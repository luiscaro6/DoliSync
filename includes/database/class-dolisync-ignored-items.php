<?php
/**
 * Persistencia de productos y pedidos excluidos manualmente de la sincronización.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Ignored_Items {
	public static function set( $resource_type, $wc_id, $dolibarr_id, $ignored ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_ignored_items';
		$resource_type = in_array( $resource_type, array( 'product', 'order', 'customer' ), true ) ? $resource_type : '';
		$wc_id = absint( $wc_id );
		$dolibarr_id = absint( $dolibarr_id );
		if ( '' === $resource_type || ( $wc_id <= 0 && $dolibarr_id <= 0 ) ) {
			return false;
		}
		$where = array( 'resource_type' => $resource_type, 'wc_id' => $wc_id, 'dolibarr_id' => $dolibarr_id );
		if ( ! $ignored ) {
			return false !== $wpdb->delete( $table, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$now = current_time( 'mysql' );
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (resource_type, wc_id, dolibarr_id, ignored_by, ignored_at, updated_at) VALUES (%s, %d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE ignored_by = VALUES(ignored_by), ignored_at = VALUES(ignored_at), updated_at = VALUES(updated_at)",
			$resource_type,
			$wc_id,
			$dolibarr_id,
			get_current_user_id(),
			$now,
			$now
		);
		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function set_many_orders( array $order_ids, $ignored ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_ignored_items';
		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		if ( empty( $order_ids ) ) {
			return 0;
		}
		$affected = 0;
		foreach ( array_chunk( $order_ids, 250 ) as $chunk ) {
			if ( ! $ignored ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				$sql = $wpdb->prepare( "DELETE FROM {$table} WHERE resource_type = 'order' AND dolibarr_id = 0 AND wc_id IN ({$placeholders})", ...$chunk );
				$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$affected += false === $result ? 0 : (int) $result;
				continue;
			}
			$now = current_time( 'mysql' );
			$user_id = get_current_user_id();
			$values = array();
			$args = array();
			foreach ( $chunk as $order_id ) {
				$values[] = "('order', %d, 0, %d, %s, %s)";
				array_push( $args, $order_id, $user_id, $now, $now );
			}
			$sql = $wpdb->prepare( "INSERT INTO {$table} (resource_type, wc_id, dolibarr_id, ignored_by, ignored_at, updated_at) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE ignored_by = VALUES(ignored_by), ignored_at = VALUES(ignored_at), updated_at = VALUES(updated_at)', ...$args );
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$affected += false === $result ? 0 : count( $chunk );
		}
		return $affected;
	}

	public static function get_map( $resource_type ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_ignored_items';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT wc_id, dolibarr_id, ignored_at FROM {$table} WHERE resource_type = %s", $resource_type ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ self::key( $row['wc_id'], $row['dolibarr_id'] ) ] = (string) $row['ignored_at'];
		}
		return $map;
	}

	public static function is_ignored( $resource_type, $wc_id = 0, $dolibarr_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_ignored_items';
		$wc_id = absint( $wc_id );
		$dolibarr_id = absint( $dolibarr_id );
		if ( $wc_id > 0 && $dolibarr_id > 0 ) {
			$sql = $wpdb->prepare( "SELECT id FROM {$table} WHERE resource_type = %s AND (wc_id = %d OR dolibarr_id = %d) LIMIT 1", $resource_type, $wc_id, $dolibarr_id );
		} elseif ( $wc_id > 0 ) {
			$sql = $wpdb->prepare( "SELECT id FROM {$table} WHERE resource_type = %s AND wc_id = %d LIMIT 1", $resource_type, $wc_id );
		} elseif ( $dolibarr_id > 0 ) {
			$sql = $wpdb->prepare( "SELECT id FROM {$table} WHERE resource_type = %s AND dolibarr_id = %d LIMIT 1", $resource_type, $dolibarr_id );
		} else {
			return false;
		}
		return (bool) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function key( $wc_id, $dolibarr_id = 0 ) {
		return absint( $wc_id ) . ':' . absint( $dolibarr_id );
	}
}
