<?php
/**
 * Catálogo comparativo WooCommerce ↔ Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Products_Page {
	const PAGE_SIZE = 20;
	const MAX_DOLIBARR_PAGES = 100;
	private static $ignored = array();

	public static function init() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		Dolisync_Schema::ensure_ignored_items_table();
		add_action( 'wp_ajax_dolisync_products_catalog', array( __CLASS__, 'ajax_catalog' ) );
		add_action( 'wp_ajax_dolisync_product_action', array( __CLASS__, 'ajax_product_action' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'dolisync' ) );
		}
		$nonce = wp_create_nonce( DOLISYNC_NONCE_ACTION );
		?>
		<div class="wrap dolisync-container dolisync-products-app">
			<div class="dolisync-products-hero">
				<div>
					<span class="dolisync-products-eyebrow"><?php echo esc_html__( 'Catálogo conectado', 'dolisync' ); ?></span>
					<h1><?php echo esc_html__( 'Productos', 'dolisync' ); ?></h1>
					<p><?php echo esc_html__( 'Compara WooCommerce y Dolibarr, revisa variaciones y sincroniza solo lo que necesites.', 'dolisync' ); ?></p>
				</div>
				<button type="button" class="button dolisync-catalog-reload"><span class="dashicons dashicons-update"></span> <?php echo esc_html__( 'Actualizar catálogo', 'dolisync' ); ?></button>
			</div>
			<section class="dolisync-page-actions" aria-labelledby="dolisync-products-sync-title">
				<div class="dolisync-page-actions-copy">
					<span class="dashicons dashicons-controls-repeat"></span>
					<div><h2 id="dolisync-products-sync-title"><?php echo esc_html__( 'Sincronización del catálogo', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Ejecuta una sincronización completa o actualiza únicamente stock y categorías.', 'dolisync' ); ?></p></div>
				</div>
				<div class="dolisync-page-actions-buttons">
					<button type="button" class="button button-primary" id="dolisync-sync-stock" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Sincronizar stock', 'dolisync' ); ?></button>
					<button type="button" class="button button-primary" id="dolisync-sync-products-dolibarr-to-woo" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Dolibarr → WooCommerce', 'dolisync' ); ?></button>
					<button type="button" class="button" id="dolisync-sync-products-woo-to-dolibarr" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'WooCommerce → Dolibarr', 'dolisync' ); ?></button>
					<button type="button" class="button" id="dolisync-sync-product-categories" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Sincronizar categorías', 'dolisync' ); ?></button>
				</div>
			</section>
			<div id="dolisync-product-sync-result" class="dolisync-page-action-result" aria-live="polite"></div>
			<div class="dolisync-products-toolbar">
				<label class="dolisync-products-search">
					<span class="dashicons dashicons-search"></span>
					<input type="search" id="dolisync-products-search" placeholder="<?php echo esc_attr__( 'Buscar por nombre, SKU o ID…', 'dolisync' ); ?>">
				</label>
				<label class="dolisync-products-filter">
					<span><?php echo esc_html__( 'Estado', 'dolisync' ); ?></span>
					<select id="dolisync-products-status-filter">
						<option value="all"><?php echo esc_html__( 'Todos', 'dolisync' ); ?></option>
						<option value="match"><?php echo esc_html__( 'Coincide', 'dolisync' ); ?></option>
						<option value="not_match"><?php echo esc_html__( 'No coincide', 'dolisync' ); ?></option>
						<option value="ignored"><?php echo esc_html__( 'Omitidos', 'dolisync' ); ?></option>
					</select>
				</label>
				<div id="dolisync-products-summary" class="dolisync-products-summary"></div>
			</div>
			<div id="dolisync-products-notice" aria-live="polite"></div>
			<div id="dolisync-products-table" class="dolisync-products-table-wrap">
				<div class="dolisync-products-loading"><span class="spinner is-active"></span><?php echo esc_html__( 'Leyendo ambos catálogos…', 'dolisync' ); ?></div>
			</div>
			<div id="dolisync-products-pagination" class="dolisync-products-pagination"></div>
		</div>
		<?php
	}

	public static function ajax_catalog() {
		self::guard_ajax();
		try {
			$rows = self::build_catalog();
			wp_send_json_success(
				array(
					'rows'    => $rows,
					'summary' => array(
						'total'     => count( $rows ),
						'linked'    => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['linked'] ); } ) ),
						'matching'  => count( array_filter( $rows, static function ( $row ) { return empty( $row['ignored'] ) && 'match' === $row['comparison']; } ) ),
						'unmatched' => count( array_filter( $rows, static function ( $row ) { return empty( $row['ignored'] ) && ( empty( $row['woo'] ) || empty( $row['dolibarr'] ) ); } ) ),
						'ignored'   => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['ignored'] ); } ) ),
					),
					'page_size' => self::PAGE_SIZE,
				)
			);
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public static function ajax_product_action() {
		self::guard_ajax();
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$wc_id = isset( $_POST['wc_id'] ) ? absint( wp_unslash( $_POST['wc_id'] ) ) : 0;
		$dolibarr_id = isset( $_POST['dolibarr_id'] ) ? absint( wp_unslash( $_POST['dolibarr_id'] ) ) : 0;

		try {
			if ( in_array( $operation, array( 'ignore', 'restore' ), true ) ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
				if ( ! Dolisync_Ignored_Items::set( 'product', $wc_id, $dolibarr_id, 'ignore' === $operation ) ) {
					throw new RuntimeException( __( 'No se pudo actualizar la omisión del producto.', 'dolisync' ) );
				}
				wp_send_json_success( array( 'message' => 'ignore' === $operation ? __( 'Producto omitido.', 'dolisync' ) : __( 'Producto restaurado.', 'dolisync' ) ) );
			}
			if ( 'refresh' === $operation ) {
				if ( ! $dolibarr_id ) {
					throw new InvalidArgumentException( __( 'Esta fila no tiene producto en Dolibarr.', 'dolisync' ) );
				}
				require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
				$response = ( new Dolisync_API_Client() )->get( '/products/' . $dolibarr_id, array( 'includestockdata' => 1, 'includeparentid' => 1 ) );
				if ( empty( $response['success'] ) ) {
					throw new RuntimeException( (string) ( $response['message'] ?? __( 'No se pudo obtener el producto.', 'dolisync' ) ) );
				}
				wp_send_json_success( array( 'message' => __( 'Información de Dolibarr actualizada.', 'dolisync' ), 'product' => self::normalize_array( $response['data'] ?? array() ) ) );
			}

			if ( 'woo_to_dolibarr' === $operation ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-sync-reverse.php';
				$result = ( new Dolisync_Product_Sync_Reverse() )->sync_product( $wc_id );
			} elseif ( 'dolibarr_to_woo' === $operation ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-sync.php';
				$result = ( new Dolisync_Product_Sync() )->sync_product( $dolibarr_id );
			} else {
				throw new InvalidArgumentException( __( 'Acción no válida.', 'dolisync' ) );
			}

			if ( empty( $result['success'] ) ) {
				wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'No se pudo sincronizar.', 'dolisync' ) ) ) );
			}
			wp_send_json_success( array( 'message' => $result['message'], 'stats' => $result['stats'] ?? array() ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	private static function guard_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'dolisync' ) ), 403 );
		}
		check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );
	}

	private static function build_catalog() {
		global $wpdb;
		$woo = self::get_woo_products();
		$dolibarr = self::get_dolibarr_products();
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		self::$ignored = Dolisync_Ignored_Items::get_map( 'product' );
		$relations = $wpdb->get_results( "SELECT dolibarr_product_id, wc_product_id, synced_at, last_sync_status FROM {$wpdb->prefix}dolisync_product_relations ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = array();
		$used_woo = array();
		$used_dolibarr = array();

		foreach ( (array) $relations as $relation ) {
			$wc_id = (int) $relation['wc_product_id'];
			$dolibarr_id = (int) $relation['dolibarr_product_id'];
			if ( ! isset( $woo[ $wc_id ] ) && ! isset( $dolibarr[ $dolibarr_id ] ) ) {
				continue;
			}
			$rows[] = self::make_row( $woo[ $wc_id ] ?? null, $dolibarr[ $dolibarr_id ] ?? null, true, $relation );
			$used_woo[ $wc_id ] = true;
			$used_dolibarr[ $dolibarr_id ] = true;
		}

		$dolibarr_by_sku = array();
		foreach ( $dolibarr as $id => $product ) {
			$sku = self::normalize_sku( $product['sku'] ?? '' );
			if ( ! isset( $used_dolibarr[ $id ] ) && '' !== $sku ) {
				$dolibarr_by_sku[ $sku ][] = $id;
			}
		}
		foreach ( $woo as $id => $product ) {
			if ( isset( $used_woo[ $id ] ) ) {
				continue;
			}
			$sku = self::normalize_sku( $product['effective_sku'] ?? $product['sku'] ?? '' );
			$candidate = ( '' !== $sku && 1 === count( $dolibarr_by_sku[ $sku ] ?? array() ) ) ? (int) $dolibarr_by_sku[ $sku ][0] : 0;
			if ( $candidate && ! isset( $used_dolibarr[ $candidate ] ) ) {
				$rows[] = self::make_row( $product, $dolibarr[ $candidate ], false );
				$used_dolibarr[ $candidate ] = true;
			} else {
				$rows[] = self::make_row( $product, null, false );
			}
			$used_woo[ $id ] = true;
		}
		foreach ( $dolibarr as $id => $product ) {
			if ( ! isset( $used_dolibarr[ $id ] ) ) {
				$rows[] = self::make_row( null, $product, false );
			}
		}

		usort( $rows, static function ( $a, $b ) {
			$a_alone = empty( $a['woo'] ) || empty( $a['dolibarr'] );
			$b_alone = empty( $b['woo'] ) || empty( $b['dolibarr'] );
			return $a_alone === $b_alone ? strnatcasecmp( (string) $a['search'], (string) $b['search'] ) : ( $a_alone ? 1 : -1 );
		} );
		return $rows;
	}

	private static function get_woo_products() {
		$result = array();
		$products = wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( (array) $products as $product ) {
			$variations = array();
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation ) {
						continue;
					}
					$variations[] = array(
						'id'         => (int) $variation->get_id(),
						'sku'        => (string) $variation->get_sku(),
						'effective_sku' => '' !== trim( (string) $variation->get_sku() ) ? (string) $variation->get_sku() : 'WC-VAR-' . (int) $variation->get_id(),
						'sku_generated' => '' === trim( (string) $variation->get_sku() ),
						'name'       => (string) $variation->get_name(),
						'price'      => self::woo_price_excluding_tax( $variation ),
						'stock'      => $variation->get_stock_quantity(),
						'attributes' => array_values( array_filter( array_map( 'strval', $variation->get_attributes() ) ) ),
					);
				}
			}
			$result[ $product->get_id() ] = array(
				'id'         => (int) $product->get_id(),
				'sku'        => (string) $product->get_sku(),
				'effective_sku' => '' !== trim( (string) $product->get_sku() ) ? (string) $product->get_sku() : 'WC-' . (int) $product->get_id(),
				'sku_generated' => '' === trim( (string) $product->get_sku() ),
				'name'       => (string) $product->get_name(),
				'price'      => self::woo_price_excluding_tax( $product ),
				'stock'      => $product->get_stock_quantity(),
				'status'     => (string) $product->get_status(),
				'type'       => (string) $product->get_type(),
				'edit_url'   => get_edit_post_link( $product->get_id(), 'raw' ),
				'variations' => $variations,
			);
		}
		return $result;
	}

	private static function get_dolibarr_products() {
		global $wpdb;
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		$client = new Dolisync_API_Client();
		$result = array();
		foreach ( array( 1, 2 ) as $variant_filter ) {
			for ( $page = 0; $page < self::MAX_DOLIBARR_PAGES; $page++ ) {
				$response = $client->get( '/products', array( 'sortfield' => 't.rowid', 'sortorder' => 'ASC', 'limit' => 100, 'page' => $page, 'mode' => 1, 'variant_filter' => $variant_filter, 'pagination_data' => 1, 'includestockdata' => 1 ) );
				if ( empty( $response['success'] ) ) {
					throw new RuntimeException( (string) ( $response['message'] ?? __( 'No se pudo leer el catálogo de Dolibarr.', 'dolisync' ) ) );
				}
				$body = self::normalize_array( $response['data'] ?? array() );
				$items = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
				foreach ( $items as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$id = (int) ( $item['id'] ?? $item['rowid'] ?? 0 );
					if ( ! $id ) {
						continue;
					}
					$result[ $id ] = array(
						'id'         => $id,
						'sku'        => (string) ( $item['ref'] ?? $item['sku'] ?? '' ),
						'name'       => (string) ( $item['label'] ?? $item['name'] ?? '' ),
						'price'      => self::dolibarr_price_excluding_tax( $item ),
						'stock'      => $item['stock_reel'] ?? $item['stock'] ?? null,
						'status'     => ! empty( $item['status_buy'] ) || ! empty( $item['status'] ) ? 'active' : 'inactive',
						'type'       => 2 === $variant_filter ? 'variable' : 'simple',
						'price_base_type' => strtoupper( (string) ( $item['price_base_type'] ?? '' ) ),
						'tax_rate'   => $item['tva_tx'] ?? $item['tax_rate'] ?? 0,
						'variations' => self::normalize_dolibarr_variations( $item['variants'] ?? $item['variations'] ?? array() ),
					);
				}
				$pagination = isset( $body['pagination'] ) && is_array( $body['pagination'] ) ? $body['pagination'] : array();
				$has_more = isset( $pagination['page_count'] ) ? $page + 1 < (int) $pagination['page_count'] : count( $items ) >= 100;
				if ( ! $has_more ) {
					break;
				}
			}
		}

		$variation_table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$variation_rows = $wpdb->get_results( "SELECT dolibarr_product_id, dolibarr_variation_id, wc_variation_id, sku, price, stock_qty, attributes_json FROM {$variation_table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$use_stored_variations = array();
		foreach ( (array) $variation_rows as $variation ) {
			$parent_id = (int) ( $variation['dolibarr_product_id'] ?? 0 );
			if ( isset( $result[ $parent_id ] ) && ! isset( $use_stored_variations[ $parent_id ] ) ) {
				// La lista puede traer combinaciones parciales (ID 0 y sin SKU).
				// Las relaciones guardan los IDs reales de los productos hijo.
				$result[ $parent_id ]['variations'] = array();
				$use_stored_variations[ $parent_id ] = true;
			}
		}
		foreach ( (array) $variation_rows as $variation ) {
			$parent_id = (int) $variation['dolibarr_product_id'];
			if ( isset( $result[ $parent_id ], $use_stored_variations[ $parent_id ] ) ) {
				$child_id = (int) $variation['dolibarr_variation_id'];
				$child = array();
				if ( $child_id > 0 ) {
					$child_response = $client->get( '/products/' . $child_id, array( 'includestockdata' => 1, 'includeparentid' => 1 ) );
					if ( ! empty( $child_response['success'] ) ) {
						$child = self::normalize_array( $child_response['data'] ?? array() );
						if ( isset( $child['data'] ) && is_array( $child['data'] ) ) {
							$child = $child['data'];
						}
						if ( ! isset( $child['tva_tx'] ) ) {
							$child['tva_tx'] = $result[ $parent_id ]['tax_rate'] ?? 0;
						}
						if ( empty( $child['price_base_type'] ) ) {
							$child['price_base_type'] = $result[ $parent_id ]['price_base_type'] ?? '';
						}
					}
				}
				$stored_price = self::stored_variation_price_excluding_tax( $variation );
				$display_price = ! empty( $child ) ? self::dolibarr_price_excluding_tax( $child ) : $stored_price;
				$stored_raw_price = self::decimal( $variation['price'] ?? '' );
				// Compatibilidad con hijos Dolibarr que no exponen ni base_type ni IVA:
				// si su valor coincide exactamente con el TTC histórico, usamos el HT
				// calculado a partir de la variación Woo vinculada.
				if ( '' !== $stored_price && $display_price === $stored_raw_price && $stored_price !== $stored_raw_price ) {
					$display_price = $stored_price;
				}

				$result[ $parent_id ]['variations'][] = array(
					'id' => $child_id,
					'sku' => (string) ( $child['ref'] ?? $child['sku'] ?? $variation['sku'] ),
					'effective_sku' => '' !== trim( (string) ( $child['ref'] ?? $child['sku'] ?? $variation['sku'] ) )
						? (string) ( $child['ref'] ?? $child['sku'] ?? $variation['sku'] )
						: 'WC-VAR-' . (int) $variation['wc_variation_id'],
					'sku_generated' => '' === trim( (string) ( $child['ref'] ?? $child['sku'] ?? $variation['sku'] ) ),
					'name' => (string) ( $child['label'] ?? $child['name'] ?? '' ),
					// Dolibarr expone `price`/`price_ht` como base imponible incluso si
					// el producto fue enviado originalmente con price_base_type=TTC.
					'price' => $display_price,
					'stock' => $child['stock_reel'] ?? $child['stock'] ?? $variation['stock_qty'],
					'attributes' => array_values( (array) json_decode( (string) $variation['attributes_json'], true ) ),
				);
			}
		}
		return $result;
	}

	private static function make_row( $woo, $dolibarr, $linked, $relation = array() ) {
		$comparison = self::compare_products( $woo, $dolibarr );
		$search = trim( implode( ' ', array_filter( array( $woo['name'] ?? '', $woo['sku'] ?? '', $woo['effective_sku'] ?? '', $woo['id'] ?? '', $dolibarr['name'] ?? '', $dolibarr['sku'] ?? '', $dolibarr['id'] ?? '' ) ) ) );
		$key = Dolisync_Ignored_Items::key( $woo['id'] ?? 0, $dolibarr['id'] ?? 0 );
		return array(
			'key' => 'w' . (int) ( $woo['id'] ?? 0 ) . '-d' . (int) ( $dolibarr['id'] ?? 0 ),
			'woo' => $woo, 'dolibarr' => $dolibarr, 'linked' => (bool) $linked,
			'comparison' => $comparison['status'], 'differences' => $comparison['differences'],
			'synced_at' => (string) ( $relation['synced_at'] ?? '' ), 'search' => self::normalize_key( $search ),
			'ignored' => isset( self::$ignored[ $key ] ), 'ignored_at' => (string) ( self::$ignored[ $key ] ?? '' ),
		);
	}

	private static function compare_products( $woo, $dolibarr ) {
		if ( empty( $woo ) || empty( $dolibarr ) ) {
			return array( 'status' => 'missing', 'differences' => array( __( 'Solo existe en una plataforma', 'dolisync' ) ) );
		}
		$differences = array();
		$woo_sku = $woo['effective_sku'] ?? $woo['sku'] ?? '';
		if ( self::normalize_sku( $woo_sku ) !== self::normalize_sku( $dolibarr['sku'] ) ) {
			$differences[] = 'SKU';
		}
		if ( self::normalize_product_name( $woo['name'] ) !== self::normalize_product_name( $dolibarr['name'] ) ) {
			$differences[] = __( 'nombre', 'dolisync' );
		}
		$precision = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$tolerance = pow( 10, -1 * max( 0, $precision ) ) / 2;
		if ( '' !== (string) $woo['price'] && '' !== (string) $dolibarr['price'] && abs( (float) $woo['price'] - (float) $dolibarr['price'] ) >= $tolerance ) {
			$differences[] = __( 'precio', 'dolisync' );
		}
		if ( self::variation_signatures( $woo['variations'] ) !== self::variation_signatures( $dolibarr['variations'] ) ) {
			$differences[] = __( 'variaciones', 'dolisync' );
		}
		return array( 'status' => empty( $differences ) ? 'match' : 'different', 'differences' => array_values( array_unique( $differences ) ) );
	}

	private static function decimal( $value ) {
		if ( '' === (string) $value || null === $value || ! is_numeric( $value ) ) {
			return '';
		}
		return wc_format_decimal( $value, wc_get_price_decimals(), false );
	}

	/**
	 * Obtiene la base imponible de un producto de Dolibarr. Algunas versiones
	 * devuelven `price` como TTC cuando price_base_type=TTC, especialmente en
	 * los productos hijo creados para combinaciones.
	 *
	 * @param array $product Producto devuelto por la API.
	 * @return string
	 */
	private static function dolibarr_price_excluding_tax( $product ) {
		if ( isset( $product['price_ht'] ) && is_numeric( $product['price_ht'] ) ) {
			return self::decimal( $product['price_ht'] );
		}

		$base_type = strtoupper( trim( (string) ( $product['price_base_type'] ?? '' ) ) );
		$tax_rate = $product['tva_tx'] ?? $product['tax_rate'] ?? 0;
		$price = $product['price'] ?? '';
		$price_ttc = $product['price_ttc'] ?? '';

		// Si Dolibarr proporciona claramente HT y TTC distintos, `price` es HT.
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
			return self::decimal( $rate > 0 ? $gross / ( 1 + ( $rate / 100 ) ) : $gross );
		}

		return self::decimal( is_numeric( $price ) ? $price : $price_ttc );
	}

	/**
	 * Convierte el precio almacenado en WooCommerce a base imponible (sin IVA),
	 * que es la misma base que devuelve `price`/`price_ht` en Dolibarr.
	 *
	 * @param WC_Product $product Producto o variación.
	 * @return string
	 */
	private static function woo_price_excluding_tax( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
			return '';
		}

		$price = $product->get_price();
		if ( '' === (string) $price || ! is_numeric( $price ) ) {
			return '';
		}

		if ( function_exists( 'wc_get_price_excluding_tax' ) ) {
			$price = wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => (float) $price ) );
		}

		return self::decimal( $price );
	}

	/**
	 * Las relaciones creadas por versiones anteriores guardaban el precio TTC
	 * original de WooCommerce. Lo convertimos al leer para no exigir una nueva
	 * sincronización ni una migración destructiva de datos.
	 */
	private static function stored_variation_price_excluding_tax( $variation ) {
		$wc_variation_id = (int) ( $variation['wc_variation_id'] ?? 0 );
		$wc_variation = $wc_variation_id > 0 ? wc_get_product( $wc_variation_id ) : false;
		if ( $wc_variation ) {
			return self::woo_price_excluding_tax( $wc_variation );
		}
		return self::decimal( $variation['price'] ?? '' );
	}

	private static function normalize_dolibarr_variations( $variations ) {
		$result = array();
		foreach ( (array) self::normalize_array( $variations ) as $variation ) {
			if ( ! is_array( $variation ) ) {
				continue;
			}
			$result[] = array(
				'id' => (int) ( $variation['fk_product_child'] ?? $variation['id'] ?? $variation['rowid'] ?? 0 ),
				'sku' => (string) ( $variation['ref'] ?? $variation['reference'] ?? $variation['sku'] ?? '' ),
				'name' => (string) ( $variation['label'] ?? $variation['name'] ?? '' ),
				'price' => self::dolibarr_price_excluding_tax( $variation ),
				'stock' => $variation['stock_reel'] ?? $variation['stock'] ?? null,
				'attributes' => array_values( array_filter( array_map( 'strval', (array) ( $variation['attributes'] ?? array() ) ) ) ),
			);
		}
		return $result;
	}

	private static function variation_signatures( $variations ) {
		$signatures = array();
		foreach ( (array) $variations as $variation ) {
			$attributes = array_map( array( __CLASS__, 'normalize_key' ), (array) ( $variation['attributes'] ?? array() ) );
			sort( $attributes, SORT_STRING );
			$signatures[] = implode( '|', array(
				self::normalize_sku( $variation['effective_sku'] ?? $variation['sku'] ?? '' ),
				self::decimal( $variation['price'] ?? '' ),
				implode( ',', $attributes ),
			) );
		}
		sort( $signatures, SORT_STRING );
		return $signatures;
	}

	private static function normalize_key( $value ) {
		$value = remove_accents( wp_strip_all_tags( (string) $value ) );
		return strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ) );
	}

	private static function normalize_sku( $value ) {
		$value = strtolower( remove_accents( trim( wp_strip_all_tags( (string) $value ) ) ) );
		return trim( preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
	}

	private static function normalize_product_name( $value ) {
		$value = remove_accents( wp_strip_all_tags( (string) $value ) );
		$value = str_replace( array( '"', "'", '“', '”', '‘', '’', '«', '»' ), '', $value );
		return strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ) );
	}

	private static function normalize_array( $value ) {
		if ( is_object( $value ) ) {
			$value = json_decode( wp_json_encode( $value ), true );
		}
		return is_array( $value ) ? $value : array();
	}
}

Dolisync_Products_Page::init();
