<?php
/** Persistencia y resolución manual de conflictos de identidad de productos. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dolisync_Product_Conflicts {
	public static function record( $direction, array $resolution, array $wc_data = array(), array $dolibarr_data = array() ) {
		global $wpdb;
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		Dolisync_Schema::ensure_product_conflicts_table();
		$wc_id = absint( $resolution['wc_product_id'] ?? $wc_data['id'] ?? 0 );
		$dolibarr_id = absint( $resolution['dolibarr_product_id'] ?? $dolibarr_data['id'] ?? 0 );
		$type = sanitize_key( $resolution['conflict_type'] ?? 'identity' );
		$key = hash( 'sha256', $type . '|' . $wc_id . '|' . $dolibarr_id . '|' . self::identity_hint( $wc_data, $dolibarr_data ) );
		$now = current_time( 'mysql' );
		$table = $wpdb->prefix . 'dolisync_product_conflicts';
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (conflict_key,direction,conflict_type,wc_product_id,dolibarr_product_id,wc_data,dolibarr_data,message,status,created_at,updated_at)
			 VALUES (%s,%s,%s,%d,%d,%s,%s,%s,'open',%s,%s)
			 ON DUPLICATE KEY UPDATE direction=VALUES(direction),wc_data=VALUES(wc_data),dolibarr_data=VALUES(dolibarr_data),message=VALUES(message),status='open',resolution=NULL,resolved_at=NULL,resolved_by=NULL,updated_at=VALUES(updated_at)",
			$key, sanitize_key( $direction ), $type, $wc_id, $dolibarr_id, wp_json_encode( $wc_data ), wp_json_encode( $dolibarr_data ), (string) ( $resolution['message'] ?? '' ), $now, $now
		);
		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_open() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_conflicts';
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE status='open' ORDER BY updated_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as &$row ) {
			$row['wc_data'] = json_decode( (string) $row['wc_data'], true ) ?: array();
			$row['dolibarr_data'] = json_decode( (string) $row['dolibarr_data'], true ) ?: array();
		}
		return $rows;
	}

	public static function snapshot_woocommerce( $id ) {
		$product = $id ? wc_get_product( absint( $id ) ) : false;
		if ( ! $product ) { return array(); }
		return array( 'id' => (int) $product->get_id(), 'name' => (string) $product->get_name(), 'sku' => (string) $product->get_sku(), 'price' => (string) $product->get_price(), 'stock' => $product->get_stock_quantity(), 'type' => (string) $product->get_type(), 'status' => (string) $product->get_status() );
	}

	public static function snapshot_dolibarr( Dolisync_API_Client $api, $id, array $fallback = array() ) {
		$response = $id ? $api->get( '/products/' . absint( $id ), array( 'includestockdata' => 1 ) ) : array();
		$data = ! empty( $response['success'] ) ? $response['data'] : $fallback;
		$data = is_object( $data ) ? json_decode( wp_json_encode( $data ), true ) : (array) $data;
		if ( ! $data ) { return array(); }
		return array( 'id' => absint( $data['id'] ?? $data['rowid'] ?? $id ), 'name' => (string) ( $data['label'] ?? $data['name'] ?? '' ), 'sku' => (string) ( $data['ref'] ?? $data['sku'] ?? '' ), 'price' => (string) ( $data['price'] ?? $data['price_ht'] ?? '' ), 'stock' => $data['stock_reel'] ?? $data['stock'] ?? null, 'type' => ! empty( $data['variants'] ) ? 'variable' : 'simple', 'status' => (string) ( $data['status'] ?? '' ) );
	}

	public static function resolve( $conflict_id, $winner ) {
		global $wpdb;
		if ( ! in_array( $winner, array( 'dolibarr', 'woocommerce' ), true ) ) { throw new InvalidArgumentException( __( 'Origen a conservar no válido.', 'dolisync' ) ); }
		$table = $wpdb->prefix . 'dolisync_product_conflicts';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND status='open'", absint( $conflict_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) { throw new RuntimeException( __( 'El conflicto ya no existe o ya fue resuelto.', 'dolisync' ) ); }
		$wc_id = absint( $row['wc_product_id'] );
		$dolibarr_id = absint( $row['dolibarr_product_id'] );
		if ( 'woocommerce' === $winner && ( $wc_id <= 0 || ! wc_get_product( $wc_id ) ) ) { throw new RuntimeException( __( 'No existe un producto WooCommerce que conservar.', 'dolisync' ) ); }
		if ( 'dolibarr' === $winner && $dolibarr_id <= 0 ) { throw new RuntimeException( __( 'No existe un producto Dolibarr que conservar.', 'dolisync' ) ); }
		$relations = $wpdb->prefix . 'dolisync_product_relations';
		$previous_relations = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$relations} WHERE wc_product_id=%d OR dolibarr_product_id=%d", $wc_id, $dolibarr_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		$api = new Dolisync_API_Client();
		$dolibarr_check = $dolibarr_id > 0 ? $api->get( '/products/' . $dolibarr_id ) : array();
		$dolibarr_exists = ! empty( $dolibarr_check['success'] );
		$check_code = (int) ( $dolibarr_check['http_code'] ?? 0 );
		if ( $dolibarr_id > 0 && ! $dolibarr_exists && ! in_array( $check_code, array( 404, 410 ), true ) ) {
			throw new RuntimeException( (string) ( $dolibarr_check['message'] ?? __( 'No se pudo validar el producto Dolibarr antes de resolver.', 'dolisync' ) ) );
		}
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$relations} WHERE wc_product_id=%d OR dolibarr_product_id=%d", $wc_id, $dolibarr_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wc_id > 0 && wc_get_product( $wc_id ) && $dolibarr_exists ) {
			$now = current_time( 'mysql' );
			$linked = $wpdb->insert( $relations, array( 'wc_product_id' => $wc_id, 'dolibarr_product_id' => $dolibarr_id, 'sku' => '', 'name' => '', 'last_sync_status' => 'pending', 'first_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $linked ) {
				foreach ( $previous_relations as $previous ) { $wpdb->replace( $relations, $previous ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				throw new RuntimeException( __( 'No se pudo preparar la nueva relación de producto.', 'dolisync' ) );
			}
		}

		try {
			if ( 'dolibarr' === $winner ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-sync.php';
				$result = ( new Dolisync_Product_Sync() )->sync_product( $dolibarr_id );
			} else {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-sync-reverse.php';
				$result = ( new Dolisync_Product_Sync_Reverse() )->sync_product( $wc_id );
			}
			if ( empty( $result['success'] ) ) { throw new RuntimeException( (string) ( $result['message'] ?? __( 'No se pudo reconstruir la relación.', 'dolisync' ) ) ); }
		} catch ( Throwable $e ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$relations} WHERE wc_product_id=%d OR dolibarr_product_id=%d", $wc_id, $dolibarr_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $previous_relations as $previous ) { $wpdb->replace( $relations, $previous ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
		$now = current_time( 'mysql' );
		$wpdb->update( $table, array( 'status' => 'resolved', 'resolution' => $winner, 'resolved_at' => $now, 'resolved_by' => get_current_user_id(), 'updated_at' => $now ), array( 'id' => absint( $conflict_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	private static function identity_hint( array $wc, array $dolibarr ) { return strtolower( trim( (string) ( $wc['sku'] ?? $dolibarr['sku'] ?? $wc['name'] ?? $dolibarr['name'] ?? '' ) ) ); }
}
