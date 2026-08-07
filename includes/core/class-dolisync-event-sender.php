<?php
/** Envío idempotente de nuevos clientes WooCommerce a Dolibarr. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Event_Sender {
	public static function on_wp_user_registered( $user_id ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-identity-resolver.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
		Dolisync_Schema::ensure_contact_relations_table();

		$user_id = (int) $user_id;
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_relations';
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT dolibarr_contact_id FROM {$table} WHERE wp_user_id = %d LIMIT 1", $user_id ) ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$first_name = (string) ( get_user_meta( $user_id, 'first_name', true ) ?: $user->display_name );
		$last_name = (string) ( get_user_meta( $user_id, 'last_name', true ) ?: '' );
		$email = sanitize_email( (string) $user->user_email );
		$dni = Dolisync_Contact_Identity_Resolver::normalize_document( get_user_meta( $user_id, 'dolisync_document_id', true ) );
		if ( '' === $dni ) {
			return;
		}
		$api = new Dolisync_API_Client();
		$identity = Dolisync_Contact_Identity_Resolver::resolve_dolibarr_thirdparty( $api, $dni, $email );

		if ( 'conflict' === (string) ( $identity['status'] ?? '' ) ) {
			Dolisync_Action_Logger::log_action( 'contacto', 'vinculación_registro', 'error', sprintf( __( 'No se creó el tercero del usuario WooCommerce %1$d porque Dolibarr devolvió un conflicto de identidad: %2$s', 'dolisync' ), $user_id, (string) ( $identity['message'] ?? __( 'Conflicto desconocido.', 'dolisync' ) ) ), get_current_user_id() );
			return;
		}

		if ( 'matched' === (string) ( $identity['status'] ?? '' ) && (int) ( $identity['id'] ?? 0 ) > 0 ) {
			$dolibarr_id = (int) $identity['id'];
			self::persist_relation( $user_id, $dolibarr_id, $dni, $email, $first_name, $last_name, 'dolibarr_match' );
			Dolisync_Action_Logger::log_action( 'contacto', 'vinculación_registro', 'finalizado', sprintf( __( 'El usuario WooCommerce %1$d se vinculó al tercero Dolibarr existente %2$d.', 'dolisync' ), $user_id, $dolibarr_id ), get_current_user_id() );
			return;
		}

		$response = $api->post(
			'/thirdparties',
			array(
				'firstname' => sanitize_text_field( $first_name ), 'name' => sanitize_text_field( $last_name ?: $first_name ),
				'email' => $email, 'idprof1' => sanitize_text_field( $dni ), 'type' => 2, 'client' => 1, 'status' => 1,
			)
		);
		if ( empty( $response['success'] ) ) {
			Dolisync_Action_Logger::log_action( 'contacto', 'creación', 'error', sprintf( __( 'Error creando el usuario WooCommerce %1$d en Dolibarr: %2$s', 'dolisync' ), $user_id, (string) ( $response['message'] ?? wp_json_encode( $response ) ) ), get_current_user_id() );
			return;
		}

		$dolibarr_id = self::extract_id( $response['data'] ?? null );
		if ( $dolibarr_id <= 0 ) {
			throw new RuntimeException( __( 'Dolibarr creó el tercero, pero no devolvió su identificador.', 'dolisync' ) );
		}
		self::persist_relation( $user_id, $dolibarr_id, $dni, $email, $first_name, $last_name, 'wordpress' );
		Dolisync_Action_Logger::log_action( 'contacto', 'creación', 'finalizado', sprintf( __( 'Usuario WooCommerce %1$d creado en Dolibarr con ID %2$d.', 'dolisync' ), $user_id, $dolibarr_id ), get_current_user_id() );
	}

	private static function persist_relation( $user_id, $dolibarr_id, $dni, $email, $first_name, $last_name, $source ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_relations';
		$mapped_user_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wp_user_id FROM {$table} WHERE dolibarr_contact_id = %d LIMIT 1", $dolibarr_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $mapped_user_id > 0 && $mapped_user_id !== $user_id ) {
			throw new RuntimeException( __( 'El tercero Dolibarr ya está vinculado a otro usuario WooCommerce.', 'dolisync' ) );
		}
		$now = current_time( 'mysql' );
		$data = array(
			'dolibarr_contact_id' => $dolibarr_id, 'wp_user_id' => $user_id, 'dni' => sanitize_text_field( $dni ),
			'email' => $email, 'first_name' => sanitize_text_field( $first_name ), 'last_name' => sanitize_text_field( $last_name ),
			'synced_at' => $now, 'updated_at' => $now, 'source' => sanitize_key( $source ),
		);
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_user_id = %d LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$saved = $existing_id > 0
			? $wpdb->update( $table, $data, array( 'id' => $existing_id ) )
			: $wpdb->insert( $table, array_merge( $data, array( 'created_at' => $now, 'first_synced_at' => $now ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $saved ) {
			throw new RuntimeException( __( 'No se pudo guardar la relación entre WooCommerce y Dolibarr.', 'dolisync' ) );
		}
	}

	private static function extract_id( $data ) {
		if ( is_numeric( $data ) ) {
			return (int) $data;
		}
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		if ( ! is_array( $data ) ) {
			return 0;
		}
		if ( ! empty( $data['id'] ) || ! empty( $data['rowid'] ) ) {
			return (int) ( $data['id'] ?? $data['rowid'] );
		}
		foreach ( $data as $item ) {
			$id = self::extract_id( $item );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}
}
