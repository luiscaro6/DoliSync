<?php
/** Simulación de sincronización sin escrituras. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Dry_Run {
	public static function preview( $resource, $direction ) {
		global $wpdb;
		$resource = sanitize_key( $resource );
		$direction = sanitize_key( $direction );
		if ( ! in_array( $resource, array( 'products', 'contacts', 'stock' ), true ) ) {
			throw new InvalidArgumentException( __( 'Tipo de simulación no admitido.', 'dolisync' ) );
		}

		$summary = array( 'create' => 0, 'update' => 0, 'skip' => 0, 'conflicts' => 0, 'warnings' => 0, 'total' => 0 );
		$warnings = array();
		if ( 'products' === $resource || 'stock' === $resource ) {
			$relation_table = $wpdb->prefix . 'dolisync_product_relations';
			$conflict_table = $wpdb->prefix . 'dolisync_product_conflicts';
			$related = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$relation_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$conflicts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conflict_table} WHERE status = 'open'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ignored = self::ignored_count( 'product' );
			if ( 'woocommerce_to_dolibarr' === $direction ) {
				$counts = wp_count_posts( 'product' );
				$total = (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 ) + (int) ( $counts->private ?? 0 );
				$summary['create'] = max( 0, $total - $related - $ignored );
			} else {
				$total = $related;
				if ( 'products' === $resource ) {
					$warnings[] = __( 'Las altas remotas exactas se confirmarán al recorrer las páginas de Dolibarr.', 'dolisync' );
					$summary['warnings']++;
				}
			}
			$summary['update'] = max( 0, $related - $ignored );
			$summary['skip'] = $ignored;
			$summary['conflicts'] = $conflicts;
			$summary['total'] = $total;
		} else {
			$relation_table = $wpdb->prefix . 'dolisync_contact_relations';
			$conflict_table = $wpdb->prefix . 'dolisync_contact_conflicts';
			$related = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$relation_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$total = (int) count_users()['total_users'];
			$ignored = self::ignored_count( 'customer' );
			$summary['create'] = max( 0, $total - $related - $ignored );
			$summary['update'] = max( 0, $related - $ignored );
			$summary['skip'] = $ignored;
			$summary['conflicts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conflict_table} WHERE status = 'open'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$summary['total'] = $total;
			if ( 'dolibarr_to_woocommerce' === $direction ) {
				$warnings[] = __( 'Los contactos nuevos de Dolibarr se contabilizarán exactamente durante la lectura remota.', 'dolisync' );
				$summary['warnings']++;
			}
		}

		return array( 'resource' => $resource, 'direction' => $direction, 'summary' => $summary, 'warnings' => $warnings, 'read_only' => true );
	}

	private static function ignored_count( $type ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_ignored_items';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE resource_type = %s", $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
