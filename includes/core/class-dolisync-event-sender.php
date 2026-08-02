<?php
/**
 * Envío de eventos a Dolibarr: nuevo usuario.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Event_Sender {
	/**
	 * Maneja registro de nuevo usuario WP.
	 *
	 * @param int $user_id
	 */
	public static function on_wp_user_registered( $user_id ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		Dolisync_Schema::ensure_contact_relations_table();

		if ( ! class_exists( 'Dolisync_API_Client' ) ) {
			return;
		}

		$api = new Dolisync_API_Client();
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$first_name = get_user_meta( $user_id, 'first_name', true ) ?: $user->display_name;
		$last_name = get_user_meta( $user_id, 'last_name', true ) ?: '';
		$email = $user->user_email;
		$dni = get_user_meta( $user_id, 'dolisync_document_id', true ) ?: '';

		$create_data = array(
			'firstname' => sanitize_text_field( $first_name ),
			'name'      => sanitize_text_field( $last_name ?: $first_name ),
			'email'     => sanitize_email( $email ),
			'idprof1'   => sanitize_text_field( $dni ),
			'type'      => 2,
			'client'    => 1,
			'status'    => 1,
		);

		$response = $api->post( '/thirdparties', $create_data );

		if ( ! empty( $response['success'] ) && $response['success'] ) {
			$data = $response['data'];
			if ( is_object( $data ) ) {
				$data = json_decode( wp_json_encode( $data ), true );
			}
			if ( is_numeric( $data ) ) {
				$dolibarr_id = (int) $data;
			} elseif ( is_array( $data ) ) {
				$dolibarr_id = (int) ( $data['id'] ?? $data['rowid'] ?? $data[0]['id'] ?? $data[0]['rowid'] ?? 0 );
			} else {
				$dolibarr_id = 0;
			}

			if ( $dolibarr_id ) {
				global $wpdb;
				$table = $wpdb->prefix . 'dolisync_contact_relations';
				$insert = array(
					'dolibarr_contact_id' => $dolibarr_id,
					'wp_user_id' => $user_id,
					'dni' => sanitize_text_field( $dni ),
					'email' => $email,
					'first_name' => $first_name,
					'created_at' => current_time( 'mysql' ),
					'synced_at' => current_time( 'mysql' ),
					'source' => 'wordpress',
				);
				$inserted = $wpdb->insert( $table, $insert ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( false === $inserted ) {
					throw new RuntimeException( __( 'El tercero se creó en Dolibarr, pero no se pudo guardar su mapeo local.', 'dolisync' ) );
				}

				if ( class_exists( 'Dolisync_Action_Logger' ) ) {
					Dolisync_Action_Logger::log_action( 'contacto', 'creación', 'finalizado', sprintf( __( 'Usuario WP %d creado en Dolibarr con ID %d', 'dolisync' ), $user_id, $dolibarr_id ), get_current_user_id() );
				}
			}
		} else {
			if ( class_exists( 'Dolisync_Action_Logger' ) ) {
				Dolisync_Action_Logger::log_action( 'contacto', 'creación', 'error', sprintf( __( 'Error creando usuario WP %d en Dolibarr: %s', 'dolisync' ), $user_id, esc_html( $response['message'] ?? wp_json_encode( $response ) ) ), get_current_user_id() );
			}
		}
	}
}
