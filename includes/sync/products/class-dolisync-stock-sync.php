<?php
/**
 * Sincronización ligera de stock Dolibarr → WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Stock_Sync {
	private const BATCH_SIZE = 25;

	private $api_client;

	public function __construct() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
		$this->api_client = new Dolisync_API_Client();
	}

	public function sync_batch( $offset = 0, $context = 'automatic' ) {
		$offset = max( 0, (int) $offset );
		$action = 'manual' === $context ? 'sincronización_manual' : 'sincronización_automática';
		$user_id = 'manual' === $context ? get_current_user_id() : 0;
		$relations = $this->get_relations_batch( $offset, self::BATCH_SIZE );
		$stats = array( 'checked' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0 );
		$abort_batch = false;

		foreach ( $relations as $index => $relation ) {
			$stats['checked']++;
			try {
				$result = $this->sync_relation( $relation );
				$stats[ $result ]++;
			} catch ( Throwable $e ) {
				$stats['errors']++;
				Dolisync_Action_Logger::log_action(
					'stock',
					$action,
					'error',
					sprintf( 'Error sincronizando stock Dolibarr %d → WooCommerce %d: %s', (int) $relation['dolibarr_product_id'], (int) $relation['wc_product_id'], $e->getMessage() ),
					$user_id
				);
				if ( 429 === (int) $e->getCode() || ( (int) $e->getCode() >= 500 && (int) $e->getCode() <= 599 ) ) {
					$stats['skipped'] += max( 0, count( $relations ) - $index - 1 );
					$abort_batch = true;
					break;
				}
			}
		}

		Dolisync_Action_Logger::log_action(
			'stock',
			$action,
			$stats['errors'] > 0 ? 'error' : 'finalizado',
			sprintf( 'Lote de stock: %d comprobados, %d actualizados, %d sin cambios, %d omitidos y %d errores.', $stats['checked'], $stats['updated'], $stats['unchanged'], $stats['skipped'], $stats['errors'] ),
			$user_id
		);

		return array(
			'stats' => $stats,
			'has_more' => ! $abort_batch && count( $relations ) === self::BATCH_SIZE,
			'next_offset' => $offset + count( $relations ),
		);
	}

	private function get_relations_batch( $offset, $limit ) {
		global $wpdb;
		$product_table = $wpdb->prefix . 'dolisync_product_relations';
		$variation_table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$sql = "
			SELECT 'product' AS relation_type, id AS relation_id, dolibarr_product_id, wc_product_id, stock_qty
			FROM {$product_table}
			WHERE dolibarr_product_id > 0 AND wc_product_id > 0
			UNION ALL
			SELECT 'variation' AS relation_type, id AS relation_id, dolibarr_variation_id AS dolibarr_product_id, wc_variation_id AS wc_product_id, stock_qty
			FROM {$variation_table}
			WHERE dolibarr_variation_id > 0 AND wc_variation_id > 0
			ORDER BY relation_type ASC, relation_id ASC
			LIMIT %d OFFSET %d";

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function sync_relation( $relation ) {
		$dolibarr_product_id = (int) ( $relation['dolibarr_product_id'] ?? 0 );
		$wc_product_id = (int) ( $relation['wc_product_id'] ?? 0 );
		$wc_product = wc_get_product( $wc_product_id );
		if ( $dolibarr_product_id <= 0 || ! $wc_product ) {
			return 'skipped';
		}
		if ( 'product' === $relation['relation_type'] && $wc_product->is_type( 'variable' ) ) {
			return 'skipped';
		}

		$response = $this->api_client->get( '/products/' . $dolibarr_product_id, array( 'includestockdata' => 1 ) );
		if ( empty( $response['success'] ) ) {
			throw new Exception( (string) ( $response['message'] ?? 'No se pudo consultar el stock en Dolibarr.' ), (int) ( $response['http_code'] ?? 0 ) );
		}
		$data = $response['data'] ?? array();
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		$stock = is_array( $data ) ? ( $data['stock_reel'] ?? null ) : null;
		if ( ! is_numeric( $stock ) ) {
			throw new Exception( 'Dolibarr no devolvió un stock_reel numérico.' );
		}
		$stock = (float) $stock;
		$current_stock = $wc_product->get_stock_quantity();
		$target_status = $stock > 0 ? 'instock' : ( $wc_product->backorders_allowed() ? 'onbackorder' : 'outofstock' );
		$unchanged = $wc_product->get_manage_stock()
			&& is_numeric( $current_stock )
			&& abs( (float) $current_stock - $stock ) < 0.000001
			&& $target_status === $wc_product->get_stock_status();

		if ( ! $unchanged ) {
			$wc_product->set_manage_stock( true );
			$wc_product->set_stock_quantity( $stock );
			$wc_product->set_stock_status( $target_status );
			$wc_product->save();
		}

		$stored_stock = $relation['stock_qty'] ?? null;
		if ( ! is_numeric( $stored_stock ) || abs( (float) $stored_stock - $stock ) >= 0.000001 ) {
			$this->update_relation_stock( $relation, $stock );
		}
		return $unchanged ? 'unchanged' : 'updated';
	}

	private function update_relation_stock( $relation, $stock ) {
		global $wpdb;
		$table = 'variation' === $relation['relation_type']
			? $wpdb->prefix . 'dolisync_product_variation_relations'
			: $wpdb->prefix . 'dolisync_product_relations';
		$result = $wpdb->update( $table, array( 'stock_qty' => $stock, 'synced_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $relation['relation_id'] ), array( '%f', '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			throw new RuntimeException( 'No se pudo actualizar el stock del mapeo local.' );
		}
	}
}
