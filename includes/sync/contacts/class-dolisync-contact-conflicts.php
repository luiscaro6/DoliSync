<?php
/** Persistencia y resolución manual de conflictos de identidad. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Contact_Conflicts {
	public static function record( $direction, $type, $wp_user_id, $dolibarr_id, array $wp_data, array $dolibarr_data, $message ) {
		global $wpdb;
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		Dolisync_Schema::ensure_contact_conflicts_table();
		$key = hash( 'sha256', sanitize_key( $type ) . '|' . absint( $wp_user_id ) . '|' . absint( $dolibarr_id ) );
		$now = current_time( 'mysql' );
		$table = $wpdb->prefix . 'dolisync_contact_conflicts';
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (conflict_key,direction,conflict_type,wp_user_id,dolibarr_contact_id,wp_data,dolibarr_data,message,status,created_at,updated_at)
			 VALUES (%s,%s,%s,%d,%d,%s,%s,%s,'open',%s,%s)
			 ON DUPLICATE KEY UPDATE direction=VALUES(direction),wp_data=VALUES(wp_data),dolibarr_data=VALUES(dolibarr_data),message=VALUES(message),status='open',resolution=NULL,resolved_at=NULL,resolved_by=NULL,updated_at=VALUES(updated_at)",
			$key, sanitize_key( $direction ), sanitize_key( $type ), absint( $wp_user_id ), absint( $dolibarr_id ), wp_json_encode( $wp_data ), wp_json_encode( $dolibarr_data ), (string) $message, $now, $now
		);
		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_open() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_conflicts';
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'open' ORDER BY updated_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as &$row ) {
			$row['wp_data'] = json_decode( (string) $row['wp_data'], true ) ?: array();
			$row['dolibarr_data'] = json_decode( (string) $row['dolibarr_data'], true ) ?: array();
		}
		return $rows;
	}

	public static function snapshot_wp_user( $user_id ) {
		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user ) {
			return array();
		}
		return array(
			'id' => (int) $user->ID,
			'name' => trim( (string) get_user_meta( $user->ID, 'first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'last_name', true ) ),
			'email' => (string) $user->user_email,
			'document_id' => (string) get_user_meta( $user->ID, 'dolisync_document_id', true ),
			'phone' => (string) get_user_meta( $user->ID, 'billing_phone', true ),
			'address' => (string) get_user_meta( $user->ID, 'billing_address_1', true ),
			'zip' => (string) get_user_meta( $user->ID, 'billing_postcode', true ),
			'town' => (string) get_user_meta( $user->ID, 'billing_city', true ),
			'country_code' => (string) get_user_meta( $user->ID, 'billing_country', true ),
		);
	}

	public static function fetch_dolibarr_snapshot( Dolisync_API_Client $api, $dolibarr_id ) {
		$response = $api->get( '/thirdparties/' . absint( $dolibarr_id ) );
		if ( empty( $response['success'] ) ) {
			return array();
		}
		$data = $response['data'] ?? array();
		$data = is_object( $data ) ? json_decode( wp_json_encode( $data ), true ) : (array) $data;
		return array(
			'id' => absint( $data['id'] ?? $data['rowid'] ?? $dolibarr_id ),
			'name' => (string) ( $data['name'] ?? '' ),
			'email' => (string) ( $data['email'] ?? '' ),
			'document_id' => (string) ( $data['idprof1'] ?? $data['siren'] ?? '' ),
			'phone' => (string) ( $data['phone'] ?? '' ),
			'address' => (string) ( $data['address'] ?? '' ),
			'zip' => (string) ( $data['zip'] ?? '' ),
			'town' => (string) ( $data['town'] ?? '' ),
			'country_code' => (string) ( $data['country_code'] ?? '' ),
		);
	}

	public static function resolve( $conflict_id, $winner ) {
		global $wpdb;
		if ( ! in_array( $winner, array( 'dolibarr', 'woocommerce' ), true ) ) {
			throw new InvalidArgumentException( __( 'Origen a conservar no válido.', 'dolisync' ) );
		}
		$table = $wpdb->prefix . 'dolisync_contact_conflicts';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND status='open'", absint( $conflict_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			throw new RuntimeException( __( 'El conflicto ya no existe o ya fue resuelto.', 'dolisync' ) );
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		$api = new Dolisync_API_Client();
		$wp_user_id = absint( $row['wp_user_id'] );
		$dolibarr_id = absint( $row['dolibarr_contact_id'] );
		$wp_data = self::snapshot_wp_user( $wp_user_id );
		$dolibarr_data = self::fetch_dolibarr_snapshot( $api, $dolibarr_id );
		if ( ! $wp_data || ! $dolibarr_data ) {
			throw new RuntimeException( __( 'No se pudieron cargar los dos registros actuales.', 'dolisync' ) );
		}

		if ( 'dolibarr' === $winner ) {
			$parts = preg_split( '/\s+/u', trim( $dolibarr_data['name'] ), 2 );
			$result = wp_update_user( array( 'ID' => $wp_user_id, 'user_email' => sanitize_email( $dolibarr_data['email'] ), 'display_name' => $dolibarr_data['name'] ) );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
			$map = array( 'dolisync_document_id' => 'document_id', 'first_name' => null, 'last_name' => null, 'billing_email' => 'email', 'billing_phone' => 'phone', 'billing_address_1' => 'address', 'billing_postcode' => 'zip', 'billing_city' => 'town', 'billing_country' => 'country_code' );
			foreach ( $map as $meta_key => $source_key ) {
				$value = null === $source_key ? ( 'first_name' === $meta_key ? ( $parts[0] ?? '' ) : ( $parts[1] ?? '' ) ) : $dolibarr_data[ $source_key ];
				update_user_meta( $wp_user_id, $meta_key, sanitize_text_field( (string) $value ) );
			}
			$final = $dolibarr_data;
		} else {
			$payload = array( 'name' => $wp_data['name'], 'email' => $wp_data['email'], 'idprof1' => $wp_data['document_id'], 'phone' => $wp_data['phone'], 'address' => $wp_data['address'], 'zip' => $wp_data['zip'], 'town' => $wp_data['town'], 'country_code' => $wp_data['country_code'], 'client' => 1, 'status' => 1, 'caller' => 'dolisync' );
			$response = $api->put( '/thirdparties/' . $dolibarr_id, $payload );
			if ( empty( $response['success'] ) ) {
				throw new RuntimeException( (string) ( $response['message'] ?? __( 'Dolibarr rechazó la actualización.', 'dolisync' ) ) );
			}
			$final = $wp_data;
		}

		$relations = $wpdb->prefix . 'dolisync_contact_relations';
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$relations} WHERE wp_user_id=%d OR dolibarr_contact_id=%d", $wp_user_id, $dolibarr_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$now = current_time( 'mysql' );
			$inserted = $wpdb->insert( $relations, array( 'dolibarr_contact_id' => $dolibarr_id, 'wp_user_id' => $wp_user_id, 'dni' => (string) $final['document_id'], 'email' => (string) $final['email'], 'first_name' => (string) $final['name'], 'last_name' => '', 'synced_at' => $now, 'first_synced_at' => $now, 'source' => 'manual_conflict_' . $winner, 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $inserted ) {
				throw new RuntimeException( $wpdb->last_error );
			}
			$wpdb->update( $table, array( 'status' => 'resolved', 'resolution' => $winner, 'resolved_at' => $now, 'resolved_by' => get_current_user_id(), 'updated_at' => $now ), array( 'id' => absint( $conflict_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
		return true;
	}
}
