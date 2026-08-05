<?php
/**
 * Sincronización manual de productos desde WooCommerce a Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Product_Sync_Reverse {
	private const DEFAULT_PAGE_SIZE = 25;
	private const MAX_PAGE_SIZE = 100;
	private const PAYLOAD_VERSION = 5;

	private $api_client = null;
	private $db_manager = null;
	private $stats = array(
		'created'  => 0,
		'updated'  => 0,
		'conflicts' => 0,
		'skipped'  => 0,
		'errors'   => 0,
		'details'  => array(),
		'errors_list' => array(),
	);
	private $attribute_cache = array();
	private $attribute_value_cache = array();
	private $warehouse_id = null;
	private $image_sync = null;

	public function __construct() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-db-manager.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-image-sync.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-identity-resolver.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-conflicts.php';

		$this->api_client = new Dolisync_API_Client();
		$this->db_manager = new Dolisync_DB_Manager();
		$this->image_sync = new Dolisync_Product_Image_Sync( $this->api_client );
		Dolisync_Schema::ensure_product_variation_relation_columns();
	}

	public function sync( $page = 1, $per_page = self::DEFAULT_PAGE_SIZE ) {
		$this->reset_stats();
		$page = max( 1, (int) $page );
		$per_page = max( 1, min( self::MAX_PAGE_SIZE, (int) $per_page ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			$message = __( 'WooCommerce no está activo.', 'dolisync' );
			Dolisync_Action_Logger::log_action( 'producto', 'sincronización_manual', 'error', $message, get_current_user_id() );
			return array(
				'success' => false,
				'message' => $message,
				'stats'   => $this->stats,
			);
		}

		$product_statuses = function_exists( 'wc_get_product_statuses' )
			? array_keys( wc_get_product_statuses() )
			: array( 'publish', 'draft', 'pending', 'private' );
		$product_statuses[] = 'future';
		$product_statuses = array_values( array_diff( $product_statuses, array( 'trash', 'auto-draft' ) ) );
		$product_statuses = array_values( array_unique( $product_statuses ) );

		$products = wc_get_products(
			array(
				'limit'  => $per_page,
				'page'   => $page,
				'paginate' => true,
				'status' => $product_statuses,
				'orderby' => 'ID',
				'order' => 'ASC',
			)
		);
		$total_pages = is_object( $products ) && isset( $products->max_num_pages ) ? (int) $products->max_num_pages : 1;
		$total_products = is_object( $products ) && isset( $products->total ) ? (int) $products->total : 0;
		$product_items = is_object( $products ) && isset( $products->products ) ? $products->products : $products;

		if ( empty( $product_items ) ) {
			$message = __( 'No hay productos de WooCommerce para exportar.', 'dolisync' );
			Dolisync_Action_Logger::log_action( 'producto', 'sincronización_manual', 'finalizado', $message, get_current_user_id() );
			return array(
				'success' => true,
				'message' => $message,
				'stats'   => $this->stats,
				'pagination' => array( 'page' => $page, 'per_page' => $per_page, 'has_more' => false, 'next_page' => null, 'total' => $total_products ),
			);
		}

		foreach ( $product_items as $product ) {
			$this->process_product( $product );
		}

		$summary = sprintf(
			__( 'Resumen de exportación de productos: %d creados, %d mapeados, %d actualizados, %d omitidos, %d errores', 'dolisync' ),
			$this->stats['created'],
			$this->stats['mapped'],
			$this->stats['updated'],
			$this->stats['skipped'],
			$this->stats['errors']
		);
		Dolisync_Action_Logger::log_action( 'producto', 'sincronización_manual', ( $this->stats['errors'] > 0 ? 'error' : 'finalizado' ), $summary, get_current_user_id() );

		return array(
			'success' => true,
			'message' => $summary,
			'stats'   => $this->stats,
			'pagination' => array( 'page' => $page, 'per_page' => $per_page, 'has_more' => $page < $total_pages, 'next_page' => $page < $total_pages ? $page + 1 : null, 'total_pages' => $total_pages, 'total' => $total_products ),
		);
	}

	/**
	 * Sincroniza un único producto de WooCommerce, incluyendo sus variaciones.
	 *
	 * @param int $wc_product_id ID del producto padre en WooCommerce.
	 * @return array
	 */
	public function sync_product( $wc_product_id ) {
		$this->reset_stats();
		$wc_product_id = absint( $wc_product_id );
		$product = $wc_product_id ? wc_get_product( $wc_product_id ) : false;
		if ( ! $product || ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) ) {
			return array( 'success' => false, 'message' => __( 'Producto de WooCommerce no válido.', 'dolisync' ), 'stats' => $this->stats );
		}

		$this->process_product( $product );
		return array(
			'success' => 0 === (int) $this->stats['errors'] && 0 === (int) $this->stats['conflicts'],
			'message' => (int) $this->stats['conflicts'] > 0 ? __( 'Se detectó un conflicto de identidad. Revísalo en la pestaña Conflictos.', 'dolisync' ) : ( 0 === (int) $this->stats['errors'] ? __( 'Producto sincronizado de WooCommerce a Dolibarr.', 'dolisync' ) : __( 'No se pudo sincronizar el producto.', 'dolisync' ) ),
			'stats'   => $this->stats,
		);
	}

	private function reset_stats() {
		$this->stats = array(
			'created'  => 0,
			'mapped'   => 0,
			'updated'  => 0,
			'conflicts' => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'details'  => array(),
			'errors_list' => array(),
		);
	}

	private function process_product( $wc_product ) {
		$wc_id = is_object( $wc_product ) && method_exists( $wc_product, 'get_id' ) ? (int) $wc_product->get_id() : 0;
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		if ( $wc_id > 0 && Dolisync_Ignored_Items::is_ignored( 'product', $wc_id, 0 ) ) {
			$this->stats['skipped']++;
			return;
		}
		try {
			if ( ! is_object( $wc_product ) || ! method_exists( $wc_product, 'get_id' ) ) {
				$this->stats['skipped']++;
				return;
			}

			$wc_product_id = (int) $wc_product->get_id();
			if ( ! $wc_product_id ) {
				$this->stats['skipped']++;
				return;
			}

			$payload = $this->normalize_wc_product( $wc_product );
			if ( ! is_numeric( $payload['price'] ) || (float) $payload['price'] < 0 ) {
				$payload['price'] = '0';
			}
			$is_variable_product = ! empty( $payload['variations'] );
			$existing_relation = $this->get_relation_by_wc_product_id( $wc_product_id );
			$dolibarr_id = 0;
			$mapping_method = '';
			$created_in_dolibarr = false;
			$identity = Dolisync_Product_Identity_Resolver::resolve_dolibarr_product( $this->api_client, $wc_product, $existing_relation );
			if ( 'error' === ( $identity['status'] ?? '' ) ) { throw new RuntimeException( (string) $identity['message'] ); }
			if ( 'conflict' === ( $identity['status'] ?? '' ) ) {
				$identity['wc_product_id'] = $wc_product_id;
				$wc_snapshot = Dolisync_Product_Conflicts::snapshot_woocommerce( $wc_product_id );
				$dolibarr_snapshot = Dolisync_Product_Conflicts::snapshot_dolibarr( $this->api_client, $identity['dolibarr_product_id'] ?? 0 );
				Dolisync_Product_Conflicts::record( 'woocommerce_to_dolibarr', $identity, $wc_snapshot, $dolibarr_snapshot );
				$this->stats['skipped']++;
				$this->stats['conflicts']++;
				$this->stats['details'][] = array( 'action' => 'conflict', 'message' => $identity['message'], 'wc_product_id' => $wc_product_id, 'dolibarr_product_id' => (int) ( $identity['dolibarr_product_id'] ?? 0 ) );
				return;
			}
			if ( 'matched' === ( $identity['status'] ?? '' ) ) {
				$dolibarr_id = (int) $identity['id'];
				$mapping_method = 'relation' === $identity['matched_by'] ? '' : $identity['matched_by'];
			}

			$payload_hash = $this->payload_hash( $payload );
			if ( $existing_relation && $dolibarr_id > 0 && hash_equals( (string) get_post_meta( $wc_product_id, '_dolisync_last_export_hash', true ), $payload_hash ) ) {
				$variation_changed = $is_variable_product
					? $this->sync_dolibarr_variations( $dolibarr_id, $wc_product_id, $payload )
					: false;
				$this->set_dolibarr_sale_status( $dolibarr_id );
				$image_changed = $this->image_sync->sync_woocommerce_to_dolibarr( $wc_product_id, $dolibarr_id, '' );
				$action = $image_changed || $variation_changed ? 'updated' : 'skipped';
				$this->stats[ $action ]++;
				$this->stats['details'][] = array( 'action' => $action, 'dolibarr_product_id' => $dolibarr_id, 'wc_product_id' => $wc_product_id );
				return;
			}

			if ( ! $dolibarr_id ) {
				$response = $this->api_client->post( '/products', $this->build_dolibarr_payload( $payload, true ) );
				if ( empty( $response['success'] ) ) {
					throw new Exception( (string) ( $response['message'] ?? __( 'No se pudo crear el producto en Dolibarr.', 'dolisync' ) ) );
				}
				$dolibarr_id = $this->extract_dolibarr_id( $response['data'] ?? null );
				if ( ! $dolibarr_id ) {
					throw new Exception( __( 'Dolibarr no devolvió un ID válido al crear el producto.', 'dolisync' ) );
				}
				$this->update_dolibarr_product( $dolibarr_id, $payload );
				$this->stats['created']++;
				$created_in_dolibarr = true;
			} else {
				$this->update_dolibarr_product( $dolibarr_id, $payload );
				$this->stats[ '' !== $mapping_method ? 'mapped' : 'updated' ]++;
			}

			$this->sync_dolibarr_product_categories( $dolibarr_id, $payload['categories'] );
			$this->image_sync->sync_woocommerce_to_dolibarr( $wc_product_id, $dolibarr_id, '' );
			if ( $created_in_dolibarr && ! $is_variable_product ) {
				$this->set_initial_dolibarr_stock( $dolibarr_id, $payload['stock_qty'], $payload['price'] );
			}

			if ( $is_variable_product ) {
				$this->sync_dolibarr_variations( $dolibarr_id, $wc_product_id, $payload );
			}
			$this->set_dolibarr_sale_status( $dolibarr_id );

			$this->upsert_relation( $dolibarr_id, $wc_product_id, $payload, $existing_relation );
			update_post_meta( $wc_product_id, '_dolisync_last_export_hash', $payload_hash );
			$this->stats['details'][] = array(
				'action' => $existing_relation ? 'updated' : ( '' !== $mapping_method ? 'mapped_' . $mapping_method : 'created' ),
				'dolibarr_product_id' => $dolibarr_id,
				'wc_product_id' => $wc_product_id,
			);

			Dolisync_Action_Logger::log_action(
				'producto',
				$existing_relation ? 'actualización_manual' : ( '' !== $mapping_method ? 'mapeo_por_' . $mapping_method : 'creación_manual' ),
				'finalizado',
				sprintf( __( 'Producto WooCommerce %d exportado a Dolibarr %d (%s).', 'dolisync' ), $wc_product_id, $dolibarr_id, $payload['sku'] ),
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			$this->stats['errors']++;
			$this->stats['errors_list'][] = array(
				'wc_product_id' => isset( $wc_product_id ) ? (int) $wc_product_id : 0,
				'error' => $e->getMessage(),
			);
			Dolisync_Action_Logger::log_action( 'producto', 'sincronización_manual', 'error', 'Error exportando producto: ' . $e->getMessage(), get_current_user_id() );
		}
	}

	private function normalize_wc_product( $wc_product ) {
		$categories = wp_get_post_terms( $wc_product->get_id(), 'product_cat', array( 'fields' => 'all' ) );
		$category_names = array();
		$category_map = array();

		if ( ! is_wp_error( $categories ) && is_array( $categories ) ) {
			foreach ( $categories as $category ) {
				$category_names[] = $category->name;
				$category_map[] = array(
					'id' => (int) $category->term_id,
					'name' => $category->name,
				);
			}
		}

		return array(
			'wc_product_id' => (int) $wc_product->get_id(),
			'sku' => (string) $wc_product->get_sku(),
			'name' => (string) $wc_product->get_name(),
			'description' => (string) $wc_product->get_description(),
			'short_description' => (string) $wc_product->get_short_description(),
			'price' => (string) $this->resolve_wc_product_price( $wc_product ),
			'price_base_type' => function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ? 'TTC' : 'HT',
			'tva_tx' => $this->resolve_wc_tax_rate( $wc_product ),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'stock_qty' => $wc_product->get_stock_quantity(),
			'weight' => $this->convert_wc_weight_to_kg( $wc_product->get_weight() ),
			'length' => $this->convert_wc_dimension_to_meters( $wc_product->get_length() ),
			'width' => $this->convert_wc_dimension_to_meters( $wc_product->get_width() ),
			'height' => $this->convert_wc_dimension_to_meters( $wc_product->get_height() ),
			'url' => method_exists( $wc_product, 'get_product_url' ) ? (string) $wc_product->get_product_url() : '',
			// Todo el catálogo exportado debe quedar disponible para la venta en
			// Dolibarr, independientemente del estado o visibilidad que tenga en Woo.
			'active' => 1,
			'categories' => $category_map,
			'category_names' => $category_names,
				'variations' => $this->normalize_wc_variations( $wc_product ),
			'image_url' => '',
		);
	}

	private function resolve_wc_product_price( $wc_product ) {
		$candidates = array(
			// get_price() devuelve el precio efectivo: usa la rebaja solo cuando
			// está activa y respeta sus fechas de inicio y finalización.
			$wc_product->get_price(),
			$wc_product->get_regular_price(),
			$wc_product->get_sale_price(),
		);

		if ( method_exists( $wc_product, 'is_type' ) && $wc_product->is_type( 'variable' ) ) {
			if ( method_exists( $wc_product, 'get_variation_price' ) ) {
				// También aquí WooCommerce calcula el mínimo efectivo entre las
				// variaciones, incluidas las rebajas vigentes.
				$candidates[] = $wc_product->get_variation_price( 'min', false );
			}
			if ( method_exists( $wc_product, 'get_variation_regular_price' ) ) {
				$candidates[] = $wc_product->get_variation_regular_price( 'min', false );
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( '' !== (string) $candidate && is_numeric( $candidate ) ) {
				return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $candidate ) : (string) $candidate;
			}
		}

		if ( method_exists( $wc_product, 'get_children' ) ) {
			foreach ( (array) $wc_product->get_children() as $variation_id ) {
				$variation = wc_get_product( (int) $variation_id );
				if ( $variation ) {
					$variation_price = $this->resolve_wc_variation_price( $variation );
					if ( '' !== $variation_price ) {
						return $variation_price;
					}
				}
			}
		}

		return '';
	}

	private function resolve_wc_variation_price( $variation ) {
		foreach ( array( $variation->get_price(), $variation->get_regular_price(), $variation->get_sale_price() ) as $candidate ) {
			if ( '' !== (string) $candidate && is_numeric( $candidate ) ) {
				return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $candidate ) : (string) $candidate;
			}
		}
		return '';
	}

	private function build_dolibarr_payload( $payload, $for_creation = false ) {
		$data = array(
			'ref' => '' !== trim( $payload['sku'] ) ? $payload['sku'] : 'WC-' . (int) ( $payload['wc_product_id'] ?? 0 ),
			'label' => $payload['name'],
			'description' => $payload['description'],
			'note_public' => $payload['short_description'],
			'price_base_type' => $payload['price_base_type'],
			'tva_tx' => (float) $payload['tva_tx'],
			// Precio, variantes y stock se escriben antes de aplicar el estado
			// comercial definitivo. Algunos modos de precio de Dolibarr no
			// registran correctamente el precio si el producto ya está inactivo.
			'status' => 1,
			'type' => 0,
			'weight' => $payload['weight'],
			'weight_units' => 0,
			'length' => $payload['length'],
			'length_units' => 0,
			'width' => $payload['width'],
			'width_units' => 0,
			'height' => $payload['height'],
			'height_units' => 0,
			'url' => $payload['url'],
			'caller' => 'dolisync',
		);
		if ( 'TTC' === $payload['price_base_type'] ) {
			$data['price_ttc'] = $payload['price'];
			$data['multiprices_ttc'] = array( null, $payload['price'] );
			$data['multiprices_min_ttc'] = array( null, 0 );
		} else {
			$data['price'] = $payload['price'];
			$data['multiprices'] = array( null, $payload['price'] );
			$data['multiprices_min'] = array( null, 0 );
		}
		$data['multiprices_base_type'] = array( null, $payload['price_base_type'] );
		$data['multiprices_tva_tx'] = array( null, (float) $payload['tva_tx'] );
		if ( $for_creation ) {
			$data['barcode'] = 'auto';
		}

		return $data;
	}

	private function payload_hash( $payload ) {
		$normalize = function ( $value ) use ( &$normalize ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			foreach ( $value as $key => $child ) {
				// El stock no forma parte de la exportación rutinaria WooCommerce → Dolibarr.
				if ( 'stock_qty' === (string) $key ) {
					unset( $value[ $key ] );
					continue;
				}
				$value[ $key ] = $normalize( $child );
			}
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value );
			} else {
				usort( $value, static function ( $a, $b ) { return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) ); } );
			}
			return $value;
		};
		return hash( 'sha256', self::PAYLOAD_VERSION . '|' . wp_json_encode( $normalize( $payload ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private function update_dolibarr_product( $dolibarr_id, $payload ) {
		$response = $this->api_client->put( '/products/' . (int) $dolibarr_id, $this->build_dolibarr_payload( $payload ) );
		if ( empty( $response['success'] ) ) {
			throw new Exception( (string) ( $response['message'] ?? __( 'No se pudo actualizar el producto en Dolibarr.', 'dolisync' ) ) );
		}
	}

	private function resolve_wc_tax_rate( $wc_product ) {
		if ( ! class_exists( 'WC_Tax' ) || ! method_exists( $wc_product, 'get_tax_class' ) ) {
			return 0.0;
		}
		$rates = WC_Tax::get_rates( $wc_product->get_tax_class() );
		foreach ( (array) $rates as $rate ) {
			if ( isset( $rate['rate'] ) && is_numeric( $rate['rate'] ) ) {
				return (float) $rate['rate'];
			}
		}
		return 0.0;
	}

	private function convert_wc_weight_to_kg( $weight ) {
		if ( '' === (string) $weight || ! is_numeric( $weight ) ) {
			return null;
		}
		return function_exists( 'wc_get_weight' ) ? (float) wc_get_weight( (float) $weight, 'kg' ) : (float) $weight;
	}

	private function convert_wc_dimension_to_meters( $dimension ) {
		if ( '' === (string) $dimension || ! is_numeric( $dimension ) ) {
			return null;
		}
		return function_exists( 'wc_get_dimension' ) ? (float) wc_get_dimension( (float) $dimension, 'm' ) : (float) $dimension;
	}

	private function sync_dolibarr_product_categories( $dolibarr_product_id, $categories ) {
		$dolibarr_product_id = (int) $dolibarr_product_id;
		if ( $dolibarr_product_id <= 0 || ! is_array( $categories ) ) {
			return;
		}

		$resolved_category_ids = $this->resolve_dolibarr_category_ids_from_wc_categories( $categories );
		if ( empty( $resolved_category_ids ) ) {
			Dolisync_Action_Logger::log_action( 'producto', 'categorias_dolibarr', 'finalizado', sprintf( __( 'Producto Dolibarr %d sin categorías mapeadas desde WooCommerce.', 'dolisync' ), $dolibarr_product_id ), get_current_user_id() );
			return;
		}

		$current_categories = $this->fetch_dolibarr_product_categories_for_product( $dolibarr_product_id );
		$current_category_ids = array_map( 'intval', wp_list_pluck( $current_categories, 'id' ) );
		$to_remove = array_diff( $current_category_ids, $resolved_category_ids );
		$to_add = array_diff( $resolved_category_ids, $current_category_ids );

		foreach ( $to_remove as $category_id ) {
			$this->api_client->delete( '/categories/' . (int) $category_id . '/objects/product/' . $dolibarr_product_id );
		}

		foreach ( $to_add as $category_id ) {
			$this->api_client->post( '/categories/' . (int) $category_id . '/objects/product/' . $dolibarr_product_id );
		}
	}

	private function resolve_dolibarr_category_ids_from_wc_categories( $categories ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_category_mappings';
		$resolved_ids = array();

		foreach ( (array) $categories as $category ) {
			$wc_category_id = (int) ( $category['id'] ?? 0 );
			if ( $wc_category_id <= 0 ) {
				continue;
			}

			$mapping = $wpdb->get_row(
				$wpdb->prepare( "SELECT dolibarr_category_id FROM {$table} WHERE wc_category_id = %d LIMIT 1", $wc_category_id ),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			$dolibarr_category_id = (int) ( $mapping['dolibarr_category_id'] ?? 0 );
			if ( $dolibarr_category_id > 0 ) {
				$resolved_ids[] = $dolibarr_category_id;
			}
		}

		return array_values( array_unique( array_filter( $resolved_ids ) ) );
	}

	private function fetch_dolibarr_product_categories_for_product( $dolibarr_product_id ) {
		$response = $this->api_client->get( '/products/' . (int) $dolibarr_product_id . '/categories' );
		if ( empty( $response['success'] ) ) {
			return array();
		}

		$data = $response['data'] ?? array();
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}

		if ( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}

		return is_array( $data ) ? $data : array();
	}

	private function normalize_wc_variations( $wc_product ) {
		if ( ! method_exists( $wc_product, 'is_type' ) || ! $wc_product->is_type( 'variable' ) ) {
			return array();
		}

		$variation_ids = $wc_product->get_children();
		$variations = array();

		foreach ( (array) $variation_ids as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );
			if ( ! $variation || ! method_exists( $variation, 'get_id' ) ) {
				continue;
			}

			$attributes = array();
			foreach ( (array) $variation->get_attributes() as $attribute_key => $attribute_value ) {
				$normalized_key = sanitize_title( preg_replace( '/^attribute_/', '', (string) $attribute_key ) );
				if ( '' !== $normalized_key && '' !== (string) $attribute_value ) {
					$attributes[ $normalized_key ] = sanitize_text_field( (string) $attribute_value );
				}
			}

			$variations[] = array(
				'id' => (int) $variation->get_id(),
				'sku' => (string) $variation->get_sku(),
				'name' => (string) $variation->get_name(),
				'description' => (string) $variation->get_description(),
				'price' => $this->resolve_wc_variation_price( $variation ),
				'tva_tx' => $this->resolve_wc_tax_rate( $variation ),
				'stock_qty' => $variation->get_manage_stock() ? $variation->get_stock_quantity() : null,
				'weight' => $this->convert_wc_weight_to_kg( $variation->get_weight() ),
				'length' => $this->convert_wc_dimension_to_meters( $variation->get_length() ),
				'width' => $this->convert_wc_dimension_to_meters( $variation->get_width() ),
				'height' => $this->convert_wc_dimension_to_meters( $variation->get_height() ),
				'attributes' => $attributes,
			);
		}

		return $variations;
	}

	private function find_dolibarr_product_by_sku( $sku ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return 0;
		}

		$escaped_sku = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $sku );
		$response = $this->api_client->get( '/products', array( 'sortfield' => 't.rowid', 'sortorder' => 'ASC', 'limit' => 100, 'mode' => 1, 'sqlfilters' => "(t.ref:=:'{$escaped_sku}')" ) );
		if ( empty( $response['success'] ) ) {
			return 0;
		}

		$data = $this->normalize_api_array( $response['data'] ?? array() );
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_relations';
		foreach ( $data as $product ) {
			$product = $this->normalize_api_array( $product );
			$id = (int) ( $product['id'] ?? $product['rowid'] ?? 0 );
			if ( $id > 0 && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dolibarr_product_id = %d LIMIT 1", $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $id;
			}
		}
		return 0;
	}

	private function find_unmapped_dolibarr_product_by_name( $name ) {
		global $wpdb;
		$name = trim( sanitize_text_field( (string) $name ) );
		if ( '' === $name ) {
			return 0;
		}

		// El filtro universal de Dolibarr permite recuperar duplicados y emparejarlos en orden de ID.
		$escaped_name = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $name );
		$table = $wpdb->prefix . 'dolisync_product_relations';
		$page = 0;
		$limit = 100;
		do {
			$response = $this->api_client->get( '/products', array( 'sortfield' => 't.rowid', 'sortorder' => 'ASC', 'limit' => $limit, 'page' => $page, 'mode' => 1, 'sqlfilters' => "(t.label:=:'{$escaped_name}')" ) );
			if ( empty( $response['success'] ) ) {
				return 0;
			}
			$data = $this->normalize_api_array( $response['data'] ?? array() );
			if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				$data = $data['data'];
			}
			foreach ( $data as $product ) {
				$product = $this->normalize_api_array( $product );
				$id = (int) ( $product['id'] ?? $product['rowid'] ?? 0 );
				if ( $id > 0 && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dolibarr_product_id = %d LIMIT 1", $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return $id;
				}
			}
			$page++;
		} while ( count( $data ) >= $limit );
		return 0;
	}

	private function normalize_api_array( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		return is_array( $data ) ? $data : array();
	}

	private function resolve_warehouse_id() {
		if ( null !== $this->warehouse_id ) {
			return $this->warehouse_id;
		}

		$this->warehouse_id = Dolisync_Config::get_warehouse_id();
		if ( $this->warehouse_id <= 0 ) {
			return 0;
		}

		$response = $this->api_client->get( '/warehouses/' . $this->warehouse_id );
		$warehouse = ! empty( $response['success'] ) ? $this->normalize_api_array( $response['data'] ?? array() ) : array();
		$status = (int) ( $warehouse['statut'] ?? $warehouse['status'] ?? 0 );
		if ( empty( $warehouse ) || 1 !== $status ) {
			throw new Exception( __( 'El almacén configurado no existe o no está activo en Dolibarr.', 'dolisync' ) );
		}

		return $this->warehouse_id;
	}

	private function set_initial_dolibarr_stock( $dolibarr_product_id, $stock_qty, $price = '' ) {
		if ( ! is_numeric( $stock_qty ) || (float) $stock_qty <= 0 ) {
			return;
		}

		$warehouse_id = $this->resolve_warehouse_id();
		if ( $warehouse_id <= 0 ) {
			throw new Exception( __( 'Configura el ID del almacén de Dolibarr antes de importar stock inicial.', 'dolisync' ) );
		}

		$response = $this->api_client->post( '/stockmovements', array(
			'product_id' => (int) $dolibarr_product_id,
			'warehouse_id' => $warehouse_id,
			'qty' => (float) $stock_qty,
			'type' => 3,
			'movementcode' => 'DOLISYNC-INITIAL-' . (int) $dolibarr_product_id,
			'label' => 'Stock inicial importado desde WooCommerce',
			'price' => is_numeric( $price ) ? (float) $price : 0,
		) );
		if ( empty( $response['success'] ) ) {
			throw new Exception( (string) ( $response['message'] ?? __( 'No se pudo registrar el stock inicial en Dolibarr.', 'dolisync' ) ) );
		}
	}

	private function set_dolibarr_sale_status( $dolibarr_id ) {
		$response = $this->api_client->put(
			'/products/' . (int) $dolibarr_id,
			array(
				// DoliSync mantiene todos los productos sincronizados a la venta.
				'status' => 1,
				'caller' => 'dolisync',
			)
		);
		if ( empty( $response['success'] ) ) {
			throw new Exception( (string) ( $response['message'] ?? __( 'No se pudo actualizar el estado de venta del producto en Dolibarr.', 'dolisync' ) ) );
		}
	}

	private function sync_dolibarr_variation_stock( $dolibarr_product_id, $stock_qty, $price, $wc_variation_id ) {
		if ( ! is_numeric( $stock_qty ) ) {
			return false;
		}

		$response = $this->api_client->get( '/products/' . (int) $dolibarr_product_id, array( 'includestockdata' => 1 ) );
		if ( empty( $response['success'] ) ) {
			throw new Exception( (string) ( $response['message'] ?? __( 'No se pudo consultar el stock actual de la variante en Dolibarr.', 'dolisync' ) ) );
		}

		$product = $this->normalize_api_array( $response['data'] ?? array() );
		$current_stock = $product['stock_reel'] ?? null;
		if ( ! is_numeric( $current_stock ) ) {
			throw new Exception( __( 'Dolibarr no devolvió un stock numérico para la variante.', 'dolisync' ) );
		}

		$target_stock = (float) $stock_qty;
		$quantity_delta = $target_stock - (float) $current_stock;
		if ( abs( $quantity_delta ) < 0.000001 ) {
			return false;
		}

		$warehouse_id = $this->resolve_warehouse_id();
		if ( $warehouse_id <= 0 ) {
			throw new Exception( __( 'Configura el ID del almacén de Dolibarr antes de sincronizar stock de variaciones.', 'dolisync' ) );
		}

		$movement = $this->api_client->post( '/stockmovements', array(
			'product_id' => (int) $dolibarr_product_id,
			'warehouse_id' => $warehouse_id,
			'qty' => $quantity_delta,
			'type' => 3,
			'movementcode' => 'DOLISYNC-WC-VAR-' . (int) $wc_variation_id . '-' . time(),
			'label' => 'Ajuste de stock de variación desde WooCommerce',
			'price' => is_numeric( $price ) ? (float) $price : 0,
		) );
		if ( empty( $movement['success'] ) ) {
			throw new Exception( (string) ( $movement['message'] ?? __( 'No se pudo ajustar el stock de la variante en Dolibarr.', 'dolisync' ) ) );
		}
		return true;
	}

	private function sync_dolibarr_variations( $dolibarr_id, $wc_product_id, $payload ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$parent_price = is_numeric( $payload['price'] ) ? (float) $payload['price'] : 0.0;
		$stock_changed = false;

		foreach ( (array) $payload['variations'] as $variation ) {
			$wc_variation_id = (int) ( $variation['id'] ?? 0 );
			$relation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_variation_id = %d LIMIT 1", $wc_variation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$combination_id = (int) ( $relation['dolibarr_combination_id'] ?? 0 );
			$child_id = (int) ( $relation['dolibarr_variation_id'] ?? 0 );
			if ( $combination_id <= 0 ) {
				$matched = $this->find_unmapped_dolibarr_variation( $dolibarr_id, $variation );
				$combination_id = (int) ( $matched['combination_id'] ?? 0 );
				$child_id = (int) ( $matched['child_id'] ?? 0 );
			}
			$features = array();
			foreach ( (array) ( $variation['attributes'] ?? array() ) as $attribute_name => $attribute_value ) {
				$attribute_id = $this->ensure_dolibarr_attribute( $attribute_name );
				$value_id = $this->ensure_dolibarr_attribute_value( $attribute_id, $attribute_value );
				if ( $attribute_id > 0 && $value_id > 0 ) {
					$features[ $attribute_id ] = $value_id;
				}
			}
			if ( empty( $features ) ) {
				continue;
			}

			// El precio comercial pertenece al producto padre. Las combinaciones
			// solo representan atributos y nunca modifican ese precio.
			$price = $parent_price;
			if ( $combination_id <= 0 ) {
				$created = $this->api_client->post( '/products/' . $dolibarr_id . '/variants', array(
					'weight_impact' => 0,
					'price_impact' => 0,
					'price_impact_is_percent' => false,
					'features' => $features,
					'reference' => '' !== trim( (string) ( $variation['sku'] ?? '' ) ) ? (string) $variation['sku'] : 'WC-VAR-' . $wc_variation_id,
				) );
				if ( empty( $created['success'] ) ) {
					throw new Exception( (string) ( $created['message'] ?? __( 'No se pudo crear una variante en Dolibarr.', 'dolisync' ) ) );
				}
				$combination_id = $this->extract_dolibarr_id( $created['data'] ?? null );
			}

			if ( $combination_id > 0 ) {
				$combination_update = $this->api_client->put( '/products/variants/' . $combination_id, array(
					'price_impact' => 0,
					'price_impact_is_percent' => false,
					'weight_impact' => 0,
					'caller' => 'dolisync',
				) );
				if ( empty( $combination_update['success'] ) ) {
					throw new Exception( (string) ( $combination_update['message'] ?? __( 'No se pudo actualizar la combinación en Dolibarr.', 'dolisync' ) ) );
				}
			}

			$variant_info = $this->find_dolibarr_combination( $dolibarr_id, $combination_id );
			$child_id = (int) ( $variant_info['fk_product_child'] ?? $child_id );
			if ( $child_id > 0 ) {
				$child_payload = array(
					'label' => (string) ( $variation['name'] ?? $payload['name'] ),
					'description' => (string) ( $variation['description'] ?? '' ),
					'price_base_type' => $payload['price_base_type'],
					'tva_tx' => (float) ( $variation['tva_tx'] ?? $payload['tva_tx'] ),
					'status' => 1,
					'weight' => $variation['weight'] ?? null,
					'weight_units' => 0,
					'length' => $variation['length'] ?? null,
					'length_units' => 0,
					'width' => $variation['width'] ?? null,
					'width_units' => 0,
					'height' => $variation['height'] ?? null,
					'height_units' => 0,
					'caller' => 'dolisync',
				);
				$child_payload[ 'TTC' === $payload['price_base_type'] ? 'price_ttc' : 'price' ] = $price;
				if ( '' !== trim( (string) ( $variation['sku'] ?? '' ) ) ) {
					$child_payload['ref'] = (string) $variation['sku'];
				}
				$child_update = $this->api_client->put( '/products/' . $child_id, $child_payload );
				if ( empty( $child_update['success'] ) ) {
					throw new Exception( (string) ( $child_update['message'] ?? __( 'No se pudo actualizar el producto hijo de la variante.', 'dolisync' ) ) );
				}
				$stock_changed = $this->sync_dolibarr_variation_stock( $child_id, $variation['stock_qty'] ?? null, $price, $wc_variation_id ) || $stock_changed;
				$this->image_sync->sync_woocommerce_to_dolibarr( $wc_variation_id, $child_id, '' );
				$this->set_dolibarr_sale_status( $child_id );
			}

			$this->save_variation_relation( $dolibarr_id, $wc_product_id, $child_id, $combination_id, $variation );
		}
		return $stock_changed;
	}

	private function find_unmapped_dolibarr_variation( $dolibarr_product_id, $variation ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$response = $this->api_client->get( '/products/' . $dolibarr_product_id . '/variants' );
		if ( empty( $response['success'] ) ) {
			return array();
		}
		$target_sku = trim( (string) ( $variation['sku'] ?? '' ) );
		foreach ( $this->normalize_api_array( $response['data'] ?? array() ) as $combination ) {
			$combination = $this->normalize_api_array( $combination );
			$combination_id = (int) ( $combination['id'] ?? $combination['rowid'] ?? 0 );
			$child_id = (int) ( $combination['fk_product_child'] ?? 0 );
			if ( $combination_id <= 0 || $child_id <= 0 || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dolibarr_combination_id = %d OR dolibarr_variation_id = %d LIMIT 1", $combination_id, $child_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			$child_response = $this->api_client->get( '/products/' . $child_id );
			$child = ! empty( $child_response['success'] ) ? $this->normalize_api_array( $child_response['data'] ?? array() ) : array();
			if ( '' !== $target_sku && $target_sku === trim( (string) ( $child['ref'] ?? '' ) ) ) {
				return array( 'combination_id' => $combination_id, 'child_id' => $child_id );
			}
			if ( '' === $target_sku && $this->normalize_name( $variation['name'] ?? '' ) === $this->normalize_name( $child['label'] ?? '' ) ) {
				return array( 'combination_id' => $combination_id, 'child_id' => $child_id );
			}
		}
		return array();
	}

	private function normalize_name( $name ) {
		$name = remove_accents( sanitize_text_field( (string) $name ) );
		return strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ) );
	}

	private function ensure_dolibarr_attribute( $name ) {
		$name = sanitize_text_field( (string) $name );
		$ref = strtoupper( substr( sanitize_title( $name ), 0, 30 ) );
		if ( '' === $ref ) {
			return 0;
		}
		if ( isset( $this->attribute_cache[ $ref ] ) ) {
			return $this->attribute_cache[ $ref ];
		}
		$response = $this->api_client->get( '/products/attributes/ref/' . rawurlencode( $ref ) );
		$id = ! empty( $response['success'] ) ? $this->extract_dolibarr_id( $response['data'] ?? null ) : 0;
		if ( $id <= 0 ) {
			$response = $this->api_client->post( '/products/attributes', array( 'ref' => $ref, 'label' => $name, 'ref_ext' => 'dolisync-' . strtolower( $ref ) ) );
			$id = ! empty( $response['success'] ) ? $this->extract_dolibarr_id( $response['data'] ?? null ) : 0;
		}
		$this->attribute_cache[ $ref ] = $id;
		return $id;
	}

	private function ensure_dolibarr_attribute_value( $attribute_id, $value ) {
		$value = sanitize_text_field( (string) $value );
		$ref = strtoupper( substr( sanitize_title( $value ), 0, 30 ) );
		$cache_key = $attribute_id . ':' . $ref;
		if ( $attribute_id <= 0 || '' === $ref ) {
			return 0;
		}
		if ( isset( $this->attribute_value_cache[ $cache_key ] ) ) {
			return $this->attribute_value_cache[ $cache_key ];
		}
		$response = $this->api_client->get( '/products/attributes/' . $attribute_id . '/values/ref/' . rawurlencode( $ref ) );
		$id = ! empty( $response['success'] ) ? $this->extract_dolibarr_id( $response['data'] ?? null ) : 0;
		if ( $id <= 0 ) {
			$response = $this->api_client->post( '/products/attributes/' . $attribute_id . '/values', array( 'ref' => $ref, 'value' => $value ) );
			$id = ! empty( $response['success'] ) ? $this->extract_dolibarr_id( $response['data'] ?? null ) : 0;
		}
		$this->attribute_value_cache[ $cache_key ] = $id;
		return $id;
	}

	private function find_dolibarr_combination( $dolibarr_product_id, $combination_id ) {
		$response = $this->api_client->get( '/products/' . $dolibarr_product_id . '/variants' );
		if ( empty( $response['success'] ) ) {
			return array();
		}
		foreach ( $this->normalize_api_array( $response['data'] ?? array() ) as $combination ) {
			$combination = $this->normalize_api_array( $combination );
			if ( (int) ( $combination['id'] ?? $combination['rowid'] ?? 0 ) === (int) $combination_id ) {
				return $combination;
			}
		}
		return array();
	}

	private function save_variation_relation( $dolibarr_id, $wc_product_id, $child_id, $combination_id, $variation ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$wc_variation_id = (int) ( $variation['id'] ?? 0 );
		$data = array(
			'dolibarr_product_id' => (int) $dolibarr_id,
			'wc_product_id' => (int) $wc_product_id,
			'dolibarr_variation_id' => (int) $child_id,
			'dolibarr_combination_id' => (int) $combination_id,
			'wc_variation_id' => $wc_variation_id,
			'sku' => (string) ( $variation['sku'] ?? '' ),
			'price' => $this->variation_relation_price_excluding_tax( $variation ),
			'attributes_json' => wp_json_encode( $variation['attributes'] ?? array() ),
			'synced_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wc_variation_id = %d LIMIT 1", $wc_variation_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists ) {
			$wpdb->update( $table, $data, array( 'wc_variation_id' => $wc_variation_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	private function extract_dolibarr_id( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		if ( is_array( $data ) ) {
			return (int) ( $data['id'] ?? $data['rowid'] ?? $data[0]['id'] ?? $data[0]['rowid'] ?? 0 );
		}
		if ( is_numeric( $data ) ) {
			return (int) $data;
		}
		return 0;
	}

	private function upsert_variation_relations( $dolibarr_id, $wc_product_id, $variations ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$kept_variation_ids = array();

		foreach ( (array) $variations as $variation ) {
			$wc_variation_id = (int) ( $variation['id'] ?? 0 );
			$kept_variation_ids[] = $wc_variation_id;
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE wc_variation_id = %d",
					$wc_variation_id
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			$data = array(
				'dolibarr_product_id' => $dolibarr_id,
				'wc_product_id' => $wc_product_id,
				'dolibarr_variation_id' => (int) ( $variation['dolibarr_variation_id'] ?? 0 ),
				'wc_variation_id' => $wc_variation_id,
				'sku' => (string) ( $variation['sku'] ?? '' ),
				'price' => $this->variation_relation_price_excluding_tax( $variation ),
				'attributes_json' => wp_json_encode( $variation['attributes'] ?? array() ),
				'synced_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			);

			if ( empty( $existing ) ) {
				$data['created_at'] = current_time( 'mysql' );
				$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$wpdb->update( $table, $data, array( 'wc_variation_id' => $wc_variation_id ), array_fill( 0, count( $data ), '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		if ( ! empty( $kept_variation_ids ) ) {
			$existing_variations = $wpdb->get_col( $wpdb->prepare( "SELECT wc_variation_id FROM {$table} WHERE wc_product_id = %d", $wc_product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $existing_variations as $existing_variation_id ) {
				if ( ! in_array( (int) $existing_variation_id, $kept_variation_ids, true ) ) {
					$wpdb->delete( $table, array( 'wc_variation_id' => (int) $existing_variation_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}
	}

	private function upsert_relation( $dolibarr_id, $wc_product_id, $payload, $existing_relation ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_relations';
		$data = array(
			'dolibarr_product_id' => $dolibarr_id,
			'wc_product_id' => $wc_product_id,
			'sku' => $payload['sku'],
			'name' => $payload['name'],
			'description' => $payload['description'],
			'short_description' => $payload['short_description'],
			'price' => $payload['price'],
			'currency' => $payload['currency'],
			'product_type' => empty( $payload['variations'] ) ? 'simple' : 'variable',
			'status' => $payload['active'] ? 'publish' : 'hidden',
			'categories_json' => wp_json_encode( $payload['categories'] ),
			'image_url' => $payload['image_url'],
			'last_sync_status' => 'success',
			'last_error_message' => '',
			'synced_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		if ( empty( $existing_relation ) ) {
			$data['created_at'] = current_time( 'mysql' );
			$data['first_synced_at'] = current_time( 'mysql' );
			$result = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $result ) {
				throw new Exception( __( 'No se pudo guardar el mapeo del producto en la base de datos local.', 'dolisync' ) );
			}
			return;
		}

		$result = $wpdb->update( $table, $data, array( 'wc_product_id' => $wc_product_id ), array_fill( 0, count( $data ), '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			throw new Exception( __( 'No se pudo actualizar el mapeo del producto en la base de datos local.', 'dolisync' ) );
		}
	}

	private function get_relation_by_wc_product_id( $wc_product_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_relations';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_product_id = %d", $wc_product_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * La tabla de relaciones conserva precios HT para poder compararlos con el
	 * campo `price` de Dolibarr aunque WooCommerce almacene precios con IVA.
	 */
	private function variation_relation_price_excluding_tax( $variation ) {
		$wc_variation_id = (int) ( $variation['id'] ?? 0 );
		$wc_variation = $wc_variation_id > 0 ? wc_get_product( $wc_variation_id ) : false;
		if ( $wc_variation && function_exists( 'wc_get_price_excluding_tax' ) ) {
			$price = $wc_variation->get_price();
			if ( '' !== (string) $price && is_numeric( $price ) ) {
				return wc_format_decimal(
					wc_get_price_excluding_tax( $wc_variation, array( 'qty' => 1, 'price' => (float) $price ) ),
					wc_get_price_decimals(),
					false
				);
			}
		}
		return isset( $variation['price'] ) && is_numeric( $variation['price'] )
			? wc_format_decimal( $variation['price'], wc_get_price_decimals(), false )
			: null;
	}
}
