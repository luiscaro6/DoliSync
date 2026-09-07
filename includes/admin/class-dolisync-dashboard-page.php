<?php
/**
 * Panel de inicio y estado operativo de DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Dashboard_Page {
	private const REMOTE_CACHE_KEY = 'dolisync_dashboard_remote_status';
	private const REMOTE_CACHE_TTL = 5 * MINUTE_IN_SECONDS;
	private static $table_exists = array();

	public static function init() {
		add_action( 'wp_ajax_dolisync_dashboard_data', array( __CLASS__, 'ajax_data' ) );
		add_action( 'wp_ajax_dolisync_dashboard_remote_status', array( __CLASS__, 'ajax_remote_status' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'dolisync' ) );
		}

		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$config = Dolisync_Config::get_all();
		$last_test = Dolisync_Config::get_last_connection_test();
		$endpoint = untrailingslashit( (string) ( $config['dolibarr_url'] ?? '' ) );
		$initial_status = (string) ( $last_test['status'] ?? 'pending' );
		if ( ! Dolisync_Config::is_configured() ) {
			$initial_status = 'unconfigured';
		}
		?>
		<div class="wrap dolisync-dashboard" data-connection-status="<?php echo esc_attr( $initial_status ); ?>">
			<header class="dolisync-dashboard-hero">
				<div class="dolisync-dashboard-orb dolisync-dashboard-orb-one" aria-hidden="true"></div>
				<div class="dolisync-dashboard-orb dolisync-dashboard-orb-two" aria-hidden="true"></div>
				<div class="dolisync-dashboard-hero-main">
					<div class="dolisync-dashboard-brand">
						<span class="dolisync-dashboard-mark" aria-hidden="true"><span class="dashicons dashicons-controls-repeat"></span></span>
						<div>
							<span class="dolisync-dashboard-eyebrow"><?php echo esc_html__( 'Centro de operaciones', 'dolisync' ); ?></span>
							<h1><?php echo esc_html__( 'DoliSync', 'dolisync' ); ?></h1>
							<p><?php echo esc_html__( 'WooCommerce y Dolibarr, visibles de un vistazo.', 'dolisync' ); ?></p>
						</div>
					</div>
					<div class="dolisync-dashboard-hero-actions">
						<button type="button" class="button dolisync-dashboard-refresh" id="dolisync-dashboard-refresh"><span class="dashicons dashicons-update" aria-hidden="true"></span><span><?php echo esc_html__( 'Actualizar panel', 'dolisync' ); ?></span></button>
						<a class="button dolisync-dashboard-settings" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings' ) ); ?>"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><span><?php echo esc_html__( 'Ajustes', 'dolisync' ); ?></span></a>
					</div>
				</div>

				<div class="dolisync-dashboard-connection-bar">
					<div class="dolisync-dashboard-connection-state">
						<span class="dolisync-dashboard-status-dot" aria-hidden="true"></span>
						<div><small><?php echo esc_html__( 'Estado de conexión', 'dolisync' ); ?></small><strong id="dolisync-dashboard-connection-label"><?php echo esc_html( self::connection_label( $initial_status ) ); ?></strong></div>
					</div>
					<div class="dolisync-dashboard-endpoint">
						<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
						<div><small><?php echo esc_html__( 'Endpoint', 'dolisync' ); ?></small><code data-dashboard-value="connection.endpoint"><?php echo esc_html( '' !== $endpoint ? $endpoint : __( 'Sin configurar', 'dolisync' ) ); ?></code></div>
					</div>
					<div class="dolisync-dashboard-hero-fact"><small><?php echo esc_html__( 'Dolibarr', 'dolisync' ); ?></small><strong data-dashboard-value="connection.dolibarr_version">—</strong></div>
					<div class="dolisync-dashboard-hero-fact"><small><?php echo esc_html__( 'DoliSync', 'dolisync' ); ?></small><strong>v<?php echo esc_html( DOLISYNC_VERSION ); ?></strong></div>
				</div>
			</header>

			<div id="dolisync-dashboard-notice" class="dolisync-dashboard-notice" aria-live="polite"></div>

			<section class="dolisync-dashboard-metrics" aria-label="<?php echo esc_attr__( 'Resumen del catálogo', 'dolisync' ); ?>">
				<a class="dolisync-dashboard-metric dolisync-dashboard-metric-products" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_products' ) ); ?>">
					<span class="dolisync-dashboard-metric-icon"><span class="dashicons dashicons-products" aria-hidden="true"></span></span>
					<span class="dolisync-dashboard-metric-copy"><small><?php echo esc_html__( 'Productos', 'dolisync' ); ?></small><strong data-dashboard-value="catalog.woo_products">—</strong><span><b data-dashboard-value="catalog.synced_products">—</b> <?php echo esc_html__( 'sincronizados', 'dolisync' ); ?></span></span>
					<span class="dashicons dashicons-arrow-right-alt2 dolisync-dashboard-metric-arrow" aria-hidden="true"></span>
					<span class="dolisync-dashboard-meter"><i data-dashboard-progress="catalog.products_coverage"></i></span>
				</a>
				<a class="dolisync-dashboard-metric dolisync-dashboard-metric-variations" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_products' ) ); ?>">
					<span class="dolisync-dashboard-metric-icon"><span class="dashicons dashicons-networking" aria-hidden="true"></span></span>
					<span class="dolisync-dashboard-metric-copy"><small><?php echo esc_html__( 'Variantes', 'dolisync' ); ?></small><strong data-dashboard-value="catalog.woo_variations">—</strong><span><b data-dashboard-value="catalog.synced_variations">—</b> <?php echo esc_html__( 'relacionadas', 'dolisync' ); ?></span></span>
					<span class="dashicons dashicons-arrow-right-alt2 dolisync-dashboard-metric-arrow" aria-hidden="true"></span>
					<span class="dolisync-dashboard-meter"><i data-dashboard-progress="catalog.variations_coverage"></i></span>
				</a>
				<a class="dolisync-dashboard-metric dolisync-dashboard-metric-customers" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_customers' ) ); ?>">
					<span class="dolisync-dashboard-metric-icon"><span class="dashicons dashicons-groups" aria-hidden="true"></span></span>
					<span class="dolisync-dashboard-metric-copy"><small><?php echo esc_html__( 'Clientes registrados', 'dolisync' ); ?></small><strong data-dashboard-value="customers.woo_customers">—</strong><span><b data-dashboard-value="customers.synced_customers">—</b> <?php echo esc_html__( 'sincronizados', 'dolisync' ); ?></span></span>
					<span class="dashicons dashicons-arrow-right-alt2 dolisync-dashboard-metric-arrow" aria-hidden="true"></span>
					<span class="dolisync-dashboard-meter"><i data-dashboard-progress="customers.coverage"></i></span>
				</a>
				<a class="dolisync-dashboard-metric dolisync-dashboard-metric-orders" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_orders' ) ); ?>">
					<span class="dolisync-dashboard-metric-icon"><span class="dashicons dashicons-cart" aria-hidden="true"></span></span>
					<span class="dolisync-dashboard-metric-copy"><small><?php echo esc_html__( 'Pedidos', 'dolisync' ); ?></small><strong data-dashboard-value="orders.woo_orders">—</strong><span><b data-dashboard-value="orders.synced_invoices">—</b> <?php echo esc_html__( 'con factura Dolibarr', 'dolisync' ); ?></span></span>
					<span class="dashicons dashicons-arrow-right-alt2 dolisync-dashboard-metric-arrow" aria-hidden="true"></span>
					<span class="dolisync-dashboard-meter"><i data-dashboard-progress="orders.coverage"></i></span>
				</a>
			</section>

			<div class="dolisync-dashboard-main-grid">
				<section class="dolisync-dashboard-card dolisync-dashboard-stock-card">
					<header class="dolisync-dashboard-card-header">
						<div><span class="dolisync-dashboard-section-icon dolisync-dashboard-section-icon-stock"><span class="dashicons dashicons-database" aria-hidden="true"></span></span><div><span class="dolisync-dashboard-card-kicker"><?php echo esc_html__( 'Inventario', 'dolisync' ); ?></span><h2><?php echo esc_html__( 'Sincronización de stock', 'dolisync' ); ?></h2></div></div>
						<span class="dolisync-dashboard-pill" id="dolisync-dashboard-stock-status"><?php echo esc_html__( 'Cargando', 'dolisync' ); ?></span>
					</header>
					<div class="dolisync-dashboard-stock-highlight">
						<div><small><?php echo esc_html__( 'Última ejecución', 'dolisync' ); ?></small><strong data-dashboard-value="stock.last_run_display">—</strong><span data-dashboard-value="stock.last_run_relative"><?php echo esc_html__( 'Consultando actividad…', 'dolisync' ); ?></span></div>
						<span class="dolisync-dashboard-stock-pulse" aria-hidden="true"><i></i><span class="dashicons dashicons-update"></span></span>
					</div>
					<div class="dolisync-dashboard-detail-grid">
						<div><small><?php echo esc_html__( 'Frecuencia', 'dolisync' ); ?></small><strong data-dashboard-value="stock.interval_label">—</strong></div>
						<div><small><?php echo esc_html__( 'Próxima ejecución', 'dolisync' ); ?></small><strong data-dashboard-value="stock.next_run_display">—</strong></div>
						<div><small><?php echo esc_html__( 'Almacén', 'dolisync' ); ?></small><strong data-dashboard-value="system.warehouse">—</strong></div>
					</div>
					<p class="dolisync-dashboard-card-note" data-dashboard-value="stock.last_description"><?php echo esc_html__( 'Cargando el último resultado de stock…', 'dolisync' ); ?></p>
					<footer><a href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_products' ) ); ?>"><?php echo esc_html__( 'Abrir control de productos', 'dolisync' ); ?> <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span></a></footer>
				</section>

				<section class="dolisync-dashboard-card dolisync-dashboard-health-card">
					<header class="dolisync-dashboard-card-header">
						<div><span class="dolisync-dashboard-section-icon dolisync-dashboard-section-icon-health"><span class="dashicons dashicons-heart" aria-hidden="true"></span></span><div><span class="dolisync-dashboard-card-kicker"><?php echo esc_html__( 'Operación', 'dolisync' ); ?></span><h2><?php echo esc_html__( 'Estado del sistema', 'dolisync' ); ?></h2></div></div>
						<span class="dolisync-dashboard-pill" id="dolisync-dashboard-health-status"><?php echo esc_html__( 'Analizando', 'dolisync' ); ?></span>
					</header>
					<div class="dolisync-dashboard-health-grid">
						<div><span class="dashicons dashicons-warning" aria-hidden="true"></span><strong data-dashboard-value="health.errors_24h">—</strong><small><?php echo esc_html__( 'Errores en 24 h', 'dolisync' ); ?></small></div>
						<div><span class="dashicons dashicons-clock" aria-hidden="true"></span><strong data-dashboard-value="orders.pending">—</strong><small><?php echo esc_html__( 'Pedidos pendientes', 'dolisync' ); ?></small></div>
						<div><span class="dashicons dashicons-shield" aria-hidden="true"></span><strong data-dashboard-value="health.open_conflicts">—</strong><small><?php echo esc_html__( 'Conflictos abiertos', 'dolisync' ); ?></small></div>
						<div><span class="dashicons dashicons-performance" aria-hidden="true"></span><strong><span data-dashboard-value="health.avg_time_ms">—</span> <em>ms</em></strong><small><?php echo esc_html__( 'Latencia media API', 'dolisync' ); ?></small></div>
					</div>
					<div class="dolisync-dashboard-health-summary"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p id="dolisync-dashboard-health-summary"><?php echo esc_html__( 'Preparando el diagnóstico operativo…', 'dolisync' ); ?></p></div>
					<footer><a href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings&tab=health' ) ); ?>"><?php echo esc_html__( 'Ver diagnóstico completo', 'dolisync' ); ?> <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span></a></footer>
				</section>
			</div>

			<div class="dolisync-dashboard-secondary-grid">
				<section class="dolisync-dashboard-card dolisync-dashboard-activity-card">
					<header class="dolisync-dashboard-card-header"><div><span class="dolisync-dashboard-section-icon"><span class="dashicons dashicons-backup" aria-hidden="true"></span></span><div><span class="dolisync-dashboard-card-kicker"><?php echo esc_html__( 'Historial', 'dolisync' ); ?></span><h2><?php echo esc_html__( 'Actividad reciente', 'dolisync' ); ?></h2></div></div></header>
					<div id="dolisync-dashboard-activity" class="dolisync-dashboard-activity"><div class="dolisync-dashboard-loading"><span class="spinner is-active"></span><?php echo esc_html__( 'Leyendo actividad…', 'dolisync' ); ?></div></div>
					<footer><a href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings&tab=logs&log_view=actions' ) ); ?>"><?php echo esc_html__( 'Ver todos los registros', 'dolisync' ); ?> <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span></a></footer>
				</section>

				<aside class="dolisync-dashboard-card dolisync-dashboard-shortcuts">
					<header><span class="dolisync-dashboard-card-kicker"><?php echo esc_html__( 'Accesos directos', 'dolisync' ); ?></span><h2><?php echo esc_html__( '¿Qué quieres revisar?', 'dolisync' ); ?></h2></header>
					<nav aria-label="<?php echo esc_attr__( 'Accesos directos de DoliSync', 'dolisync' ); ?>">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_products' ) ); ?>"><span class="dolisync-dashboard-shortcut-icon"><span class="dashicons dashicons-products" aria-hidden="true"></span></span><span><strong><?php echo esc_html__( 'Catálogo de productos', 'dolisync' ); ?></strong><small><b data-dashboard-value="catalog.category_mappings">—</b> <?php echo esc_html__( 'categorías relacionadas', 'dolisync' ); ?></small></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_categories' ) ); ?>"><span class="dolisync-dashboard-shortcut-icon"><span class="dashicons dashicons-category" aria-hidden="true"></span></span><span><strong><?php echo esc_html__( 'Categorías y TakePOS', 'dolisync' ); ?></strong><small><?php echo esc_html__( 'Mapeo y asistente de variantes', 'dolisync' ); ?></small></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_orders' ) ); ?>"><span class="dolisync-dashboard-shortcut-icon"><span class="dashicons dashicons-media-document" aria-hidden="true"></span></span><span><strong><?php echo esc_html__( 'Pedidos y facturas', 'dolisync' ); ?></strong><small><b data-dashboard-value="orders.failed">—</b> <?php echo esc_html__( 'con incidencias', 'dolisync' ); ?></small></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings&tab=warehouses' ) ); ?>"><span class="dolisync-dashboard-shortcut-icon"><span class="dashicons dashicons-store" aria-hidden="true"></span></span><span><strong><?php echo esc_html__( 'Almacén y automatización', 'dolisync' ); ?></strong><small data-dashboard-value="stock.interval_label">—</small></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
					</nav>
				</aside>
			</div>

			<footer class="dolisync-dashboard-footer"><span><span class="dashicons dashicons-wordpress" aria-hidden="true"></span> WordPress <b data-dashboard-value="system.wordpress_version">—</b> · WooCommerce <b data-dashboard-value="system.woocommerce_version">—</b> · PHP <b data-dashboard-value="system.php_version">—</b></span><span id="dolisync-dashboard-updated"><?php echo esc_html__( 'Actualizando información…', 'dolisync' ); ?></span></footer>
		</div>
		<?php
	}

	public static function ajax_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'dolisync' ) ), 403 );
		}
		check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );

		try {
			$data = self::get_local_data();
			$data['generated_at'] = self::format_unix_time( time() );
			wp_send_json_success( $data );
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'No se pudo cargar el panel: %s', 'dolisync' ), $error->getMessage() ) ), 500 );
		}
	}

	public static function ajax_remote_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'dolisync' ) ), 403 );
		}
		check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );

		try {
			wp_send_json_success( array( 'connection' => self::get_remote_status( ! empty( $_POST['force_remote'] ) ) ) );
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'No se pudo comprobar la conexión: %s', 'dolisync' ), $error->getMessage() ) ), 500 );
		}
	}

	private static function get_local_data() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-diagnostics.php';

		$config = Dolisync_Config::get_all();
		$last_test = Dolisync_Config::get_last_connection_test();
		$woo_products = self::count_post_type( 'product' );
		$woo_variations = self::count_post_type( 'product_variation' );
		$woo_customers = self::count_woocommerce_customers();
		$woo_orders = self::count_woocommerce_orders();
		$synced_products = self::count_rows( 'dolisync_product_relations' );
		$synced_variations = self::count_rows( 'dolisync_product_variation_relations', 'dolibarr_variation_id > 0' );
		$synced_customers = self::count_rows( 'dolisync_contact_relations' );
		$synced_invoices = self::count_rows( 'dolisync_order_relations', 'dolibarr_invoice_id > 0' );
		$pending_orders = self::count_rows( 'dolisync_order_relations', "sync_status IN ('pending','processing','queued','retrying')" );
		$failed_orders = self::count_rows( 'dolisync_order_relations', "sync_status IN ('error','failed')" );
		$category_mappings = self::count_rows( 'dolisync_product_category_mappings', 'dolibarr_category_id > 0 AND wc_category_id > 0' );
		$open_conflicts = self::count_rows( 'dolisync_product_conflicts', "status = 'open'" ) + self::count_rows( 'dolisync_contact_conflicts', "status = 'open'" );
		$operations = Dolisync_Diagnostics::get_summary();
		$last_stock = self::get_last_stock_action();
		$next_stock = wp_next_scheduled( 'dolisync_stock_autosync' );
		$stock_interval = (string) ( $config['stock_sync_interval'] ?? 'off' );
		$warehouse_id = (int) ( $config['warehouse_id'] ?? 0 );
		$warehouse_name = trim( (string) ( $config['warehouse_name'] ?? '' ) );
		$stock_lock = (string) get_option( 'dolisync_stock_sync_lock', '' );
		list( , $stock_lock_time ) = array_pad( explode( '|', $stock_lock, 2 ), 2, 0 );
		$stock_running = '' !== $stock_lock && (int) $stock_lock_time > 0 && ( time() - (int) $stock_lock_time ) < 15 * MINUTE_IN_SECONDS;

		return array(
			'connection' => array(
				'endpoint' => untrailingslashit( (string) ( $config['dolibarr_url'] ?? '' ) ),
				'status' => Dolisync_Config::is_configured() ? (string) ( $last_test['status'] ?? 'pending' ) : 'unconfigured',
				'checked_at' => self::format_mysql_time( $last_test['timestamp'] ?? '' ),
				'message' => (string) ( $last_test['error_message'] ?? '' ),
				'dolibarr_version' => __( 'Consultando…', 'dolisync' ),
				'latency_ms' => null,
			),
			'catalog' => array(
				'woo_products' => $woo_products,
				'woo_variations' => $woo_variations,
				'synced_products' => $synced_products,
				'synced_variations' => $synced_variations,
				'category_mappings' => $category_mappings,
				'products_coverage' => self::coverage( $synced_products, $woo_products ),
				'variations_coverage' => self::coverage( $synced_variations, $woo_variations ),
			),
			'customers' => array(
				'woo_customers' => $woo_customers,
				'synced_customers' => $synced_customers,
				'coverage' => self::coverage( $synced_customers, $woo_customers ),
			),
			'orders' => array(
				'woo_orders' => $woo_orders,
				'synced_invoices' => $synced_invoices,
				'pending' => $pending_orders,
				'failed' => $failed_orders,
				'coverage' => self::coverage( $synced_invoices, $woo_orders ),
			),
			'stock' => array(
				'interval' => $stock_interval,
				'interval_label' => self::interval_label( $stock_interval ),
				'next_run_display' => $next_stock ? self::format_unix_time( $next_stock )['display'] : ( 'off' === $stock_interval ? __( 'Automatización desactivada', 'dolisync' ) : __( 'No programada', 'dolisync' ) ),
				'last_run_display' => $last_stock['time']['display'] ?? __( 'Sin ejecuciones', 'dolisync' ),
				'last_run_relative' => $last_stock['time']['relative'] ?? __( 'Todavía no hay actividad de stock', 'dolisync' ),
				'last_description' => $last_stock['description'] ?? __( 'Ejecuta una sincronización de stock para ver aquí su último resultado.', 'dolisync' ),
				'status' => $last_stock['status'] ?? ( $stock_running ? 'running' : 'pending' ),
				'running' => $stock_running,
			),
			'health' => array(
				'errors_24h' => (int) ( $operations['errors_24h'] ?? 0 ),
				'avg_time_ms' => (int) ( $operations['avg_time_ms'] ?? 0 ),
				'failed_jobs' => (int) ( $operations['failed_jobs'] ?? 0 ),
				'stalled_jobs' => (int) ( $operations['stalled_jobs'] ?? 0 ),
				'open_conflicts' => $open_conflicts,
				'alert' => ! empty( $operations['alert'] ) || $open_conflicts > 0 || $failed_orders > 0,
			),
			'system' => array(
				'plugin_version' => DOLISYNC_VERSION,
				'wordpress_version' => get_bloginfo( 'version' ),
				'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : __( 'No activo', 'dolisync' ),
				'php_version' => PHP_VERSION,
				'warehouse' => $warehouse_id > 0 ? sprintf( '%s (#%d)', '' !== $warehouse_name ? $warehouse_name : __( 'Almacén', 'dolisync' ), $warehouse_id ) : __( 'Sin configurar', 'dolisync' ),
			),
			'activity' => self::get_recent_activity(),
		);
	}

	private static function get_remote_status( $force = false ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$endpoint = untrailingslashit( Dolisync_Config::get_dolibarr_url() );
		if ( ! Dolisync_Config::is_configured() ) {
			return array(
				'status' => 'unconfigured',
				'dolibarr_version' => __( 'Sin conexión', 'dolisync' ),
				'latency_ms' => null,
				'message' => __( 'Configura el endpoint y la clave API para conectar Dolibarr.', 'dolisync' ),
			);
		}

		$cached = get_transient( self::REMOTE_CACHE_KEY );
		if ( ! $force && is_array( $cached ) && $endpoint === (string) ( $cached['endpoint'] ?? '' ) ) {
			if ( isset( $cached['checked_at'] ) && is_array( $cached['checked_at'] ) && ! empty( $cached['checked_at']['timestamp'] ) ) {
				$cached['checked_at'] = self::format_unix_time( (int) $cached['checked_at']['timestamp'] );
			}
			unset( $cached['endpoint'] );
			return $cached;
		}

		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		$response = ( new Dolisync_API_Client() )->test_connection();
		$status = 'failed';
		$message = (string) ( $response['message'] ?? __( 'No se pudo conectar con Dolibarr.', 'dolisync' ) );
		$version = '';

		if ( 'unexpected_status' === ( $response['code'] ?? '' ) ) {
			$status = 'warning';
			Dolisync_Config::set_connection_test_warning( $message );
		} elseif ( ! empty( $response['success'] ) ) {
			$status = 'success';
			$message = __( 'Conexión operativa', 'dolisync' );
			$version = self::extract_dolibarr_version( $response['data'] ?? array() );
			Dolisync_Config::set_connection_test_success( (int) ( $response['time_ms'] ?? 0 ) );
		} else {
			Dolisync_Config::set_connection_test_failed( $message );
		}

		$result = array(
			'endpoint' => $endpoint,
			'status' => $status,
			'dolibarr_version' => '' !== $version ? $version : __( 'No disponible', 'dolisync' ),
			'latency_ms' => isset( $response['time_ms'] ) ? (int) $response['time_ms'] : null,
			'checked_at' => self::format_unix_time( time() ),
			'message' => sanitize_text_field( $message ),
		);
		set_transient( self::REMOTE_CACHE_KEY, $result, 'success' === $status ? self::REMOTE_CACHE_TTL : MINUTE_IN_SECONDS );
		unset( $result['endpoint'] );
		return $result;
	}

	private static function extract_dolibarr_version( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		if ( ! is_array( $data ) ) {
			return '';
		}
		$success = isset( $data['success'] ) && is_array( $data['success'] ) ? $data['success'] : array();
		foreach ( array( $success['dolibarr_version'] ?? '', $data['dolibarr_version'] ?? '', $success['version'] ?? '', $data['version'] ?? '' ) as $candidate ) {
			$candidate = trim( sanitize_text_field( (string) $candidate ) );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}
		return '';
	}

	private static function count_post_type( $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			return 0;
		}
		$counts = wp_count_posts( $post_type );
		$total = 0;
		foreach ( (array) $counts as $status => $count ) {
			if ( ! in_array( (string) $status, array( 'trash', 'auto-draft' ), true ) ) {
				$total += (int) $count;
			}
		}
		return $total;
	}

	private static function count_woocommerce_customers() {
		if ( ! function_exists( 'count_users' ) ) {
			return 0;
		}
		$counts = count_users();
		return (int) ( $counts['avail_roles']['customer'] ?? 0 );
	}

	private static function count_woocommerce_orders() {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return 0;
		}
		try {
			$result = wc_get_orders(
				array(
					'limit' => 1,
					'paginate' => true,
					'return' => 'ids',
					'status' => array_keys( wc_get_order_statuses() ),
				)
			);
			return is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( (array) $result );
		} catch ( Throwable $error ) {
			return 0;
		}
	}

	private static function count_rows( $table_suffix, $where = '1=1' ) {
		global $wpdb;
		$table = $wpdb->prefix . $table_suffix;
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function table_exists( $table ) {
		global $wpdb;
		if ( ! array_key_exists( $table, self::$table_exists ) ) {
			self::$table_exists[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::$table_exists[ $table ];
	}

	private static function get_last_stock_action() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_actions';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT estado, descripcion, timestamp FROM {$table} WHERE tipo = %s AND accion IN (%s, %s) ORDER BY id DESC LIMIT 1", 'stock', 'sincronización_manual', 'sincronización_automática' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $row ) ) {
			return array();
		}
		return array(
			'status' => 'finalizado' === (string) ( $row['estado'] ?? '' ) ? 'success' : ( 'error' === (string) ( $row['estado'] ?? '' ) ? 'failed' : 'running' ),
			'description' => wp_html_excerpt( wp_strip_all_tags( (string) ( $row['descripcion'] ?? '' ) ), 260, '…' ),
			'time' => self::format_mysql_time( $row['timestamp'] ?? '' ),
		);
	}

	private static function get_recent_activity() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_actions';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$rows = $wpdb->get_results( "SELECT tipo, accion, estado, descripcion, timestamp FROM {$table} ORDER BY id DESC LIMIT 6", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$activity = array();
		foreach ( (array) $rows as $row ) {
			$activity[] = array(
				'type' => sanitize_key( (string) ( $row['tipo'] ?? 'sistema' ) ),
				'action' => self::humanize_action( $row['accion'] ?? '' ),
				'status' => 'finalizado' === (string) ( $row['estado'] ?? '' ) ? 'success' : ( 'error' === (string) ( $row['estado'] ?? '' ) ? 'error' : 'info' ),
				'description' => wp_html_excerpt( wp_strip_all_tags( (string) ( $row['descripcion'] ?? '' ) ), 190, '…' ),
				'time' => self::format_mysql_time( $row['timestamp'] ?? '' ),
			);
		}
		return $activity;
	}

	private static function humanize_action( $action ) {
		$action = str_replace( array( '_', '-' ), ' ', (string) $action );
		return '' !== trim( $action ) ? ucfirst( $action ) : __( 'Actividad de DoliSync', 'dolisync' );
	}

	private static function coverage( $synced, $total ) {
		if ( (int) $total <= 0 ) {
			return 0;
		}
		return min( 100, max( 0, (int) round( ( (int) $synced / (int) $total ) * 100 ) ) );
	}

	private static function interval_label( $interval ) {
		$labels = array(
			'off' => __( 'Desactivada', 'dolisync' ),
			'm5' => __( 'Cada 5 minutos', 'dolisync' ),
			'm10' => __( 'Cada 10 minutos', 'dolisync' ),
			'm30' => __( 'Cada 30 minutos', 'dolisync' ),
			'hourly' => __( 'Cada hora', 'dolisync' ),
		);
		return $labels[ $interval ] ?? ucfirst( (string) $interval );
	}

	private static function format_mysql_time( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array( 'display' => __( 'Sin datos', 'dolisync' ), 'relative' => __( 'Nunca', 'dolisync' ), 'timestamp' => 0 );
		}
		$date = date_create_immutable_from_format( 'Y-m-d H:i:s', $value, wp_timezone() );
		if ( ! $date ) {
			return array( 'display' => $value, 'relative' => '', 'timestamp' => 0 );
		}
		return self::format_unix_time( $date->getTimestamp() );
	}

	private static function format_unix_time( $timestamp ) {
		$timestamp = (int) $timestamp;
		return array(
			'display' => wp_date( 'd/m/Y · H:i', $timestamp, wp_timezone() ),
			'relative' => sprintf( __( 'Hace %s', 'dolisync' ), human_time_diff( $timestamp, time() ) ),
			'timestamp' => $timestamp,
		);
	}

	private static function connection_label( $status ) {
		$labels = array(
			'success' => __( 'Conectado', 'dolisync' ),
			'failed' => __( 'Conexión fallida', 'dolisync' ),
			'warning' => __( 'Revisar conexión', 'dolisync' ),
			'unconfigured' => __( 'Sin configurar', 'dolisync' ),
			'pending' => __( 'Pendiente de comprobar', 'dolisync' ),
		);
		return $labels[ $status ] ?? $labels['pending'];
	}
}

Dolisync_Dashboard_Page::init();
