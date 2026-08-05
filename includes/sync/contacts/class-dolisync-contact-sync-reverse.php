<?php
/**
 * Sincronización inversa de contactos: WooCommerce a Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class Dolisync_Contact_Sync_Reverse {
	private $api_client = null;
	private $db_manager = null;
	private $validator = null;
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
		require_once DOLISYNC_PLUGIN_DIR . 'includes/utils/class-dolisync-spanish-document-validator.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-identity-resolver.php';

		$this->api_client = new Dolisync_API_Client();
		$this->db_manager = new Dolisync_DB_Manager();
		$this->validator = new Dolisync_Spanish_Document_Validator();
	}

	/**
	 * Sincronizar contactos desde WooCommerce a Dolibarr.
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

		// Obtener clientes de WooCommerce
		$customers = $this->fetch_woocommerce_customers();
		if ( empty( $customers ) ) {
			$message = __( 'No hay clientes de WooCommerce para sincronizar', 'dolisync' );
			Dolisync_Action_Logger::log_action( 'contacto', 'sincronización_inversa', 'finalizado', $message, get_current_user_id() );
			return array(
				'success' => true,
				'message' => $message,
				'stats'   => $this->stats,
			);
		}

		// Procesar cada cliente
		foreach ( $customers as $customer ) {
			$this->process_customer( $customer );
		}

		// Registrar resumen en DEBUG
		$debug_message = sprintf(
			__( 'Resumen de sincronización inversa: %d creados, %d actualizados, %d omitidos, %d errores', 'dolisync' ),
			$this->stats['created'],
			$this->stats['updated'],
			$this->stats['skipped'],
			$this->stats['errors']
		);
		Dolisync_Action_Logger::log_action( 'contacto', 'sincronización_inversa', 'finalizado', $debug_message, get_current_user_id() );

		// Registrar resultado final en INFO
		if ( $this->stats['errors'] > 0 ) {
			$info_message = sprintf(
				__( 'Sincronización inversa completada con %d errores. %d creados, %d actualizados, %d omitidos', 'dolisync' ),
				$this->stats['errors'],
				$this->stats['created'],
				$this->stats['updated'],
				$this->stats['skipped']
			);
		} else {
			$info_message = sprintf(
				__( 'Clientes sincronizados correctamente. %d creados, %d actualizados, %d omitidos', 'dolisync' ),
				$this->stats['created'],
				$this->stats['updated'],
				$this->stats['skipped']
			);
		}
		Dolisync_Action_Logger::log_action( 'contacto', 'resumen_sincronización_inversa', ( $this->stats['errors'] > 0 ? 'error' : 'finalizado' ), $info_message, get_current_user_id() );

		return array(
			'success' => true,
			'message' => $info_message,
			'stats'   => $this->stats,
		);
	}

	/**
	 * Sincroniza un único cliente registrado de WooCommerce.
	 *
	 * @param int $user_id ID del usuario de WordPress.
	 * @return array
	 */
	public function sync_customer( $user_id ) {
		$user_id = absint( $user_id );
		$user = $user_id ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user || ! in_array( 'customer', (array) $user->roles, true ) ) {
			return array( 'success' => false, 'message' => __( 'El cliente de WooCommerce no existe.', 'dolisync' ) );
		}

		$this->stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'details' => array(), 'errors_list' => array() );
		$customer = (object) array(
			'ID'         => $user_id,
			'user_login' => (string) $user->user_login,
			'user_email' => (string) $user->user_email,
			'first_name' => (string) get_user_meta( $user_id, 'first_name', true ),
			'last_name'  => (string) get_user_meta( $user_id, 'last_name', true ),
			'dni'        => (string) get_user_meta( $user_id, 'dolisync_document_id', true ),
		);
		$this->process_customer( $customer );

		if ( $this->stats['errors'] > 0 ) {
			$error = $this->stats['errors_list'][0]['error'] ?? __( 'No se pudo sincronizar el cliente.', 'dolisync' );
			return array( 'success' => false, 'message' => $error, 'stats' => $this->stats );
		}
		if ( $this->stats['skipped'] > 0 ) {
			$already_current = ! empty( $this->stats['details'][0]['reason'] ) && 'no_changes' === $this->stats['details'][0]['reason'];
			if ( $already_current ) {
				return array( 'success' => true, 'message' => __( 'El cliente ya estaba sincronizado y no tenía cambios.', 'dolisync' ), 'stats' => $this->stats );
			}
			return array( 'success' => false, 'message' => __( 'Cliente omitido: necesita un DNI/NIE válido y un email antes de poder enviarse.', 'dolisync' ), 'stats' => $this->stats );
		}

		return array( 'success' => true, 'message' => __( 'Cliente sincronizado correctamente con Dolibarr.', 'dolisync' ), 'stats' => $this->stats );
	}

	/**
	 * Obtener clientes de WooCommerce con DNI válido.
	 */
	private function fetch_woocommerce_customers() {
		global $wpdb;

		try {
			// Validar que las funciones necesarias existan
			if ( ! function_exists( 'get_user_by' ) || ! function_exists( 'get_user_meta' ) ) {
				throw new Exception( 'Funciones de WordPress no disponibles' );
			}

			// Paso 1: Obtener todos los usuarios con rol customer
			$customer_role_key = $wpdb->prefix . 'capabilities';
			$customers_query = $wpdb->prepare(
				"SELECT DISTINCT u.ID 
				FROM {$wpdb->users} u
				INNER JOIN {$wpdb->usermeta} cap ON u.ID = cap.user_id 
					AND cap.meta_key = %s 
					AND cap.meta_value LIKE %s
				ORDER BY u.user_registered DESC
				LIMIT 999",
				$customer_role_key,
				'%"customer"%'
			);

			$customer_ids = $wpdb->get_col( $customers_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

			if ( empty( $customer_ids ) || ! is_array( $customer_ids ) ) {
				return array();
			}

			// Paso 2: Para cada customer, obtener su información y DNI
			$customers = array();
			foreach ( $customer_ids as $user_id ) {
				try {
					$user_id = (int) $user_id;
					
					if ( $user_id <= 0 ) {
						continue;
					}

					$user = get_user_by( 'id', $user_id );
					if ( ! $user || ! is_object( $user ) || empty( $user->user_email ) ) {
						continue;
					}

					$dni = get_user_meta( $user_id, 'dolisync_document_id', true );
					if ( empty( $dni ) || ! is_string( $dni ) ) {
						continue;
					}

					$first_name = get_user_meta( $user_id, 'first_name', true );
					if ( empty( $first_name ) || ! is_string( $first_name ) ) {
						$first_name = $user->user_login ?? 'Customer';
					}

				// Obtener apellido
				$last_name = get_user_meta( $user_id, 'last_name', true );
				if ( ! is_string( $last_name ) ) {
					$last_name = '';
				}

				$customers[] = (object) array(
					'ID'         => $user_id,
					'user_login' => $user->user_login ?? '',
					'user_email' => $user->user_email ?? '',
					'first_name' => $first_name,
					'last_name'  => $last_name,
						'dni'        => (string) trim( $dni ),
					);
				} catch ( Exception $e ) {
					// Continuar con el siguiente usuario si hay error
					Dolisync_Action_Logger::log_action( 'contacto', 'lectura_cliente_woo', 'error', sprintf( __( 'No se pudo preparar el cliente WooCommerce %1$d: %2$s', 'dolisync' ), $user_id, $e->getMessage() ), get_current_user_id() );
					continue;
				}
			}

			return $customers;
		} catch ( Exception $e ) {
			Dolisync_Action_Logger::log_action( 'contacto', 'lectura_clientes_woo', 'error', 'Error obteniendo clientes: ' . $e->getMessage(), get_current_user_id() );
			throw $e;
		}
	}

	/**
	 * Procesar un cliente individual.
	 */
	private function process_customer( $customer ) {
		try {
			// Validar que el objeto tenga las propiedades requeridas
			if ( ! is_object( $customer ) || ! isset( $customer->ID ) ) {
				$this->stats['skipped']++;
				return;
			}

			$wp_user_id = (int) $customer->ID;
			if ( ! $wp_user_id ) {
				$this->stats['skipped']++;
				return;
			}

			// Obtener DNI validado
			$dni = $this->extract_customer_document( $customer );
			if ( ! $dni ) {
				$this->stats['skipped']++;
				Dolisync_Action_Logger::log_action( 'contacto', 'validación_documento', 'error', sprintf( __( 'Cliente WP %d omitido: sin documento válido.', 'dolisync' ), $wp_user_id ), get_current_user_id() );
				return;
			}

			// Obtener email y nombre completo de forma segura
			$email = isset( $customer->user_email ) ? $customer->user_email : '';

			// Obtener nombre y apellidos por separado
			$first_name = isset( $customer->first_name ) ? $customer->first_name : '';
			$last_name = isset( $customer->last_name ) ? $customer->last_name : '';

			// Si no hay nombre, usar user_login o 'Customer' como fallback
			if ( ! $first_name && ! $last_name ) {
				$first_name = isset( $customer->user_login ) ? $customer->user_login : 'Customer';
			}

			// Normalizar y sanitizar valores usando normalize_value() para evitar conversiones de array a string
			$email = $this->normalize_value( $email );
			$first_name = $this->normalize_value( $first_name );
			$last_name = $this->normalize_value( $last_name );

			// Combinar nombre y apellidos para Dolibarr
			$full_name = trim( $first_name . ' ' . $last_name );
			if ( ! $full_name ) {
				$full_name = 'Customer';
			}

			if ( ! $email ) {
				$this->stats['skipped']++;
				return;
			}

			// Verificar si la relación existe
			$existing_relation = $this->get_relation_by_wp_user_id( $wp_user_id );

			if ( $existing_relation ) {
				// Actualizar si hay cambios
				$this->update_existing_contact_dolibarr( $existing_relation, $customer, $dni, $email, $full_name );
			} else {
				// Crear nuevo contacto en Dolibarr
				$this->create_new_contact_dolibarr( $wp_user_id, $dni, $email, $full_name );
			}
		} catch ( Exception $e ) {
			$this->stats['errors']++;
			$this->stats['errors_list'][] = array(
				'wp_user_id' => isset( $customer->ID ) ? (int) $customer->ID : 0,
				'error'      => $e->getMessage(),
			);
			Dolisync_Action_Logger::log_action( 'contacto', 'procesamiento_cliente', 'error', 'Error procesando cliente: ' . $e->getMessage(), get_current_user_id() );
		}
	}

	/**
	 * Extraer y validar DNI del cliente.
	 */
	private function extract_customer_document( $customer ) {
		if ( ! is_object( $customer ) || ! isset( $customer->dni ) ) {
			return null;
		}

		$dni = $customer->dni;
		
		// Convertir a string si no lo es
		if ( ! is_string( $dni ) ) {
			if ( is_array( $dni ) ) {
				// Si es array, tomar el primer elemento
				$dni = reset( $dni );
			}
			$dni = (string) $dni;
		}

		$dni = trim( $dni );
		if ( ! $dni ) {
			return null;
		}

		// Validar documento
		$result = $this->validator->validate( $dni );
		if ( ! $result['valid'] ) {
			return null;
		}

		return $result['normalized'];
	}

	/**
	 * Buscar ID de contacto en Dolibarr por email o DNI.
	 */
	private function find_dolibarr_contact_id( $email, $dni ) {
		$dni = ! is_string( $dni ) ? '' : trim( (string) $dni );
		if ( $dni ) {
			try {
				$escaped_dni = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $dni );
				$response = $this->api_client->get( '/thirdparties', array( 'sortfield' => 't.rowid', 'sortorder' => 'ASC', 'limit' => 1, 'sqlfilters' => "(t.siren:=:'{$escaped_dni}')" ) );
				$id = ! empty( $response['success'] ) ? $this->extract_first_dolibarr_id( $response['data'] ?? null ) : 0;
				if ( $id > 0 ) {
					return $id;
				}
			} catch ( Exception $e ) {
				// Se conserva la búsqueda compatible por email y el filtro legacy inferior.
			}
		}
		// Validar que email y dni sean strings
		$email = ! is_string( $email ) ? '' : trim( (string) $email );
		$dni = ! is_string( $dni ) ? '' : trim( (string) $dni );

		// Intentar primero por email
		if ( $email ) {
			try {
				// Usar endpoint específico por email: /thirdparties/email/{email}
				$endpoint = '/thirdparties/email/' . rawurlencode( $email );
				$response = $this->api_client->get( $endpoint );

				if ( $response['success'] && ! empty( $response['data'] ) ) {
					$data = $response['data'];
					if ( is_object( $data ) ) {
						$data = json_decode( wp_json_encode( $data ), true );
					}
					if ( is_array( $data ) && ! isset( $data[0] ) ) {
						$data = array( $data );
					}
					if ( is_array( $data ) && isset( $data[0]['id'] ) ) {
						return (int) $data[0]['id'];
					}
				}
			} catch ( Exception $e ) {
				// La búsqueda por DNI podrá resolver el contacto si el endpoint de email no está disponible.
			}
		}

		// Intentar por DNI si el email falló
		if ( $dni ) {
			try {
				$response = $this->api_client->get(
					'/thirdparties',
					array(
						'filter' => 'idprof1:' . $dni,
						'limit'  => 1,
					)
				);

				if ( $response['success'] && ! empty( $response['data'] ) ) {
					$data = $response['data'];
					if ( is_object( $data ) ) {
						$data = json_decode( wp_json_encode( $data ), true );
					}
					if ( is_array( $data ) && ! isset( $data[0] ) ) {
						$data = array( $data );
					}
					if ( is_array( $data ) && isset( $data[0]['id'] ) ) {
						return (int) $data[0]['id'];
					}
				}
			} catch ( Exception $e ) {
				// Se continúa con la creación controlada del tercero/contacto.
			}
		}

		return 0;
	}

	private function extract_first_dolibarr_id( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		if ( ! is_array( $data ) ) {
			return is_numeric( $data ) ? (int) $data : 0;
		}
		if ( isset( $data['data'] ) ) {
			return $this->extract_first_dolibarr_id( $data['data'] );
		}
		if ( ! empty( $data['id'] ) || ! empty( $data['rowid'] ) ) {
			return (int) ( $data['id'] ?? $data['rowid'] );
		}
		foreach ( $data as $item ) {
			$id = $this->extract_first_dolibarr_id( $item );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}

	private function dolibarr_contact_matches_identity( $dolibarr_id, $dni, $email ) {
		$contact = $this->get_dolibarr_contact_by_id( $dolibarr_id );
		if ( ! $contact ) {
			return false;
		}
		$remote_dni = strtoupper( preg_replace( '/[\s-]/', '', (string) ( $contact['idprof1'] ?? $contact['siren'] ?? '' ) ) );
		$local_dni = strtoupper( preg_replace( '/[\s-]/', '', (string) $dni ) );
		if ( '' !== $remote_dni ) {
			return hash_equals( $remote_dni, $local_dni );
		}
		return '' !== $email && 0 === strcasecmp( trim( (string) ( $contact['email'] ?? '' ) ), trim( (string) $email ) );
	}

	/**
	 * Obtener relación existente por ID de WooCommerce.
	 */
	private function get_relation_by_wp_user_id( $wp_user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_relations';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE wp_user_id = %d",
				$wp_user_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Actualizar contacto existente en Dolibarr.
	 */
	private function update_existing_contact_dolibarr( $existing_relation, $customer, $dni, $email, $first_name ) {
		global $wpdb;
		
		$dolibarr_id = (int) $existing_relation->dolibarr_contact_id;
		$wp_user_id = (int) $customer->ID;

		// Preparar datos para actualizar
		$update_data = array(
			'firstname' => $this->normalize_value( $first_name ),
			'name'      => $this->normalize_value( $first_name ),
			'email'     => $this->normalize_value( $email ),
			'idprof1'   => $this->normalize_value( $dni ),
		);

		// Incluir país si está disponible en el usuario de Woo
		$country = get_user_meta( $wp_user_id, 'billing_country', true );
		if ( empty( $country ) ) {
			$country = get_user_meta( $wp_user_id, 'shipping_country', true );
		}
		if ( empty( $country ) ) {
			$country = get_user_meta( $wp_user_id, 'country', true );
		}
		if ( ! empty( $country ) ) {
			if ( is_numeric( $country ) ) {
				$update_data['country_id'] = (int) $country;
			} elseif ( is_string( $country ) && strlen( $country ) === 2 ) {
				$update_data['country_code'] = strtoupper( $country );
			} else {
				$update_data['country'] = $this->normalize_value( $country );
			}
		}

		$current_contact = $this->get_dolibarr_contact_by_id( $dolibarr_id );
		if ( $current_contact ) {
			$country_matches = true;
			if ( isset( $update_data['country_id'] ) ) {
				$country_matches = (int) ( $current_contact['country_id'] ?? 0 ) === (int) $update_data['country_id'];
			} elseif ( isset( $update_data['country_code'] ) ) {
				$country_matches = strtoupper( trim( (string) ( $current_contact['country_code'] ?? '' ) ) ) === (string) $update_data['country_code'];
			} elseif ( isset( $update_data['country'] ) ) {
				$country_matches = $this->normalize_compare_value( $current_contact['country'] ?? '' ) === $this->normalize_compare_value( $update_data['country'] );
			}

			$matches =
				$this->normalize_compare_value( $current_contact['name'] ?? '' ) === $this->normalize_compare_value( $update_data['name'] ) &&
				$this->normalize_compare_value( $current_contact['email'] ?? '' ) === $this->normalize_compare_value( $update_data['email'] ) &&
				$this->normalize_compare_value( $current_contact['idprof1'] ?? '' ) === $this->normalize_compare_value( $update_data['idprof1'] ) &&
				$country_matches;

			if ( $matches ) {
				$this->stats['skipped']++;
				$this->stats['details'][] = array(
					'action'      => 'skipped',
					'dolibarr_id' => $dolibarr_id,
					'woo_user_id' => $wp_user_id,
					'reason'      => 'no_changes',
				);
				return;
			}
		}

		// Realizar actualización en Dolibarr
		$response = $this->api_client->put(
			'/thirdparties/' . $dolibarr_id,
			$update_data
		);

		if ( ! $response['success'] ) {
			throw new Exception( 'Error actualizando contacto en Dolibarr: ' . ( $response['message'] ?? 'desconocido' ) );
		}

		// Actualizar registro de relación
		$table = $wpdb->prefix . 'dolisync_contact_relations';
		$update_result = $wpdb->update(
			$table,
			array(
				'dni'        => $dni,
				'email'      => $email,
				'first_name' => $first_name,
				'synced_at'  => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'wp_user_id' => $wp_user_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $update_result === false ) {
			// Si falla la actualización, registrar el error SQL
			$sql_error = $wpdb->last_error;
			throw new Exception( 'Error actualizando relación en BD: ' . $sql_error );
		}

		// update_result puede ser 0 si no hay cambios, pero es válido
		$this->stats['updated']++;

		// Si la relación no tiene first_synced_at o source, intentar establecerlos
		if ( empty( $existing_relation->first_synced_at ) || empty( $existing_relation->source ) ) {
			$extra = array();
			if ( empty( $existing_relation->first_synced_at ) ) {
				$extra['first_synced_at'] = $existing_relation->created_at ?? current_time( 'mysql' );
			}
			if ( empty( $existing_relation->source ) ) {
				$extra['source'] = 'woocommerce';
			}
			if ( ! empty( $extra ) ) {
				$wpdb->update( $table, $extra, array( 'wp_user_id' => $wp_user_id ), array_fill( 0, count( $extra ), '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		$this->stats['details'][] = array(
			'action'          => 'updated',
			'dolibarr_id'     => $dolibarr_id,
			'woo_user_id'     => $wp_user_id,
			'changes'         => $update_data,
			'rows_affected'   => $update_result,
		);

		// Registrar acción interna
		$changes_text = implode( ', ', array_map(
			function( $key, $value ) {
				$field_names = array(
					'name' => 'nombre',
					'firstname' => 'nombre',
					'email' => 'correo electrónico',
					'idprof1' => 'DNI',
					'country_code' => 'país',
					'country_id' => 'país',
					'country' => 'país',
				);
				$field_name = $field_names[ $key ] ?? $key;
				return sprintf( '%s cambió a "%s"', $field_name, $value );
			},
			array_keys( $update_data ),
			$update_data
		) );

		Dolisync_Action_Logger::log_action(
			'contacto',
			'actualización',
			'finalizado',
			sprintf(
				__( 'Se ha actualizado el contacto "%s" en Dolibarr desde WooCommerce. Cambios: %s', 'dolisync' ),
				esc_html( $first_name ),
				esc_html( $changes_text )
			),
			get_current_user_id()
		);
	}

	/**
	 * Vincula WooCommerce con un tercero ya existente sin modificarlo ni duplicarlo.
	 */
	private function link_existing_dolibarr_contact( $wp_user_id, $dolibarr_id, $dni, $email, $first_name ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_relations';
		$relation = $wpdb->get_row( $wpdb->prepare( "SELECT id, wp_user_id FROM {$table} WHERE dolibarr_contact_id = %d LIMIT 1", $dolibarr_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $relation && (int) $relation->wp_user_id !== (int) $wp_user_id ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-conflicts.php';
			Dolisync_Contact_Conflicts::record( 'woo_to_dolibarr', 'existing_relation', $wp_user_id, $dolibarr_id, Dolisync_Contact_Conflicts::snapshot_wp_user( $wp_user_id ), Dolisync_Contact_Conflicts::fetch_dolibarr_snapshot( $this->api_client, $dolibarr_id ), sprintf( __( 'El tercero Dolibarr %1$d ya está vinculado con otro cliente WooCommerce (%2$d).', 'dolisync' ), $dolibarr_id, (int) $relation->wp_user_id ) );
			throw new Exception( sprintf( __( 'El tercero Dolibarr %1$d ya está vinculado con otro cliente WooCommerce (%2$d).', 'dolisync' ), $dolibarr_id, (int) $relation->wp_user_id ) );
		}

		$now = current_time( 'mysql' );
		$data = array(
			'dolibarr_contact_id' => (int) $dolibarr_id,
			'wp_user_id'          => (int) $wp_user_id,
			'dni'                 => (string) $dni,
			'email'               => (string) $email,
			'first_name'          => (string) $first_name,
			'synced_at'           => $now,
			'first_synced_at'     => $now,
			'source'              => 'woocommerce_linked_existing',
			'created_at'          => $now,
			'updated_at'          => $now,
		);
		if ( $relation ) {
			$result = $wpdb->update( $table, $data, array( 'id' => (int) $relation->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$result = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( false === $result ) {
			throw new Exception( __( 'No se pudo guardar la vinculación local con el tercero existente.', 'dolisync' ) . ' ' . $wpdb->last_error );
		}

		$this->stats['updated']++;
		$this->stats['details'][] = array( 'action' => 'linked', 'dolibarr_id' => (int) $dolibarr_id, 'woo_user_id' => (int) $wp_user_id );
		Dolisync_Action_Logger::log_action( 'contacto', 'vinculación', 'finalizado', sprintf( __( 'Cliente WooCommerce %1$d vinculado con el tercero Dolibarr %2$d ya existente; no se ha creado un tercero nuevo.', 'dolisync' ), $wp_user_id, $dolibarr_id ), get_current_user_id() );
	}

	/**
	 * Crear nuevo contacto en Dolibarr.
	 */
	private function create_new_contact_dolibarr( $wp_user_id, $dni, $email, $first_name ) {
		global $wpdb;
		$identity = Dolisync_Contact_Identity_Resolver::resolve_dolibarr_thirdparty( $this->api_client, $dni, $email );
		if ( 'conflict' === $identity['status'] ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-conflicts.php';
			$dolibarr_conflict_id = (int) ( $identity['document_match_id'] ?? $identity['email_match_id'] ?? 0 );
			Dolisync_Contact_Conflicts::record( 'woo_to_dolibarr', 'identity', $wp_user_id, $dolibarr_conflict_id, Dolisync_Contact_Conflicts::snapshot_wp_user( $wp_user_id ), Dolisync_Contact_Conflicts::fetch_dolibarr_snapshot( $this->api_client, $dolibarr_conflict_id ), (string) $identity['message'] );
			throw new Exception( (string) $identity['message'] );
		}
		$existing_dolibarr_id = 'matched' === $identity['status'] ? (int) $identity['id'] : 0;
		if ( $existing_dolibarr_id > 0 ) {
			$this->link_existing_dolibarr_contact( $wp_user_id, $existing_dolibarr_id, $dni, $email, $first_name );
			// WooCommerce es el origen en esta dirección: tras vincular, aplica sus datos.
			$relation = $this->get_relation_by_wp_user_id( $wp_user_id );
			$this->update_existing_contact_dolibarr( $relation, get_user_by( 'id', $wp_user_id ), $dni, $email, $first_name );
			return;
		}
		
		// Obtener país desde metadatos de Woo (billing_country preferido)
		$country = get_user_meta( $wp_user_id, 'billing_country', true );
		if ( empty( $country ) ) {
			$country = get_user_meta( $wp_user_id, 'shipping_country', true );
		}
		if ( empty( $country ) ) {
			$country = get_user_meta( $wp_user_id, 'country', true );
		}
		
		// Si no hay país, usar "ES" (España) por defecto
		if ( empty( $country ) ) {
			$country = 'ES';
		}

		// Preparar datos para crear
		$create_data = array(
			'firstname' => $this->normalize_value( $first_name ),
			'name'      => $this->normalize_value( $first_name ),
			'email'     => $this->normalize_value( $email ),
			'idprof1'   => $this->normalize_value( $dni ),
			'type'      => 2, // 2 = Cliente
			'client'    => 1, // Marcar como cliente explícitamente
			'status'    => 1, // Activar el cliente
			'code_client' => 'auto',
		);

		// Añadir país en la forma que sea más probable que acepte Dolibarr
		if ( is_numeric( $country ) ) {
			$create_data['country_id'] = (int) $country;
		} elseif ( is_string( $country ) && strlen( $country ) === 2 ) {
			$create_data['country_code'] = strtoupper( $country );
		} else {
			$create_data['country'] = $this->normalize_value( $country );
		}

		// Asegurar que siempre enviamos code_client para delegar la generación en Dolibarr
		if ( ! isset( $create_data['code_client'] ) || '' === trim( (string) $create_data['code_client'] ) ) {
			$create_data['code_client'] = 'auto';
		}

		// Realizar creación en Dolibarr
		$response = $this->api_client->post(
			'/thirdparties',
			$create_data
		);

		if ( ! $response['success'] ) {
			throw new Exception( 'Error creando contacto en Dolibarr: ' . ( $response['message'] ?? 'desconocido' ) );
		}

		// Extraer ID del nuevo contacto - la API devuelve el objeto completo
		$dolibarr_contact_data = $response['data'];
		if ( is_object( $dolibarr_contact_data ) ) {
			$dolibarr_contact_data = json_decode( wp_json_encode( $dolibarr_contact_data ), true );
		}

		// El ID puede venir como 'id' en la respuesta
		$dolibarr_id = 0;
		if ( is_array( $dolibarr_contact_data ) ) {
			// Buscar el ID en la respuesta
			$dolibarr_id = (int) ( $dolibarr_contact_data['id'] ?? 
			                       $dolibarr_contact_data['rowid'] ?? 
			                       $dolibarr_contact_data[0]['id'] ?? 0 );
		}
		
		// Si no viene en la respuesta, buscar por email o DNI
		if ( ! $dolibarr_id ) {
			Dolisync_Action_Logger::log_action( 'contacto', 'resolución_id_dolibarr', 'finalizado', __( 'ID no encontrado en respuesta de creación, se buscará por email/DNI.', 'dolisync' ), get_current_user_id() );
			
			$dolibarr_id = $this->find_dolibarr_contact_id( $email, $dni );
		}
		
		if ( ! $dolibarr_id ) {
			throw new Exception( 'No se pudo obtener el ID del contacto creado en Dolibarr. Respuesta: ' . wp_json_encode( $response ) );
		}

		// Preparar datos para insertar
		$table = $wpdb->prefix . 'dolisync_contact_relations';
		$relation_data = array(
			'dolibarr_contact_id' => $dolibarr_id,
			'wp_user_id'          => $wp_user_id,
			'dni'                 => $dni,
			'email'               => $email,
			'first_name'          => $first_name,
			'synced_at'           => current_time( 'mysql' ),
			'first_synced_at'     => current_time( 'mysql' ),
			'source'              => 'woocommerce',
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// Verificar si ya existe una relación por wp_user_id (por si acaso)
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE wp_user_id = %d",
				$wp_user_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Además, comprobar si el dolibarr_contact_id ya está asociado a otro WP user
		$existing_by_doli = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, wp_user_id FROM {$table} WHERE dolibarr_contact_id = %d",
				$dolibarr_id
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $existing_by_doli ) {
			// Si está asociado al mismo usuario, hacer update
			if ( (int) $existing_by_doli->wp_user_id === (int) $wp_user_id ) {
				$update_result = $wpdb->update(
					$table,
					$relation_data,
					array( 'dolibarr_contact_id' => $dolibarr_id ),
					$formats,
					array( '%d' )
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				if ( $update_result === false ) {
					$sql_error = $wpdb->last_error;
					throw new Exception( 'Error actualizando relación en BD: ' . $sql_error );
				}

				$this->stats['updated']++;
				$action = 'updated (existing relation by dolibarr_id)';

				$this->stats['details'][] = array(
					'action'      => $action,
					'dolibarr_id' => $dolibarr_id,
					'woo_user_id' => $wp_user_id,
					'data'        => $relation_data,
				);

				return;
			} else {
				// Ya existe y pertenece a otro WP user -> conflicto
				throw new Exception( sprintf( 'Dolibarr contact id %d ya está vinculado al WP user %d', $dolibarr_id, (int) $existing_by_doli->wp_user_id ) );
			}
		}

		if ( $existing ) {
			// Ya existe, hacer UPDATE en lugar de INSERT
			$update_result = $wpdb->update(
				$table,
				$relation_data,
				array( 'wp_user_id' => $wp_user_id ),
				$formats,
				array( '%d' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $update_result === false ) {
				$sql_error = $wpdb->last_error;
				throw new Exception( 'Error actualizando relación en BD: ' . $sql_error );
			}

			$this->stats['updated']++;
			$action = 'updated (existing relation)';
		} else {
			// No existe, hacer INSERT
			// Antes de insertar, comprobar si el dolibarr_contact_id ya está asociado (race condition)
			$existing_by_doli = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, wp_user_id, first_synced_at FROM {$table} WHERE dolibarr_contact_id = %d",
					$dolibarr_id
					)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $existing_by_doli ) {
				// Si pertenece al mismo usuario, actualizar
				if ( (int) $existing_by_doli->wp_user_id === (int) $wp_user_id ) {
					$relation_data['first_synced_at'] = $existing_by_doli->first_synced_at ?? $relation_data['first_synced_at'];
					$insert_result = $wpdb->update( $table, $relation_data, array( 'dolibarr_contact_id' => $dolibarr_id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				} else {
					throw new Exception( sprintf( 'Dolibarr contact id %d ya está vinculado al WP user %d', $dolibarr_id, (int) $existing_by_doli->wp_user_id ) );
				}
			} else {
				$insert_result = $wpdb->insert( $table, $relation_data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}

			if ( $insert_result === false ) {
				// Si falla la inserción, registrar el error SQL
				$sql_error = $wpdb->last_error;
				throw new Exception( 'Error insertando relación en BD: ' . $sql_error );
			}

			if ( $insert_result === 0 ) {
				// Ninguna fila fue insertada
				throw new Exception( 'No se insertó la relación en BD (0 filas afectadas)' );
			}

			$this->stats['created']++;
			$action = 'created';
		}

		$this->stats['details'][] = array(
			'action'      => $action,
			'dolibarr_id' => $dolibarr_id,
			'woo_user_id' => $wp_user_id,
			'data'        => $create_data,
		);

		// Registrar acción interna
		$action_type = ( 'created' === $action ) ? 'creación' : 'actualización';
		$action_description = ( 'created' === $action ) 
			? sprintf( __( 'Se ha creado el contacto "%s" (%s) en Dolibarr desde WooCommerce', 'dolisync' ), esc_html( $first_name ), esc_html( $email ) )
			: sprintf( __( 'Se ha actualizado el contacto "%s" en Dolibarr desde WooCommerce', 'dolisync' ), esc_html( $first_name ) );
		
		Dolisync_Action_Logger::log_action(
			'contacto',
			$action_type,
			'finalizado',
			$action_description,
			get_current_user_id()
		);
	}

	/**
	 * Obtener un contacto de Dolibarr por ID.
	 */
	private function get_dolibarr_contact_by_id( $dolibarr_id ) {
		$response = $this->api_client->get( '/thirdparties/' . (int) $dolibarr_id );

		if ( ! $response['success'] || empty( $response['data'] ) ) {
			return null;
		}

		$data = $response['data'];
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}

		if ( is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ) {
			$data = $data[0];
		}

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Normaliza valores para comparaciones de cambios reales.
	 */
	private function normalize_compare_value( $value ) {
		$value = $this->normalize_value( $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return strtolower( trim( (string) $value ) );
	}

	/**
	 * Normaliza un valor a string seguro. Si es array toma el primer elemento.
	 */
	private function normalize_value( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( is_object( $value ) ) {
			if ( method_exists( $value, '__toString' ) ) {
				$value = (string) $value;
			} else {
				$value = wp_json_encode( $value );
			}
		}
		return trim( (string) $value );
	}
}
