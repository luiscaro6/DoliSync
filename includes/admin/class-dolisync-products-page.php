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
		Dolisync_Schema::ensure_product_conflicts_table();
		Dolisync_Schema::ensure_product_category_mappings_table();
		Dolisync_Schema::ensure_product_catalog_cache_table();
		add_action( 'wp_ajax_dolisync_products_catalog', array( __CLASS__, 'ajax_catalog' ) );
		add_action( 'wp_ajax_dolisync_products_cache_status', array( __CLASS__, 'ajax_cache_status' ) );
		add_action( 'wp_ajax_dolisync_product_action', array( __CLASS__, 'ajax_product_action' ) );
		add_action( 'wp_ajax_dolisync_product_conflicts', array( __CLASS__, 'ajax_conflicts' ) );
		add_action( 'wp_ajax_dolisync_resolve_product_conflict', array( __CLASS__, 'ajax_resolve_conflict' ) );
		add_action( 'wp_ajax_dolisync_product_simulation', array( __CLASS__, 'ajax_simulation' ) );
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
			<nav class="nav-tab-wrapper dolisync-customers-tabs" aria-label="<?php echo esc_attr__( 'Secciones de productos', 'dolisync' ); ?>">
				<button type="button" class="nav-tab nav-tab-active" data-products-tab="catalog"><?php echo esc_html__( 'Catálogo', 'dolisync' ); ?></button>
				<button type="button" class="nav-tab" data-products-tab="conflicts"><?php echo esc_html__( 'Conflictos', 'dolisync' ); ?> <span id="dolisync-product-conflicts-count" class="dolisync-tab-count">0</span></button>
				<button type="button" class="nav-tab" data-products-tab="simulation-dolibarr" data-simulation-resource="products" data-simulation-direction="dolibarr_to_woocommerce"><?php echo esc_html__( 'Simulación Doli → Woo', 'dolisync' ); ?></button>
				<button type="button" class="nav-tab" data-products-tab="simulation-woo" data-simulation-resource="products" data-simulation-direction="woocommerce_to_dolibarr"><?php echo esc_html__( 'Simulación Woo → Doli', 'dolisync' ); ?></button>
			</nav>
			<div id="dolisync-products-catalog-panel" class="dolisync-products-panel">
			<section class="dolisync-page-actions" aria-labelledby="dolisync-products-sync-title">
				<div class="dolisync-page-actions-copy">
					<span class="dashicons dashicons-controls-repeat"></span>
					<div><h2 id="dolisync-products-sync-title"><?php echo esc_html__( 'Sincronización del catálogo', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Ejecuta una sincronización completa o actualiza únicamente stock y categorías.', 'dolisync' ); ?></p></div>
				</div>
				<div class="dolisync-page-actions-buttons">
					<button type="button" class="button button-primary" id="dolisync-sync-stock" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Sincronizar stock', 'dolisync' ); ?></button>
					<button type="button" class="button button-primary" id="dolisync-sync-products-dolibarr-to-woo" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Dolibarr → WooCommerce', 'dolisync' ); ?></button>
					<button type="button" class="button" id="dolisync-sync-products-woo-to-dolibarr" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'WooCommerce → Dolibarr', 'dolisync' ); ?></button>
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
			<div id="dolisync-products-conflicts-panel" class="dolisync-products-panel" hidden>
				<div class="dolisync-conflicts-heading"><div><h2><?php echo esc_html__( 'Conflictos de identidad de productos', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Compara ambos productos y elige qué sistema debe conservarse para reconstruir la relación.', 'dolisync' ); ?></p></div><button type="button" class="button dolisync-product-conflicts-reload"><span class="dashicons dashicons-update"></span><?php echo esc_html__( 'Actualizar', 'dolisync' ); ?></button></div>
				<div id="dolisync-product-conflicts-notice" aria-live="polite"></div>
				<div id="dolisync-product-conflicts-table" class="dolisync-products-table-wrap"><div class="dolisync-products-loading"><span class="spinner is-active"></span><?php echo esc_html__( 'Leyendo conflictos…', 'dolisync' ); ?></div></div>
			</div>
			<?php self::render_simulation_panel( 'simulation-dolibarr', __( 'Dolibarr → WooCommerce', 'dolisync' ), __( 'Revisa las altas y modificaciones que Dolibarr produciría en WooCommerce.', 'dolisync' ) ); ?>
			<?php self::render_simulation_panel( 'simulation-woo', __( 'WooCommerce → Dolibarr', 'dolisync' ), __( 'Revisa las altas y modificaciones que WooCommerce produciría en Dolibarr.', 'dolisync' ) ); ?>
		</div>
		<?php
	}

	private static function render_simulation_panel( $id, $title, $description ) {
		?>
		<div id="dolisync-products-<?php echo esc_attr( $id ); ?>-panel" class="dolisync-products-panel dolisync-simulation-panel" hidden>
			<div class="dolisync-conflicts-heading"><div><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $description ); ?></p></div><div class="dolisync-page-actions-buttons"><button type="button" class="button button-primary dolisync-run-simulation"><?php echo esc_html__( 'Simular', 'dolisync' ); ?></button><button type="button" class="button dolisync-apply-all-simulation" disabled><?php echo esc_html__( 'Enviar todos', 'dolisync' ); ?></button></div></div>
			<div class="dolisync-simulation-notice" aria-live="polite"></div><div class="dolisync-simulation-summary dolisync-products-summary"></div>
			<div class="dolisync-simulation-table dolisync-products-table-wrap"><div class="dolisync-products-zero"><span class="dashicons dashicons-search"></span><h2><?php echo esc_html__( 'Simulación pendiente', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Pulsa Simular para calcular los posibles cambios. No se modificará ningún dato.', 'dolisync' ); ?></p></div></div>
		</div>
		<?php
	}

	public static function ajax_simulation() {
		self::guard_ajax();
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
		if ( ! in_array( $direction, array( 'dolibarr_to_woocommerce', 'woocommerce_to_dolibarr' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Dirección no válida.', 'dolisync' ) ), 400 );
		}
		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/cache/class-dolisync-product-catalog-cache.php';
			Dolisync_Product_Catalog_Cache::request_refresh();
			$cache_status = Dolisync_Product_Catalog_Cache::get_status();
			if ( empty( $cache_status['completed_at'] ) ) {
				wp_send_json_error( array( 'message' => __( 'La primera carga de la caché de productos sigue en curso. La simulación se habilitará al completarse para evitar falsos productos pendientes.', 'dolisync' ), 'cache' => $cache_status ), 409 );
			}
			$items = array();
			$skipped_incomplete = 0;
			foreach ( self::build_catalog() as $row ) {
				if ( false === ( $row['dolibarr']['variations_cache_complete'] ?? null ) ) {
					$skipped_incomplete++;
					continue;
				}
				$source = 'dolibarr_to_woocommerce' === $direction ? $row['dolibarr'] : $row['woo'];
				$target = 'dolibarr_to_woocommerce' === $direction ? $row['woo'] : $row['dolibarr'];
				if ( empty( $source ) || ! empty( $row['ignored'] ) || 'match' === $row['comparison'] ) { continue; }
				$items[] = array(
					'key' => $row['key'], 'action' => empty( $target ) ? 'create' : 'update',
					'label' => (string) ( $source['name'] ?? __( 'Producto sin nombre', 'dolisync' ) ),
					'reference' => (string) ( $source['sku'] ?? '' ), 'differences' => $row['differences'],
					'wc_id' => (int) ( $row['woo']['id'] ?? 0 ), 'dolibarr_id' => (int) ( $row['dolibarr']['id'] ?? 0 ),
				);
			}
			wp_send_json_success( array( 'items' => $items, 'summary' => self::simulation_summary( $items, $skipped_incomplete ), 'cache' => $cache_status ) );
		} catch ( Throwable $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 500 ); }
	}

	private static function simulation_summary( $items, $skipped_incomplete = 0 ) {
		return array(
			'total' => count( $items ),
			'create' => count( array_filter( $items, static function ( $item ) { return 'create' === $item['action']; } ) ),
			'update' => count( array_filter( $items, static function ( $item ) { return 'update' === $item['action']; } ) ),
			'skipped_incomplete' => max( 0, (int) $skipped_incomplete ),
		);
	}

	public static function ajax_catalog() {
		self::guard_ajax();
		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/cache/class-dolisync-product-catalog-cache.php';
			$force_refresh = ! empty( $_POST['refresh'] );
			Dolisync_Product_Catalog_Cache::request_refresh( $force_refresh );
			if ( $force_refresh ) {
				Dolisync_Product_Catalog_Cache::run_batch();
			}
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
					'cache' => Dolisync_Product_Catalog_Cache::get_status(),
				)
			);
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/** Consulta ligera para mantener WP-Cron avanzando sin reconstruir el catálogo. */
	public static function ajax_cache_status() {
		self::guard_ajax();
		require_once DOLISYNC_PLUGIN_DIR . 'includes/cache/class-dolisync-product-catalog-cache.php';
		Dolisync_Product_Catalog_Cache::request_refresh();
		// Respaldo para instalaciones donde WP-Cron o sus loopbacks están
		// desactivados: una petición autenticada procesa un único lote acotado.
		Dolisync_Product_Catalog_Cache::run_batch();
		wp_send_json_success( array( 'cache' => Dolisync_Product_Catalog_Cache::get_status() ) );
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
				require_once DOLISYNC_PLUGIN_DIR . 'includes/cache/class-dolisync-product-catalog-cache.php';
				$response = Dolisync_Product_Catalog_Cache::refresh_dolibarr_item( $dolibarr_id );
				if ( empty( $response['success'] ) ) {
					throw new RuntimeException( (string) ( $response['message'] ?? __( 'No se pudo obtener el producto.', 'dolisync' ) ) );
				}
				wp_send_json_success( array( 'message' => __( 'Producto actualizado en caché; sus variaciones se completarán en segundo plano.', 'dolisync' ), 'product' => self::normalize_array( $response['data'] ?? array() ) ) );
			}

			require_once DOLISYNC_PLUGIN_DIR . 'includes/cache/class-dolisync-product-catalog-cache.php';
			if ( empty( Dolisync_Product_Catalog_Cache::get_status()['completed_at'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Espera a que termine la primera carga de la caché antes de sincronizar productos desde el catálogo.', 'dolisync' ) ), 409 );
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
			require_once DOLISYNC_PLUGIN_DIR . 'includes/cache/class-dolisync-product-catalog-cache.php';
			if ( $wc_id > 0 ) {
				Dolisync_Product_Catalog_Cache::refresh_woocommerce_item( $wc_id );
			}
			Dolisync_Product_Catalog_Cache::request_refresh( true );
			wp_send_json_success( array( 'message' => $result['message'], 'stats' => $result['stats'] ?? array() ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public static function ajax_conflicts() {
		self::guard_ajax();
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-conflicts.php';
		$rows = Dolisync_Product_Conflicts::get_open();
		wp_send_json_success( array( 'rows' => $rows, 'count' => count( $rows ) ) );
	}

	public static function ajax_categories_catalog() {
		self::guard_ajax();
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_category_mappings';
		$records = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY category_name ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = array();
		foreach ( (array) $records as $record ) {
			$wc_id = (int) ( $record['wc_category_id'] ?? 0 );
			$term = $wc_id > 0 ? get_term( $wc_id, 'product_cat' ) : null;
			$woo_exists = $term && ! is_wp_error( $term );
			$rows[] = array(
				'id' => (int) ( $record['id'] ?? 0 ),
				'name' => (string) ( $record['category_name'] ?? '' ),
				'dolibarr_id' => (int) ( $record['dolibarr_category_id'] ?? 0 ),
				'dolibarr_parent_id' => (int) ( $record['dolibarr_parent_category_id'] ?? 0 ),
				'wc_id' => $wc_id,
				'wc_parent_id' => (int) ( $record['wc_parent_category_id'] ?? 0 ),
				'wc_name' => $woo_exists ? (string) $term->name : (string) ( $record['category_name'] ?? '' ),
				'wc_slug' => $woo_exists ? (string) $term->slug : '',
				'woo_exists' => (bool) $woo_exists,
				'synced_at' => (string) ( $record['synced_at'] ?? '' ),
			);
		}
		wp_send_json_success( array( 'rows' => $rows, 'count' => count( $rows ) ) );
	}

	public static function ajax_categories_simulation() {
		self::guard_ajax();
		global $wpdb;
		$relations = $wpdb->get_results( "SELECT dolibarr_category_id, wc_category_id FROM {$wpdb->prefix}dolisync_product_category_mappings", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$mapped_woo = array();
		$mapped_dolibarr = array();
		foreach ( (array) $relations as $relation ) {
			$mapped_woo[ (int) $relation['wc_category_id'] ] = true;
			$mapped_dolibarr[ (int) $relation['dolibarr_category_id'] ] = true;
		}

		$items = array();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! isset( $mapped_woo[ (int) $term->term_id ] ) ) {
					$items[] = array( 'action' => 'create', 'direction' => 'woocommerce_to_dolibarr', 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'parent_id' => (int) $term->parent );
				}
			}
		}

		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		$client = new Dolisync_API_Client();
		for ( $page = 0; $page < self::MAX_DOLIBARR_PAGES; $page++ ) {
			$response = $client->get( '/categories', array( 'type' => 'product', 'limit' => 100, 'page' => $page, 'sortfield' => 't.rowid', 'sortorder' => 'ASC' ) );
			if ( empty( $response['success'] ) ) { throw new RuntimeException( (string) ( $response['message'] ?? __( 'No se pudieron leer las categorías de Dolibarr.', 'dolisync' ) ) ); }
			$body = self::normalize_array( $response['data'] ?? array() );
			$categories = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
			if ( ! isset( $categories[0] ) && ! empty( $categories ) ) { $categories = array( $categories ); }
			foreach ( $categories as $category ) {
				$id = (int) ( $category['id'] ?? $category['rowid'] ?? 0 );
				if ( $id > 0 && ! isset( $mapped_dolibarr[ $id ] ) ) {
					$items[] = array( 'action' => 'create', 'direction' => 'dolibarr_to_woocommerce', 'name' => (string) ( $category['label'] ?? $category['name'] ?? '' ), 'slug' => (string) ( $category['slug'] ?? '' ), 'parent_id' => (int) ( $category['fk_parent'] ?? $category['parent_id'] ?? 0 ) );
				}
			}
			if ( count( $categories ) < 100 ) { break; }
		}

		$token = wp_generate_uuid4();
		set_transient( 'dolisync_category_simulation_' . get_current_user_id(), hash( 'sha256', $token ), 15 * MINUTE_IN_SECONDS );
		wp_send_json_success( array( 'token' => $token, 'items' => $items, 'summary' => array( 'total' => count( $items ), 'to_woo' => count( array_filter( $items, static function ( $item ) { return 'dolibarr_to_woocommerce' === $item['direction']; } ) ), 'to_dolibarr' => count( array_filter( $items, static function ( $item ) { return 'woocommerce_to_dolibarr' === $item['direction']; } ) ) ) ) );
	}

	public static function ajax_resolve_conflict() {
		self::guard_ajax();
		$conflict_id = isset( $_POST['conflict_id'] ) ? absint( wp_unslash( $_POST['conflict_id'] ) ) : 0;
		$winner = isset( $_POST['winner'] ) ? sanitize_key( wp_unslash( $_POST['winner'] ) ) : '';
		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-conflicts.php';
			Dolisync_Product_Conflicts::resolve( $conflict_id, $winner );
			wp_send_json_success( array( 'message' => __( 'Conflicto resuelto y relación de producto reconstruida.', 'dolisync' ) ) );
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
		require_once DOLISYNC_PLUGIN_DIR . 'includes/cache/class-dolisync-product-catalog-cache.php';
		return Dolisync_Product_Catalog_Cache::get_products( 'woocommerce' );
	}

	private static function get_dolibarr_products() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/cache/class-dolisync-product-catalog-cache.php';
		return Dolisync_Product_Catalog_Cache::get_products( 'dolibarr' );
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
		if ( null !== $woo['stock'] && null !== $dolibarr['stock'] && abs( (float) $woo['stock'] - (float) $dolibarr['stock'] ) >= 0.000001 ) {
			$differences[] = __( 'stock', 'dolisync' );
		}
		if ( (string) ( $woo['type'] ?? '' ) !== (string) ( $dolibarr['type'] ?? '' ) ) {
			$differences[] = __( 'tipo', 'dolisync' );
		}
		if ( self::variation_signatures( $woo['variations'] ) !== self::variation_signatures( $dolibarr['variations'] ) ) {
			$differences[] = __( 'variaciones', 'dolisync' );
		} elseif ( self::explicit_variation_skus_differ( $woo['variations'], $dolibarr['variations'] ) ) {
			$differences[] = __( 'SKU de variaciones', 'dolisync' );
		}
		return array( 'status' => empty( $differences ) ? 'match' : 'different', 'differences' => array_values( array_unique( $differences ) ) );
	}

	private static function decimal( $value ) {
		if ( '' === (string) $value || null === $value || ! is_numeric( $value ) ) {
			return '';
		}
		return wc_format_decimal( $value, wc_get_price_decimals(), false );
	}

	private static function variation_signatures( $variations ) {
		$signatures = array();
		foreach ( (array) $variations as $variation ) {
			$attributes = array_map( array( __CLASS__, 'normalize_key' ), (array) ( $variation['attributes'] ?? array() ) );
			sort( $attributes, SORT_STRING );
			$signatures[] = implode( '|', array(
				self::decimal( $variation['price'] ?? '' ),
				is_numeric( $variation['stock'] ?? null ) ? (string) (float) $variation['stock'] : 'stock-desconocido',
				implode( ',', $attributes ),
			) );
		}
		sort( $signatures, SORT_STRING );
		return $signatures;
	}

	/**
	 * Compara SKU solo cuando WooCommerce tiene uno propio. Las referencias
	 * técnicas de Dolibarr (por ejemplo 80826_36) no son diferencias si la
	 * variación Woo hereda el SKU del padre y se corresponde por atributos.
	 */
	private static function explicit_variation_skus_differ( $woo_variations, $dolibarr_variations ) {
		$dolibarr_by_attributes = array();
		foreach ( (array) $dolibarr_variations as $variation ) {
			$key = self::variation_attribute_key( $variation );
			$dolibarr_by_attributes[ $key ][] = $variation;
		}

		foreach ( (array) $woo_variations as $variation ) {
			$sku = trim( (string) ( $variation['sku'] ?? '' ) );
			if ( '' === $sku || ! empty( $variation['sku_generated'] ) ) {
				continue;
			}
			$key = self::variation_attribute_key( $variation );
			if ( 1 !== count( $dolibarr_by_attributes[ $key ] ?? array() ) ) {
				continue;
			}
			$dolibarr_variation = $dolibarr_by_attributes[ $key ][0];
			if ( self::normalize_sku( $sku ) !== self::normalize_sku( $dolibarr_variation['sku'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function variation_attribute_key( $variation ) {
		$attributes = array_map( array( __CLASS__, 'normalize_key' ), (array) ( $variation['attributes'] ?? array() ) );
		sort( $attributes, SORT_STRING );
		return implode( ',', $attributes );
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
