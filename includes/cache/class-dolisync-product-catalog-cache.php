<?php
/**
 * Caché persistente e incremental del catálogo WooCommerce/Dolibarr.
 *
 * Ninguna lectura del panel hace llamadas remotas. La API se recorre en lotes
 * cortos mediante WP-Cron y los datos anteriores siguen visibles mientras se
 * construye una nueva generación completa.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/sync/products/class-dolisync-product-variation-reference.php';

class Dolisync_Product_Catalog_Cache {
	private const STATE_OPTION = 'dolisync_catalog_cache_state';
	private const STATUS_OPTION = 'dolisync_catalog_cache_status';
	private const LOCK_OPTION = 'dolisync_catalog_cache_lock';
	private const BATCH_HOOK = 'dolisync_catalog_cache_batch';
	private const REFRESH_HOOK = 'dolisync_catalog_cache_refresh';
	private const WOO_BATCH_SIZE = 25;
	private const DOLIBARR_BATCH_SIZE = 25;
	private const CHILD_CALLS_PER_BATCH = 4;
	private const MAX_DOLIBARR_PAGES = 400;
	private const REFRESH_AFTER = 15 * MINUTE_IN_SECONDS;
	private const STALE_LOCK_AFTER = 10 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'maybe_start_refresh' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );

		add_action( 'woocommerce_new_product', array( __CLASS__, 'refresh_woocommerce_item' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'refresh_woocommerce_item' ), 10, 1 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'refresh_woocommerce_variation' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'refresh_woocommerce_variation' ), 10, 1 );
		add_action( 'woocommerce_delete_product', array( __CLASS__, 'delete_woocommerce_item' ), 10, 1 );
		add_action( 'woocommerce_delete_product_variation', array( __CLASS__, 'delete_woocommerce_variation' ), 10, 1 );
	}

	/** Programa el mantenimiento periódico y el primer llenado sin bloquear la petición. */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + 60, 'm1', self::REFRESH_HOOK );
		}
		$status = get_option( self::STATUS_OPTION, array() );
		$status = is_array( $status ) ? $status : array();
		if ( empty( $status['completed_at'] ) && ! get_option( self::STATE_OPTION, array() ) ) {
			self::request_refresh();
		}
	}

	/**
	 * Solicita una nueva generación. La operación solo agenda trabajo; no llama
	 * a Dolibarr durante la carga del panel.
	 */
	public static function request_refresh( $force = false ) {
		$state = get_option( self::STATE_OPTION, array() );
		if ( is_array( $state ) && ! empty( $state['token'] ) ) {
			$legacy_state = (int) ( $state['format_version'] ?? 1 ) < 2;
			if ( $legacy_state ) {
				// Reactiva enseguida una generación que quedó reintentando con la
				// lógica anterior, sin obligar a borrar la caché de producción.
				$state['format_version'] = 2;
				update_option( self::STATE_OPTION, $state, false );
			}
			$updated = (int) ( $state['updated_at'] ?? 0 );
			if ( $updated > 0 && time() - $updated < self::STALE_LOCK_AFTER ) {
				if ( $force ) {
					$current_status = get_option( self::STATUS_OPTION, array() );
					$current_status = is_array( $current_status ) ? $current_status : array();
					// Durante el primer llenado el botón debe reactivar el trabajo, no
					// encadenar otra reconstrucción completa al terminar la actual.
					if ( ! empty( $current_status['completed_at'] ) ) {
						$state['refresh_again'] = true;
					}
					unset( $state['retry_after'] );
					update_option( self::STATE_OPTION, $state, false );
				}
				self::schedule_batch( 1, $legacy_state || $force );
				return false;
			}
		}

		$status = get_option( self::STATUS_OPTION, array() );
		$status = is_array( $status ) ? $status : array();
		$completed = (int) ( $status['completed_timestamp'] ?? 0 );
		if ( ! $force && $completed > 0 && time() - $completed < self::REFRESH_AFTER ) {
			return false;
		}

		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', uniqid( 'dolisync-cache-', true ) );
		$state = array(
			'format_version' => 2,
			'token' => $token,
			'stage' => 'woocommerce',
			'page' => 1,
			'filter' => 1,
			'detail_cursor' => 0,
			'started_at' => time(),
			'updated_at' => time(),
			'last_error' => '',
			'warning_count' => 0,
			'last_warning' => '',
		);
		update_option( self::STATE_OPTION, $state, false );
		update_option(
			self::STATUS_OPTION,
			array_merge( $status, array( 'state' => 'refreshing', 'started_at' => current_time( 'mysql' ), 'last_error' => '' ) ),
			false
		);
		self::schedule_batch( 1 );
		return true;
	}

	/** Reanuda una generación en curso o inicia una cuando la caché está caducada. */
	public static function maybe_start_refresh() {
		self::request_refresh();
	}

	/** Procesa como máximo una página o unos pocos hijos remotos. */
	public static function run_batch() {
		if ( ! self::acquire_lock() ) {
			self::schedule_batch( 30 );
			return;
		}

		try {
			$state = get_option( self::STATE_OPTION, array() );
			if ( ! is_array( $state ) || empty( $state['token'] ) ) {
				return;
			}
			$retry_after = max( 0, (int) ( $state['retry_after'] ?? 0 ) );
			if ( $retry_after > time() ) {
				self::schedule_batch( $retry_after - time() );
				return;
			}
			if ( self::remote_sync_is_running() ) {
				$state['updated_at'] = time();
				$state['paused_reason'] = 'remote_sync';
				update_option( self::STATE_OPTION, $state, false );
				self::schedule_batch( 60 );
				return;
			}
			unset( $state['paused_reason'] );

			switch ( (string) ( $state['stage'] ?? '' ) ) {
				case 'woocommerce':
					$state = self::refresh_woocommerce_batch( $state );
					break;
				case 'dolibarr_list':
					$state = self::refresh_dolibarr_list_batch( $state );
					break;
				case 'dolibarr_details':
					$state = self::refresh_dolibarr_details_batch( $state );
					break;
				default:
					throw new RuntimeException( __( 'Estado desconocido de la caché de productos.', 'dolisync' ) );
			}

			// Una petición manual puede llegar mientras este lote está trabajando.
			// Conservamos su marca para no sobrescribirla con el estado leído al inicio.
			$latest_state = get_option( self::STATE_OPTION, array() );
			if (
				is_array( $latest_state )
				&& (string) ( $latest_state['token'] ?? '' ) === (string) ( $state['token'] ?? '' )
				&& ! empty( $latest_state['refresh_again'] )
			) {
				$state['refresh_again'] = true;
			}

			if ( ! empty( $state['complete'] ) ) {
				self::complete_refresh( $state );
				return;
			}

			$state['updated_at'] = time();
			$state['last_error'] = '';
			unset( $state['retry_after'] );
			update_option( self::STATE_OPTION, $state, false );
			$status = get_option( self::STATUS_OPTION, array() );
			if ( is_array( $status ) && ( ! empty( $status['last_error'] ) || 'retrying' === ( $status['state'] ?? '' ) ) ) {
				update_option( self::STATUS_OPTION, array_merge( $status, array( 'state' => 'refreshing', 'last_error' => '' ) ), false );
			}
			self::schedule_batch( 5 );
		} catch ( Throwable $error ) {
			$state = get_option( self::STATE_OPTION, array() );
			if ( is_array( $state ) ) {
				$state['updated_at'] = time();
				$state['last_error'] = sanitize_text_field( $error->getMessage() );
				$state['retry_after'] = time() + 5 * MINUTE_IN_SECONDS;
				update_option( self::STATE_OPTION, $state, false );
			}
			$status = get_option( self::STATUS_OPTION, array() );
			update_option( self::STATUS_OPTION, array_merge( is_array( $status ) ? $status : array(), array( 'state' => 'retrying', 'last_error' => sanitize_text_field( $error->getMessage() ) ) ), false );
			self::schedule_batch( 5 * MINUTE_IN_SECONDS );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	private static function refresh_woocommerce_batch( $state ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			throw new RuntimeException( __( 'WooCommerce no está disponible para actualizar la caché.', 'dolisync' ) );
		}
		$page = max( 1, (int) ( $state['page'] ?? 1 ) );
		$ids = wc_get_products(
			array(
				'limit' => self::WOO_BATCH_SIZE,
				'page' => $page,
				'status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'orderby' => 'ID',
				'order' => 'ASC',
				'return' => 'ids',
			)
		);
		foreach ( (array) $ids as $product_id ) {
			$product = wc_get_product( (int) $product_id );
			if ( $product ) {
				self::upsert( 'woocommerce', (int) $product_id, self::serialize_woocommerce_product( $product ), (string) $state['token'] );
			}
		}

		if ( count( (array) $ids ) < self::WOO_BATCH_SIZE ) {
			self::delete_old_generation( 'woocommerce', (string) $state['token'] );
			$state['stage'] = 'dolibarr_list';
			$state['page'] = 0;
			$state['filter'] = 1;
		} else {
			$state['page'] = $page + 1;
		}
		return $state;
	}

	private static function refresh_dolibarr_list_batch( $state ) {
		require_once dirname( __DIR__ ) . '/api/class-dolisync-api-client.php';
		$filter = in_array( (int) ( $state['filter'] ?? 1 ), array( 1, 2 ), true ) ? (int) $state['filter'] : 1;
		$page = max( 0, (int) ( $state['page'] ?? 0 ) );
		$response = self::api_get(
			new Dolisync_API_Client(),
			'/products',
			array(
				'sortfield' => 't.rowid',
				'sortorder' => 'ASC',
				'limit' => self::DOLIBARR_BATCH_SIZE,
				'page' => $page,
				'mode' => 1,
				'variant_filter' => $filter,
				'pagination_data' => 1,
				'includestockdata' => 1,
			)
		);
		if ( empty( $response['success'] ) ) {
			throw new RuntimeException( (string) ( $response['message'] ?? __( 'No se pudo actualizar la caché desde Dolibarr.', 'dolisync' ) ) );
		}
		$body = self::normalize_array( $response['data'] ?? array() );
		$items = self::normalize_list( $body );
		foreach ( $items as $item ) {
			$id = (int) ( $item['id'] ?? $item['rowid'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$data = self::normalize_dolibarr_product( $item, 2 === $filter ? 'variable' : 'simple' );
			if ( 2 === $filter ) {
				$existing = self::get_item( 'dolibarr', $id );
				if ( ! empty( $existing['variations'] ) ) {
					$data['variations'] = $existing['variations'];
				}
			}
			self::upsert( 'dolibarr', $id, $data, (string) $state['token'] );
		}

		$pagination = isset( $body['pagination'] ) && is_array( $body['pagination'] ) ? $body['pagination'] : array();
		$has_more = isset( $pagination['page_count'] ) ? $page + 1 < (int) $pagination['page_count'] : count( $items ) >= self::DOLIBARR_BATCH_SIZE;
		$page_ids = array_map( static function ( $item ) { return (int) ( $item['id'] ?? $item['rowid'] ?? 0 ); }, $items );
		$page_signature = hash( 'sha256', implode( ',', $page_ids ) );
		if ( $page > 0 && $has_more && hash_equals( (string) ( $state['last_page_signature'] ?? '' ), $page_signature ) ) {
			throw new RuntimeException( __( 'Dolibarr ha repetido la misma página de productos; se detiene el lote para evitar un bucle.', 'dolisync' ) );
		}
		if ( $has_more && $page + 1 >= self::MAX_DOLIBARR_PAGES ) {
			throw new RuntimeException( __( 'La paginación de productos de Dolibarr ha superado el límite de seguridad.', 'dolisync' ) );
		}
		$state['last_page_signature'] = $page_signature;
		if ( $has_more ) {
			$state['page'] = $page + 1;
		} elseif ( 1 === $filter ) {
			$state['filter'] = 2;
			$state['page'] = 0;
			unset( $state['last_page_signature'] );
		} else {
			$state['stage'] = 'dolibarr_details';
			$state['detail_cursor'] = 0;
			unset( $state['last_page_signature'], $state['detail_product'], $state['detail_combinations'], $state['detail_variations'], $state['detail_index'], $state['detail_failed'] );
		}
		return $state;
	}

	private static function refresh_dolibarr_details_batch( $state ) {
		global $wpdb;
		$table = self::table();
		$token = (string) $state['token'];
		$parent_id = (int) ( $state['detail_product'] ?? 0 );
		if ( $parent_id <= 0 ) {
			$parent_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT source_id FROM {$table} WHERE source = 'dolibarr' AND refresh_token = %s AND source_id > %d AND product_data LIKE %s ORDER BY source_id ASC LIMIT 1",
					$token,
					(int) ( $state['detail_cursor'] ?? 0 ),
					'%\"type\":\"variable\"%'
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $parent_id <= 0 ) {
				$state['complete'] = true;
				return $state;
			}

			require_once dirname( __DIR__ ) . '/api/class-dolisync-api-client.php';
			$response = self::api_get( new Dolisync_API_Client(), '/products/' . $parent_id . '/variants', array( 'includestock' => 1 ) );
			if ( empty( $response['success'] ) ) {
				$message = (string) ( $response['message'] ?? sprintf( __( 'No se pudieron leer las variantes de Dolibarr %d.', 'dolisync' ), $parent_id ) );
				$parent = self::get_item( 'dolibarr', $parent_id );
				if ( ! empty( $parent ) ) {
					$parent['variations_cache_complete'] = false;
					self::upsert( 'dolibarr', $parent_id, $parent, $token );
				}
				$state = self::add_warning( $state, $message );
				$state['detail_cursor'] = $parent_id;
				return $state;
			}
			$combinations = self::normalize_list( $response['data'] ?? array() );
			$parent = self::get_item( 'dolibarr', $parent_id );
			if ( empty( $combinations ) ) {
				if ( ! empty( $parent ) ) {
					// Una respuesta vacía no debe borrar variantes conocidas.
					$parent['variations_cache_complete'] = false;
					self::upsert( 'dolibarr', $parent_id, $parent, $token );
				}
				$state = self::add_warning( $state, sprintf( __( 'Dolibarr no devolvió combinaciones para el producto variable %d.', 'dolisync' ), $parent_id ) );
				$state['detail_cursor'] = $parent_id;
				return $state;
			}
			$state['detail_product'] = $parent_id;
			$state['detail_combinations'] = $combinations;
			$state['detail_variations'] = self::build_cached_variations( $parent_id, $parent, $combinations, $parent['variations'] ?? array() );
			$state['detail_index'] = 0;
			$state['detail_failed'] = 0;
			if ( ! empty( $parent ) ) {
				// Persistimos las combinaciones de inmediato. Los lotes siguientes
				// solo enriquecen sus datos y nunca vuelven a ocultarlas.
				$parent['variations'] = array_values( $state['detail_variations'] );
				$parent['variations_cache_complete'] = false;
				self::upsert( 'dolibarr', $parent_id, $parent, $token );
			}
		}

		$combinations = (array) ( $state['detail_combinations'] ?? array() );
		$index = max( 0, (int) ( $state['detail_index'] ?? 0 ) );
		$parent = self::get_item( 'dolibarr', $parent_id );
		$variations = self::build_cached_variations( $parent_id, $parent, $combinations, $state['detail_variations'] ?? array() );
		$failed = max( 0, (int) ( $state['detail_failed'] ?? 0 ) );
		if ( empty( $combinations ) ) {
			if ( ! empty( $parent ) ) {
				$parent['variations_cache_complete'] = false;
				self::upsert( 'dolibarr', $parent_id, $parent, $token );
			}
			$state = self::add_warning( $state, sprintf( __( 'No hay combinaciones utilizables en caché para el producto variable %d.', 'dolisync' ), $parent_id ) );
			$state['detail_cursor'] = $parent_id;
			unset( $state['detail_product'], $state['detail_combinations'], $state['detail_variations'], $state['detail_index'], $state['detail_failed'] );
			return $state;
		}
		$client = null;
		$calls = 0;
		$batch_started = microtime( true );
		while ( $index < count( $combinations ) && $calls < self::CHILD_CALLS_PER_BATCH && ( 0 === $calls || microtime( true ) - $batch_started < 8 ) ) {
			$combination = self::normalize_array( $combinations[ $index ] );
			$child_id = (int) ( $combination['fk_product_child'] ?? $combination['product_child_id'] ?? 0 );
			$index++;
			if ( $child_id <= 0 ) {
				$failed++;
				$state = self::add_warning( $state, sprintf( __( 'Dolibarr devolvió una combinación sin producto hijo para el producto %d.', 'dolisync' ), $parent_id ) );
				continue;
			}
			if ( null === $client ) {
				require_once dirname( __DIR__ ) . '/api/class-dolisync-api-client.php';
				$client = new Dolisync_API_Client();
			}
			$response = self::api_get( $client, '/products/' . $child_id, array( 'includestockdata' => 1, 'includeparentid' => 1 ) );
			$calls++;
			if ( empty( $response['success'] ) ) {
				$failed++;
				$state = self::add_warning( $state, (string) ( $response['message'] ?? sprintf( __( 'No se pudo completar el detalle de la variante Dolibarr %d.', 'dolisync' ), $child_id ) ) );
				continue;
			}
			$child = self::normalize_array( $response['data'] ?? array() );
			if ( isset( $child['data'] ) && is_array( $child['data'] ) ) {
				$child = $child['data'];
			}
			$variations = self::replace_cached_variation(
				$variations,
				self::normalize_dolibarr_variation( $parent_id, $child_id, $child, $combination, $parent )
			);
		}

		$state['detail_index'] = $index;
		$state['detail_variations'] = $variations;
		$state['detail_failed'] = $failed;
		if ( ! empty( $parent ) ) {
			$parent['variations'] = array_values( $variations );
			$parent['variations_cache_complete'] = $index >= count( $combinations ) && 0 === $failed;
			self::upsert( 'dolibarr', $parent_id, $parent, $token );
		}
		if ( $index >= count( $combinations ) ) {
			$state['detail_cursor'] = $parent_id;
			unset( $state['detail_product'], $state['detail_combinations'], $state['detail_variations'], $state['detail_index'], $state['detail_failed'] );
		}
		return $state;
	}

	/**
	 * Crea una entrada por cada combinación antes de consultar sus productos hijo.
	 * Así un fallo de detalle nunca convierte un producto variable en uno vacío.
	 */
	private static function build_cached_variations( $parent_id, $parent, $combinations, $previous = array() ) {
		$known = array();
		foreach ( (array) ( $parent['variations'] ?? array() ) as $variation ) {
			$id = (int) ( $variation['id'] ?? 0 );
			if ( $id > 0 ) {
				$known[ $id ] = $variation;
			}
		}
		foreach ( (array) $previous as $variation ) {
			$id = (int) ( $variation['id'] ?? 0 );
			if ( $id > 0 ) {
				$known[ $id ] = $variation;
			}
		}

		$result = array();
		foreach ( (array) $combinations as $combination ) {
			$combination = self::normalize_array( $combination );
			$child_id = (int) ( $combination['fk_product_child'] ?? $combination['product_child_id'] ?? 0 );
			if ( $child_id <= 0 ) {
				continue;
			}
			$placeholder = self::normalize_dolibarr_variation( $parent_id, $child_id, $combination, $combination, $parent );
			if ( isset( $known[ $child_id ] ) ) {
				$placeholder = self::merge_cached_variation( $placeholder, $known[ $child_id ] );
			}
			$result[] = $placeholder;
		}
		return $result;
	}

	/** Conserva datos completos previos que el listado de combinaciones no contiene. */
	private static function merge_cached_variation( $placeholder, $known ) {
		// El producto hijo es la fuente más completa para identidad, precio y stock.
		foreach ( array( 'sku', 'effective_sku', 'sku_generated', 'name', 'price', 'stock' ) as $key ) {
			if ( array_key_exists( $key, $known ) && null !== $known[ $key ] && '' !== $known[ $key ] ) {
				$placeholder[ $key ] = $known[ $key ];
			}
		}
		if ( empty( $placeholder['attributes'] ) && ! empty( $known['attributes'] ) ) {
			$placeholder['attributes'] = $known['attributes'];
		}
		if ( ! empty( $placeholder['sku'] ) ) {
			$placeholder['effective_sku'] = $placeholder['sku'];
			$placeholder['sku_generated'] = false;
		}
		return $placeholder;
	}

	private static function replace_cached_variation( $variations, $replacement ) {
		$id = (int) ( $replacement['id'] ?? 0 );
		foreach ( (array) $variations as $index => $variation ) {
			if ( (int) ( $variation['id'] ?? 0 ) === $id ) {
				$variations[ $index ] = $replacement;
				return array_values( $variations );
			}
		}
		$variations[] = $replacement;
		return array_values( $variations );
	}

	private static function add_warning( $state, $message ) {
		$state['warning_count'] = max( 0, (int) ( $state['warning_count'] ?? 0 ) ) + 1;
		$state['last_warning'] = sanitize_text_field( (string) $message );
		return $state;
	}

	private static function normalize_dolibarr_variation( $parent_id, $child_id, $child, $combination, $parent = array() ) {
		global $wpdb;
		$relation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT sku, price, stock_qty, attributes_json, wc_product_id, wc_variation_id FROM {$wpdb->prefix}dolisync_product_variation_relations WHERE dolibarr_product_id = %d AND dolibarr_variation_id = %d LIMIT 1",
				$parent_id,
				$child_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$attributes = self::combination_attributes( $combination );
		if ( empty( $attributes ) && is_array( $relation ) ) {
			$stored = json_decode( (string) ( $relation['attributes_json'] ?? '' ), true );
			$attributes = is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
		}
		if ( ! isset( $child['tva_tx'] ) && isset( $parent['tax_rate'] ) ) {
			$child['tva_tx'] = $parent['tax_rate'];
		}
		if ( empty( $child['price_base_type'] ) && ! empty( $parent['price_base_type'] ) ) {
			$child['price_base_type'] = $parent['price_base_type'];
		}
		$sku = trim( (string) ( $child['ref'] ?? $child['sku'] ?? $relation['sku'] ?? '' ) );
		$stock = self::dolibarr_stock( $child );
		$price = self::dolibarr_price_excluding_tax( $child );
		$stored_price = self::stored_variation_price_excluding_tax( $relation );
		$stored_raw_price = self::decimal( $relation['price'] ?? '' );
		if ( '' !== $stored_price && ( '' === $price || ( $price === $stored_raw_price && $stored_price !== $stored_raw_price ) ) ) {
			$price = $stored_price;
		}
		return array(
			'id' => $child_id,
			'sku' => $sku,
			'effective_sku' => '' !== $sku ? $sku : '#' . $child_id,
			'sku_generated' => '' === $sku,
			'name' => (string) ( $child['label'] ?? $child['name'] ?? '' ),
			'price' => $price,
			'stock' => null !== $stock ? $stock : ( is_numeric( $relation['stock_qty'] ?? null ) ? (float) $relation['stock_qty'] : null ),
			'attributes' => array_values( $attributes ),
		);
	}

	/** Actualiza inmediatamente una entrada local; no realiza trabajo remoto. */
	public static function refresh_woocommerce_item( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) ) {
			return;
		}
		self::upsert( 'woocommerce', (int) $product_id, self::serialize_woocommerce_product( $product ), 'live' );
	}

	public static function refresh_woocommerce_variation( $variation_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $variation_id ) : false;
		$parent_id = $product && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		if ( $parent_id > 0 ) {
			self::refresh_woocommerce_item( $parent_id );
		}
	}

	public static function delete_woocommerce_item( $product_id ) {
		self::delete_item( 'woocommerce', (int) $product_id );
	}

	public static function delete_woocommerce_variation( $variation_id ) {
		global $wpdb;
		$parent_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wc_product_id FROM {$wpdb->prefix}dolisync_product_variation_relations WHERE wc_variation_id = %d LIMIT 1", (int) $variation_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $parent_id > 0 ) {
			self::refresh_woocommerce_item( $parent_id );
		}
	}

	/** Refresca solo el resumen solicitado por el usuario y agenda el detalle. */
	public static function refresh_dolibarr_item( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return false;
		}
		require_once dirname( __DIR__ ) . '/api/class-dolisync-api-client.php';
		$response = self::api_get( new Dolisync_API_Client(), '/products/' . $product_id, array( 'includestockdata' => 1, 'includeparentid' => 1 ) );
		if ( empty( $response['success'] ) ) {
			return $response;
		}
		$item = self::normalize_array( $response['data'] ?? array() );
		if ( isset( $item['data'] ) && is_array( $item['data'] ) ) {
			$item = $item['data'];
		}
		$existing = self::get_item( 'dolibarr', $product_id );
		$type = (string) ( $existing['type'] ?? ( ! empty( $item['variants'] ) ? 'variable' : 'simple' ) );
		$data = self::normalize_dolibarr_product( $item, $type );
		if ( ! empty( $existing['variations'] ) ) {
			$data['variations'] = $existing['variations'];
		}
		self::upsert( 'dolibarr', $product_id, $data, 'live' );
		self::request_refresh( true );
		return array( 'success' => true, 'data' => $data );
	}

	/** Devuelve exclusivamente datos locales listos para comparar. */
	public static function get_products( $source ) {
		global $wpdb;
		$source = self::valid_source( $source );
		if ( '' === $source ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source_id, product_data FROM ' . self::table() . ' WHERE source = %s ORDER BY source_id ASC', $source ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = array();
		foreach ( (array) $rows as $row ) {
			$data = json_decode( (string) ( $row['product_data'] ?? '' ), true );
			if ( is_array( $data ) ) {
				$result[ (int) $row['source_id'] ] = $data;
			}
		}
		return $result;
	}

	public static function get_status() {
		global $wpdb;
		$status = get_option( self::STATUS_OPTION, array() );
		$status = is_array( $status ) ? $status : array();
		$state = get_option( self::STATE_OPTION, array() );
		$counts = $wpdb->get_results( 'SELECT source, COUNT(*) AS total, MAX(refreshed_at) AS refreshed_at FROM ' . self::table() . ' GROUP BY source', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$status['running'] = is_array( $state ) && ! empty( $state['token'] );
		$status['stage'] = is_array( $state ) ? (string) ( $state['stage'] ?? '' ) : '';
		if ( $status['running'] ) {
			$status['page'] = max( 0, (int) ( $state['page'] ?? 0 ) );
			$status['filter'] = max( 0, (int) ( $state['filter'] ?? 0 ) );
			$status['detail_cursor'] = max( 0, (int) ( $state['detail_cursor'] ?? 0 ) );
			$status['updated_timestamp'] = max( 0, (int) ( $state['updated_at'] ?? 0 ) );
			$status['retry_after'] = max( 0, (int) ( $state['retry_after'] ?? 0 ) );
			$status['paused_reason'] = (string) ( $state['paused_reason'] ?? '' );
			$worker_lock = max( 0, (int) get_option( self::LOCK_OPTION, 0 ) );
			$status['worker_locked'] = $worker_lock > 0 && time() - $worker_lock < self::STALE_LOCK_AFTER;
			$status['worker_lock_age'] = $worker_lock > 0 ? max( 0, time() - $worker_lock ) : 0;
			$status['wp_cron_disabled'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
			$status['warning_count'] = max( 0, (int) ( $state['warning_count'] ?? 0 ) );
			$status['last_warning'] = (string) ( $state['last_warning'] ?? '' );
		}
		$status['counts'] = array();
		foreach ( (array) $counts as $count ) {
			$status['counts'][ (string) $count['source'] ] = array( 'total' => (int) $count['total'], 'refreshed_at' => (string) $count['refreshed_at'] );
		}
		return $status;
	}

	/** Actualiza el stock ya obtenido por el sincronizador sin repetir la API. */
	public static function update_dolibarr_stock( $product_id, $stock, $parent_id = 0 ) {
		$product_id = (int) $product_id;
		$parent_id = (int) $parent_id;
		if ( $product_id <= 0 || ! is_numeric( $stock ) ) {
			return false;
		}
		$cache_id = $parent_id > 0 ? $parent_id : $product_id;
		$product = self::get_item( 'dolibarr', $cache_id );
		if ( empty( $product ) ) {
			return false;
		}
		if ( $parent_id > 0 ) {
			foreach ( (array) ( $product['variations'] ?? array() ) as &$variation ) {
				if ( (int) ( $variation['id'] ?? 0 ) === $product_id ) {
					$variation['stock'] = (float) $stock;
					break;
				}
			}
			unset( $variation );
		} else {
			$product['stock'] = (float) $stock;
		}
		return self::upsert( 'dolibarr', $cache_id, $product, 'live' );
	}

	private static function serialize_woocommerce_product( $product ) {
		$product_sku = trim( (string) $product->get_sku() );
		$product_reference = '' !== $product_sku ? $product_sku : 'WC-' . (int) $product->get_id();
		$parent_attribute_order = array();
		foreach ( (array) $product->get_attributes() as $attribute_key => $attribute ) {
			$key = sanitize_title( preg_replace( '/^attribute_/', '', (string) $attribute_key ) );
			if ( '' !== $key ) {
				$parent_attribute_order[] = $key;
			}
		}
		$variations = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( (array) $product->get_children() as $variation_id ) {
				$variation = wc_get_product( (int) $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$variation_id = (int) $variation->get_id();
				$variation_sku = trim( (string) $variation->get_sku( 'edit' ) );
				$raw_attributes = array();
				foreach ( (array) $variation->get_attributes() as $attribute_key => $attribute_value ) {
					$key = sanitize_title( preg_replace( '/^attribute_/', '', (string) $attribute_key ) );
					if ( '' !== $key && '' !== (string) $attribute_value ) {
						$raw_attributes[ $key ] = (string) $attribute_value;
					}
				}
				$ordered = array();
				foreach ( $parent_attribute_order as $key ) {
					if ( isset( $raw_attributes[ $key ] ) ) {
						$ordered[ $key ] = $raw_attributes[ $key ];
						unset( $raw_attributes[ $key ] );
					}
				}
				$attributes = array_merge( $ordered, $raw_attributes );
				if ( Dolisync_Product_Variation_Reference::is_generated( $variation_sku, $product_reference, $attributes, $variation_id ) ) {
					$variation_sku = '';
				}
				$variations[] = array(
					'id' => $variation_id,
					'sku' => $variation_sku,
					'effective_sku' => '' !== $variation_sku ? $variation_sku : Dolisync_Product_Variation_Reference::build( $product_reference, $attributes, $variation_id ),
					'sku_generated' => '' === $variation_sku,
					'name' => (string) $variation->get_name(),
					'price' => self::woocommerce_price_excluding_tax( $variation ),
					'stock' => $variation->get_stock_quantity(),
					'attributes' => array_values( $attributes ),
				);
			}
		}
		return array(
			'id' => (int) $product->get_id(),
			'sku' => $product_sku,
			'effective_sku' => $product_reference,
			'sku_generated' => '' === $product_sku,
			'name' => (string) $product->get_name(),
			'price' => self::woocommerce_price_excluding_tax( $product ),
			'stock' => $product->get_stock_quantity(),
			'status' => (string) $product->get_status(),
			'type' => (string) $product->get_type(),
			'edit_url' => get_edit_post_link( $product->get_id(), 'raw' ),
			'variations' => $variations,
		);
	}

	private static function normalize_dolibarr_product( $item, $type ) {
		$id = (int) ( $item['id'] ?? $item['rowid'] ?? 0 );
		return array(
			'id' => $id,
			'sku' => (string) ( $item['ref'] ?? $item['sku'] ?? '' ),
			'name' => (string) ( $item['label'] ?? $item['name'] ?? '' ),
			'price' => self::dolibarr_price_excluding_tax( $item ),
			'stock' => self::dolibarr_stock( $item ),
			'status' => ! empty( $item['status_buy'] ) || ! empty( $item['status'] ) ? 'active' : 'inactive',
			'type' => $type,
			'price_base_type' => strtoupper( (string) ( $item['price_base_type'] ?? '' ) ),
			'tax_rate' => $item['tva_tx'] ?? $item['tax_rate'] ?? 0,
			'variations' => self::normalize_embedded_variations( $item['variants'] ?? $item['variations'] ?? array() ),
		);
	}

	private static function normalize_embedded_variations( $variations ) {
		$result = array();
		foreach ( self::normalize_list( $variations ) as $variation ) {
			$result[] = array(
				'id' => (int) ( $variation['fk_product_child'] ?? $variation['id'] ?? $variation['rowid'] ?? 0 ),
				'sku' => (string) ( $variation['ref'] ?? $variation['reference'] ?? $variation['sku'] ?? '' ),
				'name' => (string) ( $variation['label'] ?? $variation['name'] ?? '' ),
				'price' => self::dolibarr_price_excluding_tax( $variation ),
				'stock' => self::dolibarr_stock( $variation ),
				'attributes' => self::combination_attributes( $variation ),
			);
		}
		return $result;
	}

	private static function combination_attributes( $combination ) {
		$values = array();
		foreach ( (array) ( $combination['attributes'] ?? array() ) as $pair ) {
			if ( is_scalar( $pair ) ) {
				if ( '' !== trim( (string) $pair ) ) {
					$values[] = (string) $pair;
				}
				continue;
			}
			$pair = self::normalize_array( $pair );
			$value = $pair['value'] ?? $pair['attribute_value'] ?? $pair['label'] ?? '';
			if ( is_array( $value ) ) {
				$value = $value['value'] ?? $value['label'] ?? $value['ref'] ?? '';
			}
			if ( '' !== trim( (string) $value ) ) {
				$values[] = (string) $value;
			}
		}
		return $values;
	}

	private static function woocommerce_price_excluding_tax( $product ) {
		$price = $product->get_price();
		if ( '' === (string) $price || ! is_numeric( $price ) ) {
			return '';
		}
		if ( function_exists( 'wc_get_price_excluding_tax' ) ) {
			$price = wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => (float) $price ) );
		}
		return self::decimal( $price );
	}

	/** Compatibilidad con relaciones antiguas que guardaban el precio Woo con IVA. */
	private static function stored_variation_price_excluding_tax( $relation ) {
		if ( ! is_array( $relation ) ) {
			return '';
		}
		$variation_id = (int) ( $relation['wc_variation_id'] ?? 0 );
		$variation = $variation_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : false;
		return $variation ? self::woocommerce_price_excluding_tax( $variation ) : self::decimal( $relation['price'] ?? '' );
	}

	private static function dolibarr_price_excluding_tax( $product ) {
		if ( isset( $product['price_ht'] ) && is_numeric( $product['price_ht'] ) ) {
			return self::decimal( $product['price_ht'] );
		}
		$base_type = strtoupper( trim( (string) ( $product['price_base_type'] ?? '' ) ) );
		$tax_rate = $product['tva_tx'] ?? $product['tax_rate'] ?? 0;
		$price = $product['price'] ?? '';
		$price_ttc = $product['price_ttc'] ?? '';
		if ( is_numeric( $price ) && is_numeric( $price_ttc ) && abs( (float) $price - (float) $price_ttc ) > 0.000001 ) {
			return self::decimal( $price );
		}
		$looks_like_ttc = is_numeric( $price ) && is_numeric( $price_ttc ) && abs( (float) $price - (float) $price_ttc ) <= 0.000001 && is_numeric( $tax_rate ) && (float) $tax_rate > 0;
		if ( 'TTC' === $base_type || ( '' === $base_type && $looks_like_ttc ) ) {
			$gross = is_numeric( $price_ttc ) ? (float) $price_ttc : ( is_numeric( $price ) ? (float) $price : null );
			if ( null === $gross ) {
				return '';
			}
			$rate = is_numeric( $tax_rate ) ? (float) $tax_rate : 0.0;
			return self::decimal( $rate > 0 ? $gross / ( 1 + $rate / 100 ) : $gross );
		}
		return self::decimal( is_numeric( $price ) ? $price : $price_ttc );
	}

	private static function dolibarr_stock( $data ) {
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}
		$warehouse_id = class_exists( 'Dolisync_Config' ) ? (int) Dolisync_Config::get_warehouse_id() : 0;
		$stocks = ! empty( $data['stock_warehouse'] ) && is_array( $data['stock_warehouse'] ) ? $data['stock_warehouse'] : array();
		if ( $warehouse_id > 0 ) {
			if ( isset( $stocks[ $warehouse_id ] ) ) {
				$warehouse_stock = self::normalize_array( $stocks[ $warehouse_id ] );
				foreach ( array( 'real', 'reel', 'stock_reel' ) as $key ) {
					if ( isset( $warehouse_stock[ $key ] ) && is_numeric( $warehouse_stock[ $key ] ) ) {
						return (float) $warehouse_stock[ $key ];
					}
				}
			}
			foreach ( $stocks as $stock ) {
				$stock = self::normalize_array( $stock );
				$id = (int) ( $stock['id'] ?? $stock['warehouse_id'] ?? $stock['fk_entrepot'] ?? 0 );
				if ( $id !== $warehouse_id ) {
					continue;
				}
				foreach ( array( 'real', 'reel', 'stock_reel' ) as $key ) {
					if ( isset( $stock[ $key ] ) && is_numeric( $stock[ $key ] ) ) {
						return (float) $stock[ $key ];
					}
				}
			}
			if ( ! empty( $stocks ) ) {
				return 0.0;
			}
		}
		foreach ( array( 'stock_reel', 'stock' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
				return (float) $data[ $key ];
			}
		}
		if ( ! empty( $stocks ) ) {
			$total = 0.0;
			$found = false;
			foreach ( $stocks as $stock ) {
				$stock = self::normalize_array( $stock );
				foreach ( array( 'real', 'reel', 'stock_reel' ) as $key ) {
					if ( isset( $stock[ $key ] ) && is_numeric( $stock[ $key ] ) ) {
						$total += (float) $stock[ $key ];
						$found = true;
						break;
					}
				}
			}
			if ( $found ) {
				return $total;
			}
		}
		return null;
	}

	private static function decimal( $value ) {
		if ( '' === (string) $value || null === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value, $decimals, false ) : number_format( (float) $value, $decimals, '.', '' );
	}

	private static function normalize_list( $value ) {
		$value = self::normalize_array( $value );
		if ( isset( $value['data'] ) && is_array( $value['data'] ) ) {
			$value = $value['data'];
		}
		if ( empty( $value ) ) {
			return array();
		}
		if ( isset( $value['id'] ) || isset( $value['rowid'] ) ) {
			return array( $value );
		}
		$result = array();
		foreach ( $value as $key => $item ) {
			$item = self::normalize_array( $item );
			if ( empty( $item ) ) {
				continue;
			}
			if ( is_numeric( $key ) && ! isset( $item['id'], $item['rowid'] ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				$item['id'] = (int) $key;
			}
			$result[] = $item;
		}
		return $result;
	}

	private static function normalize_array( $value ) {
		if ( is_object( $value ) ) {
			$value = json_decode( wp_json_encode( $value ), true );
		}
		return is_array( $value ) ? $value : array();
	}

	private static function api_get( $client, $endpoint, $params = array() ) {
		return method_exists( $client, 'get_for_cache' )
			? $client->get_for_cache( $endpoint, $params )
			: $client->get( $endpoint, $params );
	}

	private static function upsert( $source, $source_id, $data, $token ) {
		global $wpdb;
		$source = self::valid_source( $source );
		$source_id = (int) $source_id;
		if ( '' === $source || $source_id <= 0 || ! is_array( $data ) ) {
			return false;
		}
		// Una escritura disparada por hooks durante una reconstrucción pertenece a
		// la generación activa; de lo contrario la limpieza final podría borrarla.
		if ( 'live' === $token ) {
			$state = get_option( self::STATE_OPTION, array() );
			if ( is_array( $state ) && ! empty( $state['token'] ) ) {
				$token = (string) $state['token'];
			}
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$now = current_time( 'mysql' );
		return false !== $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (source, source_id, product_data, payload_hash, refresh_token, refreshed_at, created_at, updated_at) VALUES (%s, %d, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE product_data = VALUES(product_data), payload_hash = VALUES(payload_hash), refresh_token = VALUES(refresh_token), refreshed_at = VALUES(refreshed_at), updated_at = VALUES(updated_at)',
				$source,
				$source_id,
				$json,
				hash( 'sha256', $json ),
				(string) $token,
				$now,
				$now,
				$now
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function get_item( $source, $source_id ) {
		global $wpdb;
		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT product_data FROM ' . self::table() . ' WHERE source = %s AND source_id = %d LIMIT 1', self::valid_source( $source ), (int) $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}

	private static function delete_item( $source, $source_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'source' => self::valid_source( $source ), 'source_id' => (int) $source_id ), array( '%s', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function delete_old_generation( $source, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE source = %s AND refresh_token <> %s', self::valid_source( $source ), (string) $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function complete_refresh( $state ) {
		self::delete_old_generation( 'dolibarr', (string) $state['token'] );
		delete_option( self::STATE_OPTION );
		update_option(
			self::STATUS_OPTION,
			array(
				'state' => ! empty( $state['warning_count'] ) ? 'ready_with_warnings' : 'ready',
				'started_at' => ! empty( $state['started_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $state['started_at'] ) : '',
				'completed_at' => current_time( 'mysql' ),
				'completed_timestamp' => time(),
				'last_error' => '',
				'warning_count' => max( 0, (int) ( $state['warning_count'] ?? 0 ) ),
				'last_warning' => (string) ( $state['last_warning'] ?? '' ),
			),
			false
		);
		if ( ! empty( $state['refresh_again'] ) ) {
			self::request_refresh( true );
		}
	}

	private static function acquire_lock() {
		$current = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $current > 0 && time() - $current >= self::STALE_LOCK_AFTER ) {
			delete_option( self::LOCK_OPTION );
		}
		return add_option( self::LOCK_OPTION, time(), '', false );
	}

	/** Evita sumar lecturas masivas de caché a una sincronización activa. */
	private static function remote_sync_is_running() {
		foreach ( array( 'dolisync_lock_products_catalog', 'dolisync_stock_sync_lock' ) as $option ) {
			$value = (string) get_option( $option, '' );
			list( $run_id, $started_at ) = array_pad( explode( '|', $value, 3 ), 2, '' );
			if ( '' !== $run_id && (int) $started_at > 0 && time() - (int) $started_at < 20 * MINUTE_IN_SECONDS ) {
				return true;
			}
		}
		return false;
	}

	private static function schedule_batch( $delay, $expedite = false ) {
		$next = wp_next_scheduled( self::BATCH_HOOK );
		if ( $expedite && $next && $next > time() + 15 ) {
			wp_unschedule_event( $next, self::BATCH_HOOK );
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::BATCH_HOOK );
		}
	}

	private static function valid_source( $source ) {
		return in_array( (string) $source, array( 'woocommerce', 'dolibarr' ), true ) ? (string) $source : '';
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dolisync_product_catalog_cache';
	}
}
