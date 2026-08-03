<?php
/**
 * Página de configuración de DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Settings_Page {
	private $active_tab = 'settings';

	public function __construct() {
		$this->active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( in_array( $this->active_tab, array( 'contact_sync', 'product_sync', 'connection_test' ), true ) ) {
			$this->active_tab = 'settings';
		}
	}

	public function render() {
		if ( isset( $_GET['onboarding'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_onboarding();
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'dolisync' ) );
		}
		?>
		<div class="wrap dolisync-container dolisync-products-app dolisync-settings-app">
			<div class="dolisync-products-hero dolisync-settings-hero">
				<div>
					<span class="dolisync-products-eyebrow"><?php echo esc_html__( 'Centro de control', 'dolisync' ); ?></span>
					<h1><?php echo esc_html__( 'Ajustes', 'dolisync' ); ?></h1>
					<p><?php echo esc_html__( 'Configura la conexión y controla cómo se sincronizan WooCommerce y Dolibarr.', 'dolisync' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings&tab=health' ) ); ?>"><span class="dashicons dashicons-heart"></span><span><?php echo esc_html__( 'Ver salud del sistema', 'dolisync' ); ?></span></a>
			</div>

			<nav class="dolisync-tabs dolisync-settings-tabs" aria-label="<?php echo esc_attr__( 'Secciones de ajustes', 'dolisync' ); ?>">
				<ul>
					<li><a class="<?php echo 'settings' === $this->active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings&tab=settings' ) ); ?>"><?php echo esc_html__( 'Configuración', 'dolisync' ); ?></a></li>
					<li><a class="<?php echo 'warehouses' === $this->active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings&tab=warehouses' ) ); ?>"><?php echo esc_html__( 'Almacenes', 'dolisync' ); ?></a></li>
					<li><a class="<?php echo 'health' === $this->active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings&tab=health' ) ); ?>"><?php echo esc_html__( 'Salud', 'dolisync' ); ?></a></li>
					<li><a class="<?php echo 'logs' === $this->active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings&tab=logs' ) ); ?>"><?php echo esc_html__( 'Logs', 'dolisync' ); ?></a></li>
				</ul>
			</nav>

			<div class="dolisync-content">
				<?php
				switch ( $this->active_tab ) {
					case 'logs':
						$this->render_logs_tab();
						break;
					case 'warehouses':
						$this->render_warehouses_tab();
						break;
					case 'health':
						$this->render_health_tab();
						break;
					case 'settings':
					default:
						$this->render_settings_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	private function render_health_tab() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-encryption.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		global $wpdb;

		$config = Dolisync_Config::get_all();
		$last_test = Dolisync_Config::get_last_connection_test();
		$url = (string) ( $config['dolibarr_url'] ?? '' );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$stock_interval = (string) ( $config['stock_sync_interval'] ?? 'off' );
		$next_stock = wp_next_scheduled( 'dolisync_stock_autosync' );
		$schema_status = Dolisync_Schema::get_schema_status();
		$schema_result = isset( $_GET['dolisync-schema'] ) ? sanitize_key( wp_unslash( $_GET['dolisync-schema'] ) ) : '';
		$show_schema_details = in_array( $schema_result, array( 'checked', 'repaired', 'failed' ), true );
		$order_table = $wpdb->prefix . 'dolisync_order_relations';
		$order_columns = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $order_table ) ) === $order_table ? (array) $wpdb->get_col( "DESCRIBE {$order_table}", 0 ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tax_rates = class_exists( 'WooCommerce' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tax_mapping = Dolisync_Config::get_tax_mapping();
		$relation_table_exists = ! empty( $order_columns );
		$pending_orders = $relation_table_exists && in_array( 'sync_status', $order_columns, true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$order_table} WHERE sync_status IN ('pending','processing','error')" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$pending_email = $relation_table_exists && in_array( 'invoice_email_status', $order_columns, true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$order_table} WHERE invoice_email_status IN ('pending','queued','retrying','error','failed') AND dolibarr_invoice_id IS NOT NULL" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$connection_status = (string) ( $last_test['status'] ?? 'pending' );
		$last_test_timestamp = ! empty( $last_test['timestamp'] ) ? strtotime( (string) $last_test['timestamp'] ) : false;
		$last_test_age = $last_test_timestamp ? max( 0, current_time( 'timestamp' ) - $last_test_timestamp ) : null;
		$is_test_very_stale = null !== $last_test_age && $last_test_age > DAY_IN_SECONDS;
		$is_test_stale = null !== $last_test_age && $last_test_age > HOUR_IN_SECONDS;
		$connection_check_status = 'success' === $connection_status
			? ( $is_test_very_stale ? 'critical' : ( $is_test_stale ? 'warning' : 'good' ) )
			: ( 'failed' === $connection_status ? 'critical' : 'warning' );
		$connection_value = 'success' === $connection_status
			? ( $is_test_very_stale ? __( 'Conexión sin comprobar desde hace más de 24 horas', 'dolisync' ) : ( $is_test_stale ? __( 'Conexión sin comprobar en la última hora', 'dolisync' ) : __( 'Conexión correcta', 'dolisync' ) ) )
			: ( 'failed' === $connection_status ? __( 'La última conexión falló', 'dolisync' ) : __( 'Conexión pendiente de validar', 'dolisync' ) );

		$checks = array(
			array( 'status' => class_exists( 'WooCommerce' ) ? 'good' : 'critical', 'icon' => 'cart', 'title' => __( 'WooCommerce', 'dolisync' ), 'value' => class_exists( 'WooCommerce' ) ? sprintf( __( 'Activo · versión %s', 'dolisync' ), defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) : __( 'No está activo', 'dolisync' ), 'help' => __( 'DoliSync necesita WooCommerce para operar.', 'dolisync' ) ),
			array( 'status' => '' !== $url && '' !== Dolisync_Config::get_dolibarr_api_key() ? 'good' : 'critical', 'icon' => 'admin-links', 'title' => __( 'Configuración Dolibarr', 'dolisync' ), 'value' => '' !== $url ? $url : __( 'Configuración incompleta', 'dolisync' ), 'help' => __( 'URL y clave API necesarias para sincronizar.', 'dolisync' ) ),
			array( 'status' => $connection_check_status, 'icon' => 'cloud', 'title' => __( 'Última prueba remota', 'dolisync' ), 'value' => $connection_value, 'help' => ! empty( $last_test['timestamp'] ) ? sprintf( __( 'Última comprobación: %s', 'dolisync' ), (string) $last_test['timestamp'] ) : __( 'Ejecuta la prueba de conexión antes de sincronizar.', 'dolisync' ) ),
			array( 'status' => 'https' === $scheme ? 'good' : ( '' === $url ? 'critical' : 'warning' ), 'icon' => 'lock', 'title' => __( 'Transporte seguro', 'dolisync' ), 'value' => 'https' === $scheme ? __( 'HTTPS activado', 'dolisync' ) : __( 'La URL no utiliza HTTPS', 'dolisync' ), 'help' => __( 'Las credenciales no deben viajar mediante HTTP en producción.', 'dolisync' ) ),
			array( 'status' => Dolisync_Encryption::is_available() ? 'good' : 'critical', 'icon' => 'shield-alt', 'title' => __( 'Cifrado de credenciales', 'dolisync' ), 'value' => Dolisync_Encryption::is_available() ? __( 'AES-256-GCM disponible', 'dolisync' ) : __( 'OpenSSL o AES-256-GCM no disponible', 'dolisync' ), 'help' => __( 'Protege las claves almacenadas por el plugin.', 'dolisync' ) ),
			array( 'schema' => true, 'status' => ! empty( $schema_status['healthy'] ) ? 'good' : 'critical', 'icon' => 'database', 'title' => __( 'Base de datos', 'dolisync' ), 'value' => ! empty( $schema_status['healthy'] ) ? __( 'Esquema preparado', 'dolisync' ) : sprintf( __( 'Se han detectado %d problemas', 'dolisync' ), (int) $schema_status['issues'] ), 'help' => ! empty( $schema_status['healthy'] ) ? __( 'Todas las tablas y columnas de DoliSync están disponibles.', 'dolisync' ) : __( 'Hay tablas o columnas ausentes. Comprueba el detalle y actualiza el esquema.', 'dolisync' ) ),
			array( 'status' => 'off' === $stock_interval ? 'neutral' : ( $next_stock ? 'good' : 'critical' ), 'icon' => 'clock', 'title' => __( 'Cron de stock', 'dolisync' ), 'value' => 'off' === $stock_interval ? __( 'Desactivado', 'dolisync' ) : ( $next_stock ? sprintf( __( 'Próxima ejecución: %s', 'dolisync' ), wp_date( 'd/m/Y H:i:s', $next_stock, wp_timezone() ) ) : __( 'Configurado pero no programado', 'dolisync' ) ), 'help' => __( 'WP-Cron debe ejecutarse regularmente en producción.', 'dolisync' ) ),
			array( 'status' => $tax_rates <= count( $tax_mapping ) ? 'good' : 'warning', 'icon' => 'money-alt', 'title' => __( 'Mapeo fiscal', 'dolisync' ), 'value' => sprintf( __( '%1$d de %2$d tasas mapeadas', 'dolisync' ), min( count( $tax_mapping ), $tax_rates ), $tax_rates ), 'help' => __( 'Revisa el mapeo al cambiar los impuestos de WooCommerce.', 'dolisync' ) ),
			array( 'status' => (int) ( $config['warehouse_id'] ?? 0 ) > 0 ? 'good' : 'warning', 'icon' => 'store', 'title' => __( 'Almacén Dolibarr', 'dolisync' ), 'value' => (int) ( $config['warehouse_id'] ?? 0 ) > 0 ? sprintf( '%s (#%d)', (string) ( $config['warehouse_name'] ?: __( 'Almacén', 'dolisync' ) ), (int) $config['warehouse_id'] ) : __( 'Sin almacén configurado', 'dolisync' ), 'help' => __( 'Necesario para las operaciones de existencias.', 'dolisync' ) ),
		);
		$counts = array_count_values( wp_list_pluck( $checks, 'status' ) );
		$critical = (int) ( $counts['critical'] ?? 0 );
		$warnings = (int) ( $counts['warning'] ?? 0 );
		$score = max( 0, 100 - ( $critical * 25 ) - ( $warnings * 10 ) );
		$overall = $critical > 0 ? 'critical' : ( $warnings > 0 ? 'warning' : 'good' );
		?>
		<div class="dolisync-tab-content dolisync-health">
			<div class="dolisync-health-hero dolisync-health-<?php echo esc_attr( $overall ); ?>">
				<div class="dolisync-health-score" style="--health-score: <?php echo esc_attr( (string) $score ); ?>"><strong><?php echo esc_html( (string) $score ); ?></strong><span>/ 100</span></div>
				<div><span class="dolisync-health-kicker"><?php echo esc_html__( 'Estado del sincronizador', 'dolisync' ); ?></span><h2><?php echo esc_html( $critical ? __( 'Requiere atención antes de producir', 'dolisync' ) : ( $warnings ? __( 'Operativo con recomendaciones', 'dolisync' ) : __( 'Todo preparado', 'dolisync' ) ) ); ?></h2><p><?php echo esc_html( sprintf( __( '%1$d comprobaciones críticas · %2$d recomendaciones', 'dolisync' ), $critical, $warnings ) ); ?></p></div>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=dolisync_settings&tab=health' ) ); ?>"><span class="dashicons dashicons-update"></span><?php echo esc_html__( 'Volver a comprobar', 'dolisync' ); ?></a>
			</div>
			<div class="dolisync-health-metrics">
				<div><span class="dashicons dashicons-update"></span><strong><?php echo esc_html( (string) $pending_orders ); ?></strong><small><?php echo esc_html__( 'pedidos pendientes o con error', 'dolisync' ); ?></small></div>
				<div><span class="dashicons dashicons-email-alt"></span><strong><?php echo esc_html( (string) $pending_email ); ?></strong><small><?php echo esc_html__( 'entregas de factura pendientes', 'dolisync' ); ?></small></div>
				<div><span class="dashicons dashicons-admin-links"></span><strong><?php echo esc_html( 'good' === $connection_check_status ? __( 'Correcta', 'dolisync' ) : ( 'warning' === $connection_check_status ? __( 'Revisar', 'dolisync' ) : __( 'No fiable', 'dolisync' ) ) ); ?></strong><small><?php echo esc_html( ! empty( $last_test['timestamp'] ) ? sprintf( __( 'conexión probada hace %s', 'dolisync' ), human_time_diff( strtotime( $last_test['timestamp'] ), current_time( 'timestamp' ) ) ) : __( 'conexión no comprobada', 'dolisync' ) ); ?></small></div>
			</div>
			<div class="dolisync-health-grid">
			<?php foreach ( $checks as $check ) : ?>
				<?php $state_label = array( 'good' => __( 'Correcto', 'dolisync' ), 'warning' => __( 'Revisar', 'dolisync' ), 'critical' => __( 'Crítico', 'dolisync' ), 'neutral' => __( 'Informativo', 'dolisync' ) )[ $check['status'] ] ?? __( 'Informativo', 'dolisync' ); ?>
				<article class="dolisync-health-card is-<?php echo esc_attr( $check['status'] ); ?>"><div class="dolisync-health-icon"><span class="dashicons dashicons-<?php echo esc_attr( $check['icon'] ); ?>"></span></div><div><span class="dolisync-health-state"><?php echo esc_html( $state_label ); ?></span><h3><?php echo esc_html( $check['title'] ); ?></h3><strong><?php echo esc_html( $check['value'] ); ?></strong><p><?php echo esc_html( $check['help'] ); ?></p>
				<?php if ( ! empty( $check['schema'] ) ) : ?>
					<?php if ( $show_schema_details ) : ?>
						<ul class="dolisync-schema-results">
						<?php foreach ( $schema_status['tables'] as $table_status ) : ?>
							<li><strong><?php echo esc_html( $table_status['table'] ); ?>:</strong> <?php echo esc_html( ! $table_status['exists'] ? __( 'tabla ausente', 'dolisync' ) : ( empty( $table_status['missing_columns'] ) ? __( 'correcta', 'dolisync' ) : sprintf( __( 'faltan: %s', 'dolisync' ), implode( ', ', $table_status['missing_columns'] ) ) ) ); ?></li>
						<?php endforeach; ?>
						</ul>
						<?php if ( 'repaired' === $schema_result && ! empty( $schema_status['healthy'] ) ) : ?><p class="dolisync-schema-success"><strong><?php echo esc_html__( 'Esquema actualizado correctamente.', 'dolisync' ); ?></strong></p><?php endif; ?>
					<?php endif; ?>
					<div class="dolisync-schema-actions">
						<form method="post"><?php wp_nonce_field( DOLISYNC_NONCE_ACTION, 'dolisync_nonce' ); ?><input type="hidden" name="action" value="dolisync_check_schema"><button type="submit" class="button"><span class="dashicons dashicons-search"></span> <?php echo esc_html__( 'Comprobar esquema', 'dolisync' ); ?></button></form>
						<?php if ( $show_schema_details && empty( $schema_status['healthy'] ) ) : ?><form method="post"><?php wp_nonce_field( DOLISYNC_NONCE_ACTION, 'dolisync_nonce' ); ?><input type="hidden" name="action" value="dolisync_repair_schema"><button type="submit" class="button button-primary"><span class="dashicons dashicons-update"></span> <?php echo esc_html__( 'Actualizar', 'dolisync' ); ?></button></form><?php endif; ?>
					</div>
				<?php endif; ?>
				</div></article>
			<?php endforeach; ?>
				<article class="dolisync-health-card dolisync-health-test-card is-<?php echo esc_attr( $connection_check_status ); ?>">
					<div class="dolisync-health-icon"><span class="dashicons dashicons-controls-play"></span></div>
					<div class="dolisync-health-test-copy"><span class="dolisync-health-state"><?php echo esc_html__( 'Test manual', 'dolisync' ); ?></span><h3><?php echo esc_html__( 'Conexión con Dolibarr', 'dolisync' ); ?></h3><p><?php echo esc_html__( 'Comprueba autenticación, acceso y latencia de la API.', 'dolisync' ); ?></p><div id="dolisync-test-result" class="dolisync-health-test-result" aria-live="polite"></div></div>
					<div class="dolisync-health-test-action"><span><?php echo esc_html( ! empty( $last_test['timestamp'] ) ? sprintf( __( 'Último: %s', 'dolisync' ), human_time_diff( strtotime( $last_test['timestamp'] ), current_time( 'timestamp' ) ) ) : __( 'Sin comprobar', 'dolisync' ) ); ?></span><button type="button" id="dolisync-test-connection" class="button button-primary" <?php disabled( ! Dolisync_Config::is_configured() ); ?>><span class="dashicons dashicons-controls-play"></span><span><?php echo esc_html__( 'Probar', 'dolisync' ); ?></span></button></div>
				</article>
			</div>
			<div class="dolisync-health-foot"><span class="dashicons dashicons-info-outline"></span><p><strong><?php echo esc_html__( 'Comprobación local y segura.', 'dolisync' ); ?></strong> <?php echo esc_html__( 'Esta pantalla no llama a Dolibarr al abrirse. Usa el botón Probar de esta misma pantalla para verificar el servicio remoto.', 'dolisync' ); ?></p></div>
		</div>
		<?php
	}

	private function render_onboarding() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'dolisync' ) );
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$config = Dolisync_Config::get_all();
		$cf = Dolisync_Config::get_cf_access_headers();
		$cf_enabled = (bool) get_option( 'dolisync_cf_access_enabled', ! empty( $cf ) );
		$last_test = Dolisync_Config::get_last_connection_test();
		$is_reconfigure = Dolisync_Config::is_configured() || ! empty( $last_test['timestamp'] );
		$has_api_key = '' !== Dolisync_Config::get_dolibarr_api_key();
		?>
		<div class="wrap dolisync-container dolisync-onboarding">
			<div class="dolisync-onboarding-progress"><span class="is-active">1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
			<form id="dolisync-onboarding-form">
				<section class="dolisync-onboarding-step is-active"><span class="dashicons dashicons-swap"></span><h1><?php echo esc_html( $is_reconfigure ? __( 'Reconfiguremos DoliSync', 'dolisync' ) : __( 'Bienvenido a DoliSync', 'dolisync' ) ); ?></h1><p><?php echo esc_html( $is_reconfigure ? __( 'Revisaremos la conexión actual y volveremos a validarla.', 'dolisync' ) : __( 'Conectaremos WooCommerce con Dolibarr en cinco pasos rápidos.', 'dolisync' ) ); ?></p><?php if ( $is_reconfigure && ! empty( $last_test['error_message'] ) ) : ?><div class="notice notice-error inline dolisync-onboarding-last-error"><p><strong><?php echo esc_html__( 'Último error:', 'dolisync' ); ?></strong> <?php echo esc_html( $last_test['error_message'] ); ?></p><?php if ( ! empty( $last_test['timestamp'] ) ) : ?><small><?php echo esc_html( $last_test['timestamp'] ); ?></small><?php endif; ?></div><?php endif; ?></section>
				<section class="dolisync-onboarding-step"><h2><?php echo esc_html__( 'Endpoint de Dolibarr', 'dolisync' ); ?></h2><label for="onboarding-url"><?php echo esc_html__( 'URL de Dolibarr', 'dolisync' ); ?></label><input id="onboarding-url" class="dolisync-input" type="text" inputmode="url" name="dolibarr_url" value="<?php echo esc_attr( $config['dolibarr_url'] ?? '' ); ?>" placeholder="https://dolibarr.ejemplo.com" required><p><?php echo esc_html__( 'Usa la URL base; si omites el protocolo se utilizará HTTPS.', 'dolisync' ); ?></p></section>
				<section class="dolisync-onboarding-step"><h2><?php echo esc_html__( 'Autenticación', 'dolisync' ); ?></h2><label for="onboarding-key"><?php echo esc_html__( 'Clave API de Dolibarr', 'dolisync' ); ?></label><input id="onboarding-key" class="dolisync-input" type="password" name="dolibarr_api_key" <?php echo $has_api_key ? '' : 'required'; ?> autocomplete="new-password" placeholder="<?php echo esc_attr( $has_api_key ? __( 'Clave actual guardada; déjalo vacío para conservarla', 'dolisync' ) : __( 'Introduce la clave API', 'dolisync' ) ); ?>"><p><?php echo esc_html( $has_api_key ? __( 'Ya existe una clave cifrada. Solo escribe otra si deseas sustituirla.', 'dolisync' ) : __( 'La clave se guardará cifrada.', 'dolisync' ) ); ?></p></section>
				<section class="dolisync-onboarding-step"><h2><?php echo esc_html__( 'Cloudflare Access', 'dolisync' ); ?></h2><label class="dolisync-switch"><input type="checkbox" name="cf_access_enabled" value="1" <?php checked( $cf_enabled ); ?>><span></span><?php echo esc_html__( 'Habilitar credenciales de CF Access', 'dolisync' ); ?></label><div class="dolisync-cf-fields<?php echo $cf_enabled ? '' : ' is-disabled'; ?>"><label for="onboarding-cf-id">CF-Access-Client-Id</label><input id="onboarding-cf-id" class="dolisync-input" type="text" name="cf_access_client_id" value="<?php echo esc_attr( $cf['CF-Access-Client-Id'] ?? '' ); ?>" <?php disabled( ! $cf_enabled ); ?>><label for="onboarding-cf-secret">CF-Access-Client-Secret</label><input id="onboarding-cf-secret" class="dolisync-input" type="password" name="cf_access_client_secret" autocomplete="new-password" <?php disabled( ! $cf_enabled ); ?>></div><p><?php echo esc_html__( 'Déjalo desactivado si tu Dolibarr no está protegido por Cloudflare Access.', 'dolisync' ); ?></p></section>
				<section class="dolisync-onboarding-step"><h2><?php echo esc_html__( 'Prueba de conexión', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Guardaremos los ajustes y consultaremos /status.', 'dolisync' ); ?></p><button type="button" id="dolisync-onboarding-test" class="button button-primary"><?php echo esc_html__( 'Guardar y probar conexión', 'dolisync' ); ?></button><div id="dolisync-onboarding-result" aria-live="polite"></div></section>
				<div class="dolisync-onboarding-actions"><button type="button" class="button dolisync-onboarding-prev" disabled><?php echo esc_html__( 'Anterior', 'dolisync' ); ?></button><button type="button" class="button button-primary dolisync-onboarding-next"><?php echo esc_html__( 'Continuar', 'dolisync' ); ?></button><button type="button" class="button button-primary dolisync-onboarding-finish" hidden disabled><?php echo esc_html__( 'Finalizar', 'dolisync' ); ?></button></div>
			</form>
		</div>
		<?php
	}

	private function render_settings_tab() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$config = Dolisync_Config::get_all();
		$cf_access_headers = Dolisync_Config::get_cf_access_headers();
		$cf_access_enabled = (bool) get_option( 'dolisync_cf_access_enabled', ! empty( $cf_access_headers ) );
		$tax_mapping = Dolisync_Config::get_tax_mapping();
		global $wpdb;
		$woocommerce_tax_rates = class_exists( 'WooCommerce' ) ? $wpdb->get_results( "SELECT tax_rate_id, tax_rate, tax_rate_name, tax_rate_country, tax_rate_class FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_country, tax_rate_class, tax_rate" ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		?>
		<div class="dolisync-tab-content dolisync-settings-panel dolisync-settings-form-panel">
			<div class="dolisync-panel-heading">
				<span class="dashicons dashicons-admin-generic"></span>
				<div><h2><?php echo esc_html__( 'Configuración de DoliSync', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Gestiona las credenciales, impuestos y automatizaciones desde un solo lugar.', 'dolisync' ); ?></p></div>
			</div>
			<form method="post" action="">
				<?php wp_nonce_field( DOLISYNC_NONCE_ACTION, 'dolisync_nonce' ); ?>
				<input type="hidden" name="action" value="dolisync_save_settings">

				<div class="dolisync-section">
					<h3><?php echo esc_html__( 'Conexión Dolibarr', 'dolisync' ); ?></h3>
					<div class="dolisync-form-group">
						<label for="dolibarr_url"><?php echo esc_html__( 'URL de Dolibarr', 'dolisync' ); ?> <span class="required">*</span></label>
						<input class="dolisync-input" type="url" id="dolibarr_url" name="dolibarr_url" value="<?php echo esc_attr( $config['dolibarr_url'] ?? '' ); ?>" placeholder="https://dolibarr.ejemplo.com" required>
					</div>

					<div class="dolisync-form-group">
						<label for="dolibarr_api_key"><?php echo esc_html__( 'API Key', 'dolisync' ); ?></label>
						<input class="dolisync-input" type="password" id="dolibarr_api_key" name="dolibarr_api_key" value="" placeholder="••••••••••••••••">
						<p class="description"><?php echo esc_html__( 'Déjalo vacío si no deseas cambiar la clave actual.', 'dolisync' ); ?></p>
					</div>

					<div class="dolisync-form-group dolisync-cf-toggle">
						<label><input type="checkbox" name="cf_access_enabled" value="1" <?php checked( $cf_access_enabled ); ?>> <?php echo esc_html__( 'Habilitar Cloudflare Access', 'dolisync' ); ?></label>
					</div>

					<div class="dolisync-form-group dolisync-cf-fields">
						<label for="cf_access_client_id"><?php echo esc_html__( 'CF-Access-Client-Id', 'dolisync' ); ?></label>
						<input class="dolisync-input" type="text" id="cf_access_client_id" name="cf_access_client_id" value="<?php echo esc_attr( $cf_access_headers['CF-Access-Client-Id'] ?? '' ); ?>" placeholder="TU_CLIENT_ID">
					</div>

					<div class="dolisync-form-group dolisync-cf-fields">
						<label for="cf_access_client_secret"><?php echo esc_html__( 'CF-Access-Client-Secret', 'dolisync' ); ?></label>
						<input class="dolisync-input" type="password" id="cf_access_client_secret" name="cf_access_client_secret" value="" placeholder="TU_CLIENT_SECRET">
						<p class="description"><?php echo esc_html__( 'Headers opcionales para entornos protegidos por Cloudflare Access. Déjalo vacío para conservar el secreto actual.', 'dolisync' ); ?></p>
					</div>
				</div>

				<div class="dolisync-section">
					<h3><?php echo esc_html__( 'Mapeo de impuestos para facturación', 'dolisync' ); ?></h3>
					<p class="description"><?php echo esc_html__( 'Indica el porcentaje exacto que se enviará a Dolibarr para cada tipo configurado en WooCommerce. Por ejemplo, escribe 21 y no 21,0001. Esto evita rechazos de VeriFactu por tipos impositivos derivados con decimales.', 'dolisync' ); ?></p>
					<?php if ( ! empty( $woocommerce_tax_rates ) ) : ?>
						<table class="widefat striped dolisync-table" style="margin-top:12px;max-width:760px">
							<thead><tr><th><?php echo esc_html__( 'Impuesto WooCommerce', 'dolisync' ); ?></th><th><?php echo esc_html__( 'Tipo configurado', 'dolisync' ); ?></th><th><?php echo esc_html__( 'Enviar a Dolibarr', 'dolisync' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $woocommerce_tax_rates as $wc_rate ) : ?>
								<?php $rate_id = (int) $wc_rate->tax_rate_id; $mapped_rate = isset( $tax_mapping[ (string) $rate_id ] ) ? $tax_mapping[ (string) $rate_id ] : (string) (float) $wc_rate->tax_rate; ?>
								<tr>
									<td><?php echo esc_html( (string) ( $wc_rate->tax_rate_name ?: __( 'Sin nombre', 'dolisync' ) ) ); ?> <small>#<?php echo esc_html( (string) $rate_id ); ?></small></td>
									<td><?php echo esc_html( (string) (float) $wc_rate->tax_rate ); ?>%</td>
									<td><input type="number" name="tax_mapping[<?php echo esc_attr( (string) $rate_id ); ?>]" value="<?php echo esc_attr( $mapped_rate ); ?>" min="0" max="100" step="0.0001" class="small-text"> %</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php echo esc_html__( 'WooCommerce no tiene tipos impositivos configurados.', 'dolisync' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="dolisync-section">
					<h3><?php echo esc_html__( 'Desinstalación', 'dolisync' ); ?></h3>
					<div class="dolisync-form-group">
						<label><input type="checkbox" name="retain_data_on_uninstall" value="1" <?php checked( (int) ( $config['retain_data_on_uninstall'] ?? 1 ), 1 ); ?>> <?php echo esc_html__( 'Conservar datos al desinstalar DoliSync', 'dolisync' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Recomendado para preservar relaciones, trazabilidad fiscal, configuración y PDFs privados. Si desmarcas esta opción, la desinstalación eliminará permanentemente todos esos datos.', 'dolisync' ); ?></p>
					</div>
				</div>

				<div class="dolisync-section">
					<h3><?php echo esc_html__( 'Configuración de Logs', 'dolisync' ); ?></h3>
					<div class="dolisync-form-group">
						<label><input type="checkbox" name="logs_enabled" value="1" <?php checked( (int) ( $config['logs_enabled'] ?? 1 ), 1 ); ?>> <?php echo esc_html__( 'Activar logging', 'dolisync' ); ?></label>
					</div>
					<div class="dolisync-form-group">
						<label for="log_level"><?php echo esc_html__( 'Nivel de log', 'dolisync' ); ?></label>
						<select id="log_level" name="log_level" class="dolisync-select">
							<?php foreach ( array( 'ERROR', 'WARNING', 'INFO', 'DEBUG', 'TRACE' ) as $level ) : ?>
								<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $config['log_level'] ?? 'INFO', $level ); ?>><?php echo esc_html( $level ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="dolisync-form-group">
						<label for="log_retention_days"><?php echo esc_html__( 'Retención de logs (días)', 'dolisync' ); ?></label>
						<input class="dolisync-input-small" type="number" min="1" max="365" id="log_retention_days" name="log_retention_days" value="<?php echo esc_attr( $config['log_retention_days'] ?? 7 ); ?>">
					</div>
				</div>


				<div class="dolisync-section">
					<h3><?php echo esc_html__( 'Sincronización automática de stock', 'dolisync' ); ?></h3>
					<div class="dolisync-form-group">
						<label for="stock_sync_interval"><?php echo esc_html__( 'Frecuencia Dolibarr → WooCommerce', 'dolisync' ); ?></label>
						<select id="stock_sync_interval" name="stock_sync_interval" class="dolisync-select">
							<?php
							$stock_options = array(
								'off' => __( 'Desactivado', 'dolisync' ),
								'm5' => __( 'Cada 5 minutos', 'dolisync' ),
								'm10' => __( 'Cada 10 minutos', 'dolisync' ),
								'm30' => __( 'Cada 30 minutos', 'dolisync' ),
								'hourly' => __( 'Cada hora', 'dolisync' ),
							);
							$current_stock_interval = $config['stock_sync_interval'] ?? 'off';
							foreach ( $stock_options as $key => $label ) :
								?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_stock_interval, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php
							endforeach;
							?>
						</select>
						<p class="description"><?php echo esc_html__( 'Actualiza únicamente el stock de productos ya vinculados, siempre desde Dolibarr hacia WooCommerce. No crea ni modifica productos, precios o contenido. WP-Cron se ejecuta cuando el sitio recibe tráfico.', 'dolisync' ); ?></p>
						<?php $next_stock_sync = wp_next_scheduled( 'dolisync_stock_autosync' ); ?>
						<?php if ( $next_stock_sync ) : ?>
							<p class="description"><strong><?php echo esc_html__( 'Próxima ejecución:', 'dolisync' ); ?></strong> <?php echo esc_html( wp_date( 'Y-m-d H:i:s', $next_stock_sync ) ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<div class="dolisync-section">
					<h3><?php echo esc_html__( 'Autochequeo periódico', 'dolisync' ); ?></h3>
					<div class="dolisync-form-group">
						<label for="cron_interval"><?php echo esc_html__( 'Comprobación automática de conexión', 'dolisync' ); ?></label>
						<select id="cron_interval" name="cron_interval" class="dolisync-select">
							<?php
							$options = array(
								'off' => __( 'Desactivado', 'dolisync' ),
								'hourly' => __( 'Cada hora', 'dolisync' ),
								'twicedaily' => __( 'Dos veces al día', 'dolisync' ),
								'daily' => __( 'Diario', 'dolisync' ),
							);
							$current = $config['cron_interval'] ?? 'off';
							foreach ( $options as $key => $label ) :
								?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php
							endforeach;
							?>
						</select>
						<p class="description"><?php echo esc_html__( 'Programa una verificación automática periódica de la conectividad con Dolibarr.', 'dolisync' ); ?></p>
					</div>
				</div>

				<div class="dolisync-form-actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Guardar Configuración', 'dolisync' ); ?></button>
				</div>
			</form>

			<?php $this->show_saved_notice(); ?>
		</div>
		<?php
	}

	private function render_warehouses_tab() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$config = Dolisync_Config::get_all();
		$id = absint( $config['warehouse_id'] ?? 0 );
		$name = (string) ( $config['warehouse_name'] ?? '' );
		?>
		<div class="dolisync-tab-content">
			<h2><?php echo esc_html__( 'Almacenes de Dolibarr', 'dolisync' ); ?></h2>
			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Almacén para operaciones de stock', 'dolisync' ); ?></h3>
				<p><?php echo esc_html__( 'Obtén los almacenes disponibles y elige por nombre dónde se registrará el stock enviado desde WooCommerce.', 'dolisync' ); ?></p>
				<form method="post" action="" id="dolisync-warehouse-form">
					<?php wp_nonce_field( DOLISYNC_NONCE_ACTION, 'dolisync_nonce' ); ?>
					<input type="hidden" name="action" value="dolisync_save_warehouse">
					<div class="dolisync-form-group">
						<label for="warehouse_id"><?php echo esc_html__( 'Almacén seleccionado', 'dolisync' ); ?></label>
						<select id="warehouse_id" name="warehouse_id" class="dolisync-select" required>
							<option value="<?php echo esc_attr( $id ?: '' ); ?>"><?php echo esc_html( $id ? sprintf( '%s (#%d)', $name ?: __( 'Almacén guardado', 'dolisync' ), $id ) : __( 'Obtén la lista para seleccionar un almacén', 'dolisync' ) ); ?></option>
						</select>
						<p class="description"><?php echo esc_html__( 'Se mantiene el ID internamente, pero siempre verás también el nombre del almacén.', 'dolisync' ); ?></p>
					</div>
					<div class="dolisync-form-actions">
						<button type="button" class="button button-secondary" id="dolisync-load-warehouses"><?php echo esc_html__( 'Obtener lista de almacenes', 'dolisync' ); ?></button>
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Guardar almacén', 'dolisync' ); ?></button>
					</div>
					<div id="dolisync-warehouses-result" aria-live="polite"></div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_connection_test_tab() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$last_test = Dolisync_Config::get_last_connection_test();
		$is_configured = Dolisync_Config::is_configured();
		$last_test_timestamp = ! empty( $last_test['timestamp'] ) ? strtotime( get_gmt_from_date( $last_test['timestamp'] ) ) : 0;
		$last_test_status = (string) ( $last_test['status'] ?? 'pending' );
		$last_test_message = (string) ( $last_test['error_message'] ?? '' );
		?>
		<div class="dolisync-tab-content">
			<h2><?php echo esc_html__( 'Prueba de Conexión', 'dolisync' ); ?></h2>
			<div class="dolisync-section">
				<?php if ( isset( $_GET['test-success'] ) ) : ?>
					<div class="dolisync-notice dolisync-notice-success"><strong><?php echo esc_html__( '✓ Conexión Exitosa', 'dolisync' ); ?></strong></div>
				<?php elseif ( isset( $_GET['test-error'] ) ) : ?>
					<div class="dolisync-notice dolisync-notice-error"><strong><?php echo esc_html__( '✗ Error de Conexión', 'dolisync' ); ?></strong></div>
				<?php elseif ( $last_test && 'success' === ( $last_test['status'] ?? '' ) ) : ?>
					<div class="dolisync-notice dolisync-notice-success"><strong><?php echo esc_html__( '✓ Último Test: Exitoso', 'dolisync' ); ?></strong></div>
				<?php elseif ( $last_test && 'warning' === ( $last_test['status'] ?? '' ) ) : ?>
					<div class="dolisync-notice dolisync-notice-warning"><strong><?php echo esc_html__( '? Último Test: Conexión establecida pero con respuesta sospechosa', 'dolisync' ); ?></strong><?php if ( ! empty( $last_test_message ) ) : ?><div style="margin-top:6px;"><?php echo esc_html( $last_test_message ); ?></div><?php endif; ?></div>
				<?php elseif ( $last_test && 'failed' === ( $last_test['status'] ?? '' ) ) : ?>
					<div class="dolisync-notice dolisync-notice-error">
						<strong><?php echo esc_html__( '✗ Último Test: Error', 'dolisync' ); ?></strong>
						<?php if ( ! empty( $last_test_message ) ) : ?>
							<div style="margin-top:6px;"><?php echo esc_html( $last_test_message ); ?></div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

                <?php if ( $is_configured ) : ?>

                    <div class="dolisync-form-actions">

                        <button
                            type="button"
                            id="dolisync-test-connection"
                            class="button button-secondary"
                        >
                            <?php echo esc_html__( 'Probar Conexión', 'dolisync' ); ?>
                        </button>

                    </div>

                    <div id="dolisync-test-result" style="margin-top:15px;"></div>

                <?php else : ?>

                    <div class="dolisync-notice dolisync-notice-warning">
                        <?php echo esc_html__( 'Debes configurar la URL y API Key antes de probar la conexión.', 'dolisync' ); ?>
                    </div>

                <?php endif; ?>
			</div>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Información de Depuración', 'dolisync' ); ?></h3>
				<p><?php echo esc_html__( 'Estado de Configuración:', 'dolisync' ); ?> <?php echo $is_configured ? esc_html__( 'Configurado', 'dolisync' ) : esc_html__( 'No Configurado', 'dolisync' ); ?></p>
                <?php 
                $timestamp = $last_test_timestamp; 
                ?>
				<p><strong><?php echo esc_html__( 'Última Prueba: ', 'dolisync' ); ?></strong><span id="dolisync-last-check-value" data-timestamp="<?php echo esc_attr( $timestamp ); ?>"><?php echo ! empty( $last_test['timestamp'] ) ? esc_html( human_time_diff( $timestamp, current_time( 'timestamp', true ) ) ) : esc_html__( 'sin fecha', 'dolisync' ); ?></span></p>
			</div>
		</div>
		<?php
	}

	private function render_contact_sync_tab() {
		$relations_data = $this->get_contact_relations();
		$relations = $relations_data['relations'];
		$has_first_synced = $relations_data['has_first_synced'];
		$has_source = $relations_data['has_source'];
		$nonce = wp_create_nonce( DOLISYNC_NONCE_ACTION );
		?>
		<div class="dolisync-tab-content">
			<h2><?php echo esc_html__( 'Sincronización de contactos', 'dolisync' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'Esta pantalla preparará la sincronización entre Dolibarr y WooCommerce. La API se conectará después; por ahora solo queda la interfaz y el registro local de relaciones.', 'dolisync' ); ?>
			</p>

			<div class="dolisync-sync-actions">
				<button type="button" class="button button-primary dolisync-sync-button" id="dolisync-sync-dolibarr-to-woo" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php echo esc_html__( 'Sincronizar Dolibarr → WooCommerce', 'dolisync' ); ?>
				</button>
				<button type="button" class="button button-secondary dolisync-sync-button" id="dolisync-sync-woo-to-dolibarr" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php echo esc_html__( 'Sincronizar WooCommerce → Dolibarr', 'dolisync' ); ?>
				</button>
			</div>

			<div id="dolisync-sync-result" style="margin-top: 20px; display: none;"></div>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Relaciones de contactos', 'dolisync' ); ?></h3>
				<p class="description">
					<?php echo esc_html__( 'La tabla muestra los enlaces locales entre un contacto de Dolibarr y su usuario equivalente en WooCommerce.', 'dolisync' ); ?>
				</p>

				<table class="widefat striped dolisync-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Dolibarr ID', 'dolisync' ); ?></th>
							<th><?php echo esc_html__( 'Woo User ID', 'dolisync' ); ?></th>
							<th><?php echo esc_html__( 'DNI', 'dolisync' ); ?></th>
							<th><?php echo esc_html__( 'Email', 'dolisync' ); ?></th>
							<th><?php echo esc_html__( 'Nombre', 'dolisync' ); ?></th>
							<th><?php echo esc_html__( 'Fecha de sincronización', 'dolisync' ); ?></th>
							<?php if ( $has_first_synced ) : ?>
								<th><?php echo esc_html__( 'Primera sincronización', 'dolisync' ); ?></th>
							<?php endif; ?>
							<?php if ( $has_source ) : ?>
								<th><?php echo esc_html__( 'Fuente original', 'dolisync' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $relations ) ) : ?>
							<?php foreach ( $relations as $relation ) : ?>
								<tr>
									<td><?php echo esc_html( (string) ( $relation->dolibarr_contact_id ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $relation->wp_user_id ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $relation->dni ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $relation->email ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $relation->first_name ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $relation->synced_at ?? '' ) ); ?></td>
									<?php if ( $has_first_synced ) : ?>
										<td><?php echo esc_html( (string) ( $relation->first_synced_at ?? '' ) ); ?></td>
									<?php endif; ?>
									<?php if ( $has_source ) : ?>
										<td><?php echo esc_html( (string) ( $relation->source ?? '' ) ); ?></td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
									   <td colspan="6"><?php echo esc_html__( 'Todavía no hay relaciones guardadas.', 'dolisync' ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private function render_product_sync_tab() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$filters = array(
			'status'  => isset( $_GET['product_status'] ) ? sanitize_text_field( wp_unslash( $_GET['product_status'] ) ) : '',
			'sku'     => isset( $_GET['product_sku'] ) ? sanitize_text_field( wp_unslash( $_GET['product_sku'] ) ) : '',
			'category' => isset( $_GET['product_category'] ) ? sanitize_text_field( wp_unslash( $_GET['product_category'] ) ) : '',
		);
		$relations = $this->get_product_relations( $filters );
		$category_relations = $this->get_product_category_mappings();
		$variation_relations = $this->get_product_variation_relations();
		$nonce = wp_create_nonce( DOLISYNC_NONCE_ACTION );
		?>
		<div class="dolisync-tab-content">
			<h2><?php echo esc_html__( 'Sincronizar productos', 'dolisync' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Sincronización paginada de catálogo, precios, categorías, imágenes y variaciones en ambos sentidos. Al crear un producto desde WooCommerce se importa su stock inicial una sola vez; después, el stock se actualiza exclusivamente desde Dolibarr hacia WooCommerce. Los pedidos viajan de WooCommerce a Dolibarr.', 'dolisync' ); ?></p>

			<div class="dolisync-sync-actions">
				<button type="button" class="button button-primary dolisync-sync-button" id="dolisync-sync-stock" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Sincronizar stock ahora', 'dolisync' ); ?></button>
				<button type="button" class="button button-primary dolisync-sync-button" id="dolisync-sync-products-dolibarr-to-woo" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Sincronizar Dolibarr → WooCommerce', 'dolisync' ); ?></button>
				<button type="button" class="button button-secondary dolisync-sync-button" id="dolisync-sync-products-woo-to-dolibarr" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Sincronizar WooCommerce → Dolibarr', 'dolisync' ); ?></button>
				<button type="button" class="button button-secondary dolisync-sync-button" id="dolisync-sync-product-categories" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Sincronizar categorías', 'dolisync' ); ?></button>
			</div>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Filtros', 'dolisync' ); ?></h3>
				<form method="get" action="">
					<input type="hidden" name="page" value="dolisync_settings">
					<input type="hidden" name="tab" value="product_sync">
					<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
						<div class="dolisync-form-group"><input class="dolisync-input" type="text" name="product_sku" placeholder="SKU" value="<?php echo esc_attr( $filters['sku'] ); ?>"></div>
						<div class="dolisync-form-group"><input class="dolisync-input" type="text" name="product_category" placeholder="Categoría" value="<?php echo esc_attr( $filters['category'] ); ?>"></div>
						<div class="dolisync-form-group">
							<select name="product_status" class="dolisync-select">
								<option value=""><?php echo esc_html__( 'Cualquiera', 'dolisync' ); ?></option>
								<option value="publish" <?php selected( $filters['status'], 'publish' ); ?>><?php echo esc_html__( 'Publicado', 'dolisync' ); ?></option>
								<option value="draft" <?php selected( $filters['status'], 'draft' ); ?>><?php echo esc_html__( 'Borrador', 'dolisync' ); ?></option>
								<option value="private" <?php selected( $filters['status'], 'private' ); ?>><?php echo esc_html__( 'Privado', 'dolisync' ); ?></option>
							</select>
						</div>
					</div>
					<div class="dolisync-form-actions"><button type="submit" class="button button-secondary"><?php echo esc_html__( 'Aplicar filtros', 'dolisync' ); ?></button></div>
				</form>
			</div>

			<div id="dolisync-product-sync-result" style="margin-top: 20px; display: none;"></div>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Relaciones de productos', 'dolisync' ); ?></h3>
				<?php $this->render_product_relations_table( $relations ); ?>
			</div>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Relaciones de categorías', 'dolisync' ); ?></h3>
				<?php $this->render_product_category_mappings_table( $category_relations ); ?>
			</div>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Relaciones de variaciones', 'dolisync' ); ?></h3>
				<?php $this->render_product_variation_relations_table( $variation_relations ); ?>
			</div>
		</div>
		<?php
	}

	private function render_logs_tab() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-logger.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';

		$logger = new Dolisync_Logger();
		$filters = array(
			'level'    => isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '',
			'endpoint' => isset( $_GET['endpoint'] ) ? sanitize_text_field( wp_unslash( $_GET['endpoint'] ) ) : '',
			'origin'   => isset( $_GET['origin'] ) ? sanitize_text_field( wp_unslash( $_GET['origin'] ) ) : '',
            'http_code' => isset( $_GET['http_code'] ) ? absint( wp_unslash( $_GET['http_code'] ) )	: '',
    		'keyword'  => isset( $_GET['keyword'] ) ? sanitize_text_field( wp_unslash( $_GET['keyword'] ) ) : '',
			'tipo'     => isset( $_GET['tipo'] ) ? sanitize_text_field( wp_unslash( $_GET['tipo'] ) ) : '',
			'accion'   => isset( $_GET['accion'] ) ? sanitize_text_field( wp_unslash( $_GET['accion'] ) ) : '',
			'estado'   => isset( $_GET['estado'] ) ? sanitize_text_field( wp_unslash( $_GET['estado'] ) ) : '',
		);

		$logs = $logger->get_logs( 100, 0, $filters );
		list( $api_logs ) = $this->partition_logs_by_category( $logs );

		// Cargar acciones desde la nueva tabla
		$action_filters = array();
		if ( ! empty( $filters['tipo'] ) ) {
			$action_filters['tipo'] = $filters['tipo'];
		}
		if ( ! empty( $filters['accion'] ) ) {
			$action_filters['accion'] = $filters['accion'];
		}
		if ( ! empty( $filters['estado'] ) ) {
			$action_filters['estado'] = $filters['estado'];
		}
		if ( ! empty( $filters['keyword'] ) ) {
			$action_filters['keyword'] = $filters['keyword'];
		}

		$actions = Dolisync_Action_Logger::get_actions( 100, 0, $action_filters );
		$stats = $logger->get_error_stats();
		?>
		<div class="dolisync-tab-content">
			<h2><?php echo esc_html__( 'Actividad y diagnóstico', 'dolisync' ); ?></h2>
			<p><?php echo esc_html__( 'Consulta qué se ha sincronizado y abre una fila para ver los detalles técnicos. Las credenciales y el contenido de los archivos se ocultan automáticamente.', 'dolisync' ); ?></p>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Resumen de errores', 'dolisync' ); ?></h3>
				<p><?php echo esc_html__( 'Total:', 'dolisync' ); ?> <?php echo esc_html( (string) ( $stats['error_count_total'] ?? 0 ) ); ?></p>
				<p><?php echo esc_html__( 'Últimas 24h:', 'dolisync' ); ?> <?php echo esc_html( (string) ( $stats['error_count_24h'] ?? 0 ) ); ?></p>
			</div>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Filtros', 'dolisync' ); ?></h3>
				<form method="get" action="">
					<input type="hidden" name="page" value="dolisync_settings">
					<input type="hidden" name="tab" value="logs">
					<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
						<div class="dolisync-form-group"><input class="dolisync-input" type="text" name="level" placeholder="INFO" value="<?php echo esc_attr( $filters['level'] ); ?>"></div>
						<div class="dolisync-form-group"><input class="dolisync-input" type="text" name="endpoint" placeholder="/orders" value="<?php echo esc_attr( $filters['endpoint'] ); ?>"></div>
						<div class="dolisync-form-group">
							<select name="origin" class="dolisync-select">
								<option value=""><?php echo esc_html__( 'Cualquiera', 'dolisync' ); ?></option>
								<option value="cron" <?php selected( $filters['origin'], 'cron' ); ?>><?php echo esc_html__( 'Cron', 'dolisync' ); ?></option>
								<option value="user" <?php selected( $filters['origin'], 'user' ); ?>><?php echo esc_html__( 'Usuario', 'dolisync' ); ?></option>
							</select>
						</div>
						<div class="dolisync-form-group"><input class="dolisync-input" type="number" name="http_code" placeholder="200" value="<?php echo esc_attr( (string) $filters['http_code'] ); ?>"></div>
						<div class="dolisync-form-group"><input class="dolisync-input" type="text" name="tipo" placeholder="contacto" value="<?php echo esc_attr( $filters['tipo'] ); ?>"></div>
						<div class="dolisync-form-group"><input class="dolisync-input" type="text" name="accion" placeholder="creación" value="<?php echo esc_attr( $filters['accion'] ); ?>"></div>
						<div class="dolisync-form-group">
							<select name="estado" class="dolisync-select">
								<option value=""><?php echo esc_html__( 'Cualquiera', 'dolisync' ); ?></option>
								<option value="finalizado" <?php selected( $filters['estado'], 'finalizado' ); ?>><?php echo esc_html__( 'Finalizado', 'dolisync' ); ?></option>
								<option value="error" <?php selected( $filters['estado'], 'error' ); ?>><?php echo esc_html__( 'Error', 'dolisync' ); ?></option>
							</select>
						</div>
						<div class="dolisync-form-group"><input class="dolisync-input" type="text" name="keyword" placeholder="error" value="<?php echo esc_attr( $filters['keyword'] ); ?>"></div>
					</div>
					<div class="dolisync-form-actions"><button type="submit" class="button button-secondary"><?php echo esc_html__( 'Aplicar filtros', 'dolisync' ); ?></button></div>
				</form>
			</div>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Llamadas a la API', 'dolisync' ); ?> <span class="dolisync-badge dolisync-level-info"><?php echo esc_html( (string) count( $api_logs ) ); ?></span></h3>
				<?php $this->render_logs_table( $api_logs, __( 'No hay llamadas a la API con los filtros actuales.', 'dolisync' ) ); ?>
			</div>

			<div class="dolisync-section">
				<h3><?php echo esc_html__( 'Acciones internas y BD', 'dolisync' ); ?> <span class="dolisync-badge dolisync-level-debug"><?php echo esc_html( (string) count( $actions ) ); ?></span></h3>
				<?php $this->render_actions_table( $actions, __( 'No hay acciones internas o de base de datos con los filtros actuales.', 'dolisync' ) ); ?>
			</div>
		</div>
		<?php
	}

	private function render_logs_table( $logs, $empty_message ) {
		?>
		<table class="widefat striped dolisync-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Fecha', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Nivel', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Endpoint', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'HTTP', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Tiempo', 'dolisync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $logs ) ) : ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr class="dolisync-log-row" data-log-id="<?php echo esc_attr( (string) $log['id'] ); ?>">
							<td><?php echo esc_html( $log['created_at'] ?? '' ); ?></td>
							<td>
								<span class="dolisync-badge dolisync-level-<?php echo esc_attr( strtolower( $log['log_level'] ?? 'info' ) ); ?>">
									<?php echo esc_html( $log['log_level'] ?? '' ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $log['http_method'] ?? '' ); ?> <?php echo esc_html( $log['endpoint'] ?? '' ); ?></td>
							<td><?php echo esc_html( (string) ( $log['http_code'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $log['response_time_ms'] ?? '' ) ); ?> ms</td>
						</tr>

						<tr class="dolisync-log-details" id="log-details-<?php echo esc_attr( (string) $log['id'] ); ?>">
							<td colspan="5">
								<div class="dolisync-log-split">
									<div class="dolisync-log-panel">
										<h4><?php echo esc_html__( 'Solicitud enviada', 'dolisync' ); ?></h4>
										<div class="dolisync-log-meta">
											<strong><?php echo esc_html__( 'Método:', 'dolisync' ); ?></strong>
											<?php echo esc_html( $log['http_method'] ?? '' ); ?>
										</div>
										<div class="dolisync-log-meta">
											<strong><?php echo esc_html__( 'Ruta:', 'dolisync' ); ?></strong>
											<?php echo esc_html( $log['endpoint'] ?? '' ); ?>
										</div>
										<div class="dolisync-log-meta">
											<strong><?php echo esc_html__( 'Ejecutado por:', 'dolisync' ); ?></strong>
											<?php
											$origin = (string) ( $log['origin'] ?? '' );
											$executor = (string) ( $log['executor'] ?? '' );
											$cron_interval = (string) ( $log['cron_interval'] ?? '' );

											if ( 'cron' === $origin ) {
												echo esc_html( 'cron' . ( '' !== $cron_interval ? '(' . $cron_interval . ')' : '' ) );
											} elseif ( 'user' === $origin ) {
												echo esc_html( 'manual' . ( '' !== $executor ? ' (' . $executor . ')' : '' ) );
											} elseif ( ! empty( $origin ) ) {
												echo esc_html( $origin );
											} elseif ( ! empty( $executor ) ) {
												echo esc_html( $executor );
											} else {
												echo esc_html( 'system' );
											}
											?>
										</div>
										<pre><?php
										$request_payload = $log['request_payload'] ?? '';
										$request_json = json_decode( $request_payload, true );
										if ( JSON_ERROR_NONE === json_last_error() ) {
											echo esc_html( wp_json_encode( $request_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
										} else {
											echo esc_html( $request_payload );
										}
										?></pre>
									</div>
									<div class="dolisync-log-panel">
										<h4><?php echo esc_html__( 'Respuesta recibida', 'dolisync' ); ?></h4>
										<div class="dolisync-log-meta"><strong>HTTP:</strong> <?php echo esc_html( (string) ( $log['http_code'] ?? '' ) ); ?></div>
										<?php if ( ! empty( $log['error_message'] ) ) : ?>
											<div class="dolisync-log-error"><?php echo esc_html( $log['error_message'] ); ?></div>
										<?php endif; ?>
										<pre><?php
										$response_body = $log['response_body'] ?? '';
										$response_json = json_decode( $response_body, true );
										if ( JSON_ERROR_NONE === json_last_error() ) {
											echo esc_html( wp_json_encode( $response_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
										} else {
											echo esc_html( $response_body );
										}
										?></pre>
									</div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="5"><?php echo esc_html( $empty_message ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_actions_table( $actions, $empty_message ) {
		?>
		<table class="widefat striped dolisync-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Fecha', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Tipo', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Acción', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Estado', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Usuario', 'dolisync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $actions ) ) : ?>
					<?php foreach ( $actions as $action ) : ?>
						<tr class="dolisync-action-row" data-action-id="<?php echo esc_attr( (string) $action['id'] ); ?>">
							<td><?php echo esc_html( $action['timestamp'] ?? '' ); ?></td>
							<td>
								<span class="dolisync-badge dolisync-badge-tipo">
									<?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) ( $action['tipo'] ?? '' ) ) ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) ( $action['accion'] ?? '' ) ) ) ); ?></td>
							<td>
								<span class="dolisync-badge dolisync-estado-<?php echo esc_attr( strtolower( $action['estado'] ?? 'finalizado' ) ); ?>">
									<?php echo esc_html( $action['estado'] ?? '' ); ?>
								</span>
							</td>
							<td><?php echo ! empty( $action['usuario_id'] ) ? esc_html( (string) $action['usuario_id'] ) : esc_html__( 'Sistema', 'dolisync' ); ?></td>
						</tr>

						<tr class="dolisync-action-details" id="action-details-<?php echo esc_attr( (string) $action['id'] ); ?>">
							<td colspan="5">
								<div class="dolisync-action-detail">
									<h4><?php echo esc_html__( 'Descripción', 'dolisync' ); ?></h4>
									<p><?php echo nl2br( esc_html( (string) ( $action['descripcion'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
									<div class="dolisync-action-meta">
										<strong><?php echo esc_html__( 'ID:', 'dolisync' ); ?></strong>
										<?php echo esc_html( (string) $action['id'] ); ?>
									</div>
									<div class="dolisync-action-meta">
										<strong><?php echo esc_html__( 'Creado:', 'dolisync' ); ?></strong>
										<?php echo esc_html( $action['created_at'] ?? '' ); ?>
									</div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="5"><?php echo esc_html( $empty_message ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function partition_logs_by_category( $logs ) {
		$api_logs = array();
		$internal_logs = array();

		foreach ( (array) $logs as $log ) {
			if ( $this->is_api_log_entry( $log ) ) {
				$api_logs[] = $log;
			} else {
				$internal_logs[] = $log;
			}
		}

		return array( $api_logs, $internal_logs );
	}

	private function is_api_log_entry( $log ) {
		$endpoint = trim( (string) ( $log['endpoint'] ?? '' ) );

		if ( '' === $endpoint ) {
			return false;
		}

		if ( 0 === strpos( $endpoint, '/sync/' ) ) {
			return false;
		}

		return true;
	}

	private function get_contact_relations() {
		global $wpdb;

		$table = $wpdb->prefix . 'dolisync_contact_relations';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $exists !== $table ) {
			return array(
				'relations' => array(),
				'has_first_synced' => false,
				'has_source' => false,
			);
		}

		// Comprobar columnas disponibles para construir SELECT seguro
		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$select_fields = array( 'dolibarr_contact_id', 'wp_user_id', 'dni', 'email', 'first_name', 'last_name', 'synced_at' );
		$has_first_synced = in_array( 'first_synced_at', $columns, true );
		$has_source = in_array( 'source', $columns, true );

		if ( $has_first_synced ) {
			$select_fields[] = 'first_synced_at';
		}
		if ( $has_source ) {
			$select_fields[] = 'source';
		}

		$fields_sql = implode( ',', $select_fields );
		$query = "SELECT {$fields_sql} FROM {$table} ORDER BY synced_at DESC LIMIT 100";

		$relations = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array(
			'relations' => is_array( $relations ) ? $relations : array(),
			'has_first_synced' => $has_first_synced,
			'has_source' => $has_source,
		);
	}

	private function get_product_relations( $filters = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'dolisync_product_relations';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $exists !== $table ) {
			return array();
		}

		$where = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$params[] = sanitize_text_field( $filters['status'] );
		}

		if ( ! empty( $filters['sku'] ) ) {
			$where[] = 'sku LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['sku'] ) . '%';
		}

		if ( ! empty( $filters['category'] ) ) {
			$where[] = 'categories_json LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['category'] ) . '%';
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY synced_at DESC LIMIT 100';

		if ( empty( $params ) ) {
			return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function get_product_category_mappings() {
		global $wpdb;

		$table = $wpdb->prefix . 'dolisync_product_category_mappings';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $exists !== $table ) {
			return array();
		}

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY synced_at DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function get_product_variation_relations() {
		global $wpdb;

		$table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $exists !== $table ) {
			return array();
		}

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY synced_at DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function render_product_relations_table( $relations ) {
		?>
		<table class="widefat striped dolisync-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Dolibarr ID', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Woo ID', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'SKU', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Nombre', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Precio', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Stock', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Categorías', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Estado', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Sincronizado', 'dolisync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $relations ) ) : ?>
					<?php foreach ( $relations as $relation ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $relation['dolibarr_product_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['wc_product_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['sku'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['price'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['stock_qty'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['categories_json'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['last_sync_status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['synced_at'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="9"><?php echo esc_html__( 'Todavía no hay relaciones de productos guardadas.', 'dolisync' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_product_category_mappings_table( $relations ) {
		?>
		<table class="widefat striped dolisync-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'ID categoría Dolibarr', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'ID categoría padre Dolibarr', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'ID categoría Woo', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'ID categoría padre Woo', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Nombre categoría', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Sincronizado', 'dolisync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $relations ) ) : ?>
					<?php foreach ( $relations as $relation ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $relation['dolibarr_category_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['dolibarr_parent_category_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['wc_category_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['wc_parent_category_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['category_name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['synced_at'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="6"><?php echo esc_html__( 'Todavía no hay categorías de producto mapeadas.', 'dolisync' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_product_variation_relations_table( $relations ) {
		?>
		<table class="widefat striped dolisync-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Dolibarr ID producto', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Woo ID producto', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Variación Dolibarr', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Variación Woo', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'SKU', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Precio', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Stock', 'dolisync' ); ?></th>
					<th><?php echo esc_html__( 'Sincronizado', 'dolisync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $relations ) ) : ?>
					<?php foreach ( $relations as $relation ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $relation['dolibarr_product_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['wc_product_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['dolibarr_variation_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['wc_variation_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['sku'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['price'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['stock_qty'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $relation['synced_at'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="8"><?php echo esc_html__( 'Todavía no hay variaciones de producto mapeadas.', 'dolisync' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function show_saved_notice() {
		if ( isset( $_GET['dolisync-saved'] ) ) {
			?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Configuración guardada exitosamente.', 'dolisync' ); ?></p></div>
			<?php
		}

		if ( isset( $_GET['dolisync-save-error'] ) ) {
			$error_code = sanitize_text_field( wp_unslash( $_GET['dolisync-save-error'] ) );
			$message = __( 'No se pudo guardar la configuración.', 'dolisync' );
			if ( 'missing_url' === $error_code ) {
				$message = __( 'Debes indicar una URL válida de Dolibarr antes de guardar.', 'dolisync' );
			} elseif ( 'database_error' === $error_code ) {
				$message = __( 'No se pudo escribir la configuración en la base de datos.', 'dolisync' );
			}
			?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php
		}
	}
}

