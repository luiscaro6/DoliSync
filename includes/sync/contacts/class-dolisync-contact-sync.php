<?php
/**
 * Sincronización de contactos entre Dolibarr y WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Contact_Sync {
	private $api_client = null;
	private $db_manager = null;
	private $stats = array(
		'created'  => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'errors'   => 0,
		'details'  => array(),
		'errors_list' => array(),
	);

	public function __construct() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-db-manager.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-identity-resolver.php';

		$this->api_client = new Dolisync_API_Client();
		$this->db_manager = new Dolisync_DB_Manager();
	}

	/**
	 * Filtra un array de campos para insert/update contra las columnas reales de la tabla.
	 * Devuelve un array con [datos_filtrados, formatos]
	 */
	private function filter_relation_fields( $table, $data ) {
		global $wpdb;
		$columns = (array) $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$filtered = array();
		$formats = array();
		foreach ( $data as $k => $v ) {
			if ( in_array( $k, $columns, true ) ) {
				$filtered[ $k ] = $v;
				if ( is_int( $v ) ) {
					$formats[] = '%d';
				} else {
					$formats[] = '%s';
				}
			}
		}
		return array( $filtered, $formats );
	}

	/**
	 * Sincronizar contactos desde Dolibarr a WooCommerce.
	 */
	public function sync() {
		$this->stats = array(
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'details'  => array(),
			'errors_list' => array(),
		);

		// Obtener contactos de Dolibarr
		$contacts = $this->fetch_dolibarr_contacts();
		if ( ! $contacts ) {
			$message = __( 'Error al obtener contactos de Dolibarr', 'dolisync' );
			Dolisync_Action_Logger::log_action( 'contacto', 'sincronización', 'error', $message, get_current_user_id() );
			return array(
				'success' => false,
				'message' => $message,
			);
		}


		// Procesar cada contacto
		foreach ( $contacts as $contact ) {
			$this->process_contact( $contact );
		}

		// Política de seguridad: una respuesta remota nunca elimina usuarios ni
		// relaciones locales. Los listados de Dolibarr pueden ser parciales o paginados.

		// Registrar resumen en DEBUG
		$debug_message = sprintf(
			__( 'Resumen de sincronización: %d creados, %d actualizados, %d omitidos, %d errores', 'dolisync' ),
			$this->stats['created'],
			$this->stats['updated'],
			$this->stats['skipped'],
			$this->stats['errors']
		);
		Dolisync_Action_Logger::log_action( 'contacto', 'sincronización', 'finalizado', $debug_message, get_current_user_id() );

		// Registrar resultado final en INFO
		if ( $this->stats['errors'] > 0 ) {
			$info_message = sprintf(
				__( 'Sincronización completada con %d errores. %d creados, %d actualizados, %d omitidos', 'dolisync' ),
				$this->stats['errors'],
				$this->stats['created'],
				$this->stats['updated'],
				$this->stats['skipped']
			);
		} else {
			$info_message = sprintf(
				__( 'Contactos sincronizados correctamente. %d creados, %d actualizados, %d omitidos', 'dolisync' ),
				$this->stats['created'],
				$this->stats['updated'],
				$this->stats['skipped']
			);
		}
		Dolisync_Action_Logger::log_action( 'contacto', 'resumen_sincronización', ( $this->stats['errors'] > 0 ? 'error' : 'finalizado' ), $info_message, get_current_user_id() );

		return array(
			'success' => true,
			'message' => $info_message,
			'stats'   => $this->stats,
		);
	}

	/**
	 * Obtener contactos de Dolibarr.
	 */
	private function fetch_dolibarr_contacts() {
		$response = $this->api_client->get(
			'/thirdparties',
			array(
				'sortfield' => 'rowid',
				'sortorder' => 'ASC',
				'limit'     => 999,
			)
		);

		if ( ! $response['success'] ) {
			return null;
		}

		// Dolibarr devuelve un objeto, no un array. Convertir a array si es necesario.
		$data = $response['data'];
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}

		// Si es un array con una sola entrada, envolverlo
		if ( is_array( $data ) && ! isset( $data[0] ) ) {
			$data = array( $data );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Procesar un contacto individual.
	 */
	private function process_contact( $dolibarr_contact ) {
		try {
			$dolibarr_id = (int) ( $dolibarr_contact['id'] ?? 0 );
			if ( ! $dolibarr_id ) {
				$this->stats['skipped']++;
				return;
			}

			$dni = $this->extract_dni( $dolibarr_contact );
			$email = (string) ( $dolibarr_contact['email'] ?? '' );
			$full_name = $this->combine_name( $dolibarr_contact );

			// Verificar si la relación existe
			$existing_relation = $this->get_relation_by_dolibarr_id( $dolibarr_id );

			if ( $existing_relation ) {
				// Actualizar si hay cambios
				$this->update_existing_contact( $existing_relation, $dolibarr_contact, $dni, $email, $full_name );
			} else {
				// Crear nuevo contacto
				$this->create_new_contact( $dolibarr_id, $dni, $email, $full_name, $dolibarr_contact );
			}
		} catch ( Exception $e ) {
			$this->stats['errors']++;
			$this->stats['errors_list'][] = array(
				'dolibarr_id' => (int) ( $dolibarr_contact['id'] ?? 0 ),
				'error'       => $e->getMessage(),
			);

			// Registrar error en acciones
			Dolisync_Action_Logger::log_action(
				'contacto',
				'procesamiento',
				'error',
				sprintf(
					__( 'Error al procesar contacto de Dolibarr (ID: %d): %s', 'dolisync' ),
					(int) ( $dolibarr_contact['id'] ?? 0 ),
					esc_html( $e->getMessage() )
				),
				get_current_user_id()
			);
		}
	}

	/**
	 * Extraer DNI del contacto de Dolibarr desde idprof1.
	 */
	private function extract_dni( $contact ) {
		return strtoupper( trim( (string) ( $contact['idprof1'] ?? '' ) ) );
	}

	/**
	 * Combinar nombre y apellido en un solo campo.
	 */
	private function combine_name( $contact ) {
		$firstname = trim( (string) ( $contact['firstname'] ?? '' ) );
		$lastname = trim( (string) ( $contact['name'] ?? '' ) );
		$combined = trim( $firstname . ' ' . $lastname );
		return $combined ?: 'Contact';
	}

	/** Sincroniza un único tercero de Dolibarr hacia WooCommerce. */
	public function sync_contact( $dolibarr_id ) {
		$dolibarr_id = absint( $dolibarr_id );
		if ( ! $dolibarr_id ) { return array( 'success' => false, 'message' => __( 'Tercero de Dolibarr no válido.', 'dolisync' ) ); }
		$response = $this->api_client->get( '/thirdparties/' . $dolibarr_id );
		if ( empty( $response['success'] ) ) { return array( 'success' => false, 'message' => (string) ( $response['message'] ?? __( 'No se pudo leer el tercero.', 'dolisync' ) ) ); }
		$data = $response['data'] ?? array();
		if ( is_object( $data ) ) { $data = json_decode( wp_json_encode( $data ), true ); }
		if ( isset( $data[0] ) && is_array( $data[0] ) ) { $data = $data[0]; }
		if ( ! is_array( $data ) ) { return array( 'success' => false, 'message' => __( 'Dolibarr devolvió un tercero no válido.', 'dolisync' ) ); }
		$this->stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'details' => array(), 'errors_list' => array() );
		$this->process_contact( $data );
		if ( $this->stats['errors'] > 0 ) { return array( 'success' => false, 'message' => (string) ( $this->stats['errors_list'][0]['error'] ?? __( 'No se pudo sincronizar el cliente.', 'dolisync' ) ), 'stats' => $this->stats ); }
		return array( 'success' => true, 'message' => __( 'Cliente sincronizado desde Dolibarr.', 'dolisync' ), 'stats' => $this->stats );
	}

	private function split_name( $contact ) {
		$firstname = trim( (string) ( $contact['firstname'] ?? '' ) );
		$name = trim( (string) ( $contact['name'] ?? '' ) );

		if ( $firstname && $name && 0 !== strcasecmp( $firstname, $name ) ) {
			return array( $firstname, $name );
		}

		$parts = preg_split( '/\s+/u', $name ?: $firstname, 2 );
		return array( $parts[0] ?? 'Contact', $parts[1] ?? '' );
	}

	/**
	 * Obtener relación existente por ID de Dolibarr.
	 */
	private function get_relation_by_dolibarr_id( $dolibarr_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_relations';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE dolibarr_contact_id = %d",
				$dolibarr_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Actualizar contacto existente.
	 */
	private function update_existing_contact( $existing_relation, $dolibarr_contact, $dni, $email, $full_name ) {
		$wp_user_id = (int) $existing_relation->wp_user_id;
		$changes = array();
		list( $first_name, $last_name ) = $this->split_name( $dolibarr_contact );

		// Verificar cambios de email
		$wp_user = get_userdata( $wp_user_id );
		$current_dni = Dolisync_Contact_Identity_Resolver::normalize_document( get_user_meta( $wp_user_id, 'dolisync_document_id', true ) );
		if ( $current_dni !== Dolisync_Contact_Identity_Resolver::normalize_document( $dni ) || (string) $existing_relation->dni !== $dni ) {
			$changes['dni'] = $dni;
			update_user_meta( $wp_user_id, 'dolisync_document_id', $dni );
		}

		if ( $email !== (string) $existing_relation->email || ( $wp_user && $email !== (string) $wp_user->user_email ) ) {
			$changes['email'] = $email;
			$email_update = wp_update_user( array(
				'ID'         => $wp_user_id,
				'user_email' => $email,
			) );
			if ( is_wp_error( $email_update ) ) {
				throw new Exception( $email_update->get_error_message() );
			}
		}

		// Verificar cambios en nombres
		if (
			$first_name !== (string) $existing_relation->first_name ||
			$last_name !== (string) ( $existing_relation->last_name ?? '' ) ||
			$first_name !== (string) get_user_meta( $wp_user_id, 'first_name', true ) ||
			$last_name !== (string) get_user_meta( $wp_user_id, 'last_name', true )
		) {
			$changes['first_name'] = $first_name;
			$changes['last_name'] = $last_name;
			update_user_meta( $wp_user_id, 'first_name', $first_name );
			update_user_meta( $wp_user_id, 'last_name', $last_name );
			update_user_meta( $wp_user_id, 'billing_first_name', $first_name );
			update_user_meta( $wp_user_id, 'billing_last_name', $last_name );
			update_user_meta( $wp_user_id, 'shipping_first_name', $first_name );
			update_user_meta( $wp_user_id, 'shipping_last_name', $last_name );
			wp_update_user( array( 'ID' => $wp_user_id, 'display_name' => $full_name ) );
		}

		// Sincronizar país de Dolibarr a Woo si viene en la respuesta
		$country = '';
		if ( isset( $dolibarr_contact['country_code'] ) && $dolibarr_contact['country_code'] ) {
			$country = strtoupper( (string) $dolibarr_contact['country_code'] );
		} elseif ( isset( $dolibarr_contact['country'] ) && $dolibarr_contact['country'] ) {
			$country = (string) $dolibarr_contact['country'];
		} elseif ( isset( $dolibarr_contact['country_id'] ) && $dolibarr_contact['country_id'] ) {
			$country = (string) $dolibarr_contact['country_id'];
		}
		if ( $country ) {
			$current_country = (string) get_user_meta( $wp_user_id, 'billing_country', true );
			if ( $current_country !== $country ) {
				update_user_meta( $wp_user_id, 'billing_country', $country );
				$changes['billing_country'] = $country;
			}
		}

		// Actualizar registro de relación si hay cambios
		if ( ! empty( $changes ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'dolisync_contact_relations';
			$update_payload = array(
				'dni'        => $dni,
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'updated_at' => current_time( 'mysql' ),
				'synced_at'  => current_time( 'mysql' ),
			);

			// Si faltan columnas como first_synced_at o source, filter_relation_fields las eliminará automáticamente
			list( $filtered_update, $filtered_formats ) = $this->filter_relation_fields( $table, $update_payload );

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				$filtered_update,
				array( 'dolibarr_contact_id' => (int) $dolibarr_contact['id'] ),
				$filtered_formats,
				array( '%d' )
			);

			$this->stats['updated']++;
			$this->stats['details'][] = array(
				'action'          => 'updated',
				'dolibarr_id'     => (int) $dolibarr_contact['id'],
				'woo_user_id'     => $wp_user_id,
				'changes'         => $changes,
			);

			// Registrar acción interna
			$changes_text = implode( ', ', array_map(
				function( $key, $value ) {
					$field_name = array(
						'email' => 'correo electrónico',
						'dni' => 'documento fiscal',
						'first_name' => 'nombre',
						'billing_country' => 'país',
					)[ $key ] ?? $key;
					return sprintf( '%s cambió a "%s"', $field_name, $value );
				},
				array_keys( $changes ),
				$changes
			) );

			Dolisync_Action_Logger::log_action(
				'contacto',
				'actualización',
				'finalizado',
				sprintf(
					__( 'Se ha actualizado el contacto "%s". Cambios: %s', 'dolisync' ),
					esc_html( $full_name ),
					esc_html( $changes_text )
				),
				get_current_user_id()
			);
		} else {
			$this->stats['skipped']++;
		}
	}

	/**
	 * Crear nuevo contacto en WooCommerce.
	 */
	private function create_new_contact( $dolibarr_id, $dni, $email, $full_name, $dolibarr_contact = null ) {
		list( $first_name, $last_name ) = $this->split_name( (array) $dolibarr_contact );
		$identity = Dolisync_Contact_Identity_Resolver::resolve_wp_user( $dni, $email );
		if ( 'conflict' === $identity['status'] ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-conflicts.php';
			$wp_conflict_id = (int) ( $identity['document_match_id'] ?? $identity['email_match_id'] ?? 0 );
			Dolisync_Contact_Conflicts::record( 'dolibarr_to_woo', 'identity', $wp_conflict_id, $dolibarr_id, Dolisync_Contact_Conflicts::snapshot_wp_user( $wp_conflict_id ), (array) $dolibarr_contact, (string) $identity['message'] );
			throw new Exception( (string) $identity['message'] );
		}
		$existing_user = 'matched' === $identity['status'] ? get_user_by( 'id', (int) $identity['id'] ) : false;
		$username = '';

		if ( $existing_user ) {
			$wp_user_id = (int) $existing_user->ID;
			$linked_relation = $this->get_relation_by_wp_user_id( $wp_user_id );
			if ( $linked_relation && (int) $linked_relation->dolibarr_contact_id !== $dolibarr_id ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-conflicts.php';
				Dolisync_Contact_Conflicts::record( 'dolibarr_to_woo', 'existing_relation', $wp_user_id, $dolibarr_id, Dolisync_Contact_Conflicts::snapshot_wp_user( $wp_user_id ), (array) $dolibarr_contact, sprintf( __( 'El usuario WooCommerce %1$d ya está vinculado al tercero Dolibarr %2$d.', 'dolisync' ), $wp_user_id, (int) $linked_relation->dolibarr_contact_id ) );
				throw new Exception( sprintf( 'El usuario WooCommerce %d ya está vinculado al tercero Dolibarr %d.', $wp_user_id, (int) $linked_relation->dolibarr_contact_id ) );
			}
		} else {
			$username = $this->generate_unique_username( $email, $full_name, $dolibarr_id );
			$wp_user_id = wp_create_user( $username, wp_generate_password( 16, true ), $email );
			if ( is_wp_error( $wp_user_id ) ) {
				throw new Exception( $wp_user_id->get_error_message() );
			}
		}

		// Actualizar metadatos del usuario
		update_user_meta( $wp_user_id, 'first_name', $first_name );
		update_user_meta( $wp_user_id, 'last_name', $last_name );
		update_user_meta( $wp_user_id, 'billing_first_name', $first_name );
		update_user_meta( $wp_user_id, 'billing_last_name', $last_name );
		update_user_meta( $wp_user_id, 'shipping_first_name', $first_name );
		update_user_meta( $wp_user_id, 'shipping_last_name', $last_name );
		update_user_meta( $wp_user_id, 'dolisync_document_id', $dni );
		$updated_user = wp_update_user(
			array(
				'ID'           => $wp_user_id,
				'display_name' => $full_name,
				'user_email'   => sanitize_email( $email ),
			)
		);
		if ( is_wp_error( $updated_user ) ) {
			throw new Exception( $updated_user->get_error_message() );
		}

		// Intentar sincronizar país desde Dolibarr si viene en los datos (billing_country en Woo)
		$country = '';
		if ( isset( $dolibarr_contact ) && is_array( $dolibarr_contact ) ) {
			if ( isset( $dolibarr_contact['country_code'] ) && $dolibarr_contact['country_code'] ) {
				$country = strtoupper( (string) $dolibarr_contact['country_code'] );
			} elseif ( isset( $dolibarr_contact['country'] ) && $dolibarr_contact['country'] ) {
				$country = (string) $dolibarr_contact['country'];
			} elseif ( isset( $dolibarr_contact['country_id'] ) && $dolibarr_contact['country_id'] ) {
				$country = (string) $dolibarr_contact['country_id'];
			}
		}
		if ( $country ) {
			update_user_meta( $wp_user_id, 'billing_country', $country );
		}

		// Asignar rol de cliente
		if ( ! $existing_user ) {
			$user = new WP_User( $wp_user_id );
			$user->set_role( 'customer' );
		}

		// Guardar relación en la base de datos
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_relations';

		$insert_payload = array(
			'dolibarr_contact_id' => $dolibarr_id,
			'wp_user_id'          => $wp_user_id,
			'dni'                 => $dni,
			'email'               => $email,
			'first_name'          => $first_name,
			'last_name'           => $last_name,
			'created_at'          => current_time( 'mysql' ),
			'synced_at'           => current_time( 'mysql' ),
			'first_synced_at'     => current_time( 'mysql' ),
			'source'              => 'dolibarr',
		);

		list( $filtered_insert, $insert_formats ) = $this->filter_relation_fields( $table, $insert_payload );

		$inserted = $wpdb->insert( $table, $filtered_insert, $insert_formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $inserted ) {
			throw new Exception( 'Error guardando la relación en BD: ' . $wpdb->last_error );
		}

		$this->stats[ $existing_user ? 'updated' : 'created' ]++;
		$this->stats['details'][] = array(
			'action'      => 'created',
			'dolibarr_id' => $dolibarr_id,
			'woo_user_id' => $wp_user_id,
			'username'    => $username,
			'email'       => $email,
		);

		// Registrar acción interna
		Dolisync_Action_Logger::log_action(
			'contacto',
			'creación',
			'finalizado',
			sprintf(
				__( 'Se ha creado el contacto "%s" (%s) desde Dolibarr con ID %d', 'dolisync' ),
				esc_html( $full_name ),
				esc_html( $email ),
				$dolibarr_id
			),
			get_current_user_id()
		);
	}

	private function get_relation_by_wp_user_id( $wp_user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_relations';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE wp_user_id = %d", $wp_user_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Generar nombre de usuario único.
	 */
	private function generate_unique_username( $email, $full_name = '', $dolibarr_id = 0 ) {
		$base_username = '';

		// Preferir la parte local del email si existe
		if ( ! empty( $email ) ) {
			$parts = explode( '@', $email );
			$base_username = sanitize_user( $parts[0], true );
		}

		// Si no hay base por email, intentar usar el nombre completo
		if ( empty( $base_username ) && ! empty( $full_name ) ) {
			$normalized = strtolower( trim( preg_replace( '/\s+/', '.', $full_name ) ) );
			$base_username = sanitize_user( $normalized, true );
		}

		// Si aún no hay base, usar dolibarr id o un prefijo con random pequeño
		if ( empty( $base_username ) ) {
			if ( ! empty( $dolibarr_id ) ) {
				$base_username = 'dolisync' . intval( $dolibarr_id );
			} else {
				$base_username = 'dolisync' . wp_generate_password( 6, false, false );
			}
		}

		// Asegurar que no esté vacío (fallback final)
		if ( empty( $base_username ) ) {
			$base_username = 'dolisync' . time();
		}

		$username = $base_username;
		$counter = 1;

		while ( username_exists( $username ) ) {
			$username = $base_username . $counter;
			$counter++;
		}

		return $username;
	}
}
