<?php
/**
 * Administración de DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Admin {
	private static $instance = null;
    private $notices = array();

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submissions' ) );
	    add_action( 'admin_notices', array( $this, 'render_notices' ) );
        add_action(	'wp_ajax_dolisync_test_connection',	array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_dolisync_onboarding_save_test', array( $this, 'ajax_onboarding_save_test' ) );
		add_action( 'wp_ajax_dolisync_onboarding_complete', array( $this, 'ajax_onboarding_complete' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_onboarding' ), 5 );
        add_action( 'wp_ajax_dolisync_get_warehouses', array( $this, 'ajax_get_warehouses' ) );
        add_action( 'wp_ajax_dolisync_get_last_check_time', array( $this, 'ajax_get_last_check_time' ) );
    }

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function add_admin_menu() {
		if ( ! $this->user_can_access_settings() ) {
			return;
		}

		add_menu_page(
			__( 'DoliSync - Sincronización Dolibarr ↔ WooCommerce', 'dolisync' ),
			__( 'DoliSync', 'dolisync' ),
			'manage_options',
			'dolisync_settings',
			array( $this, 'render_page' ),
			'dashicons-swap',
			25
		);

		add_submenu_page(
			'dolisync_settings',
			__( 'Clientes · DoliSync', 'dolisync' ),
			__( 'Clientes', 'dolisync' ),
			'manage_options',
			'dolisync_customers',
			array( $this, 'render_customers_page' )
		);

		add_submenu_page(
			'dolisync_settings',
			__( 'Productos · DoliSync', 'dolisync' ),
			__( 'Productos', 'dolisync' ),
			'manage_options',
			'dolisync_products',
			array( $this, 'render_products_page' )
		);

		add_submenu_page(
			'dolisync_settings',
			__( 'Pedidos · DoliSync', 'dolisync' ),
			__( 'Pedidos', 'dolisync' ),
			'manage_options',
			'dolisync_orders',
			array( $this, 'render_orders_page' )
		);

		/* WordPress crea automáticamente la entrada del menú principal como primer
		 * submenú. La presentamos como "Ajustes" y la movemos al final. */
		global $submenu;
		if ( isset( $submenu['dolisync_settings'] ) && is_array( $submenu['dolisync_settings'] ) ) {
			$settings_item = null;
			foreach ( $submenu['dolisync_settings'] as $index => $item ) {
				if ( isset( $item[2] ) && 'dolisync_settings' === $item[2] ) {
					$settings_item = $item;
					unset( $submenu['dolisync_settings'][ $index ] );
					break;
				}
			}
			if ( null !== $settings_item ) {
				$settings_item[0] = __( 'Ajustes', 'dolisync' );
				$submenu['dolisync_settings'][] = $settings_item;
			}
		}
	}

	public function enqueue_admin_assets( $hook_suffix ) {
		$allowed_hooks = array( 'toplevel_page_dolisync_settings', 'dolisync_page_dolisync_products', 'dolisync_page_dolisync_customers', 'dolisync_page_dolisync_orders' );
		if ( ! in_array( $hook_suffix, $allowed_hooks, true ) || ! $this->user_can_access_settings() ) {
			return;
		}

        $admin_css = DOLISYNC_PLUGIN_DIR . 'assets/css/admin.css';
        $admin_js = DOLISYNC_PLUGIN_DIR . 'assets/js/admin.js';
        $admin_css_version = file_exists( $admin_css ) ? (string) filemtime( $admin_css ) : DOLISYNC_VERSION;
        $admin_js_version = file_exists( $admin_js ) ? (string) filemtime( $admin_js ) : DOLISYNC_VERSION;

        wp_enqueue_style( 'dolisync-admin', DOLISYNC_PLUGIN_URL . 'assets/css/admin.css', array(), $admin_css_version );
        wp_enqueue_script( 'dolisync-admin', DOLISYNC_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), $admin_js_version, true );

		wp_localize_script(
			'dolisync-admin',
			'DoliSync',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( DOLISYNC_NONCE_ACTION ),
				'textDomain' => 'dolisync',
			)
		);
	}

	public function handle_form_submissions() {
        if ( wp_doing_ajax() ) {
            return;
        }

        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
            : 'GET';

        if ( 'POST' !== $request_method ) {
            return;
        }

        if ( empty( $_POST['action'] ) || 0 !== strpos( sanitize_text_field( wp_unslash( $_POST['action'] ) ), 'dolisync_' ) ) {
            return;
        }

        if ( empty( $_POST['dolisync_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dolisync_nonce'] ) ), DOLISYNC_NONCE_ACTION ) ) {
            wp_die( esc_html__( 'Error de seguridad.', 'dolisync' ) );
        }

		if ( ! $this->user_can_access_settings() ) {
			wp_die( esc_html__( 'No tienes permiso para realizar esta acción.', 'dolisync' ) );
		}

		$action = sanitize_text_field( wp_unslash( $_POST['action'] ) );

		if ( 'dolisync_save_settings' === $action ) {
			$this->handle_save_settings();
		}
		if ( 'dolisync_save_warehouse' === $action ) {
			$this->handle_save_warehouse();
		}
		if ( 'dolisync_check_schema' === $action ) {
			$this->redirect_to_schema_result( 'checked' );
		}
		if ( 'dolisync_repair_schema' === $action ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
			try {
				$result = Dolisync_Schema::repair_schema();
				$this->redirect_to_schema_result( ! empty( $result['healthy'] ) ? 'repaired' : 'failed' );
			} catch ( Throwable $error ) {
				$this->add_notice( 'error', sprintf( __( 'No se pudo actualizar el esquema: %s', 'dolisync' ), $error->getMessage() ) );
				$this->redirect_to_schema_result( 'failed' );
			}
		}

	}

	public function maybe_redirect_to_onboarding() {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( 'dolisync_onboarding_pending' ) || wp_doing_ajax() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'dolisync_settings' !== $page ) {
			delete_option( 'dolisync_onboarding_pending' );
			wp_safe_redirect( admin_url( 'admin.php?page=dolisync_settings&onboarding=1' ) );
			exit;
		}
	}

	private function redirect_to_schema_result( $result ) {
		wp_safe_redirect( add_query_arg( 'dolisync-schema', sanitize_key( $result ), admin_url( 'admin.php?page=dolisync_settings&tab=health' ) ) );
		exit;
	}

	private function handle_save_settings() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';

		$dolibarr_url = isset( $_POST['dolibarr_url'] ) ? esc_url_raw( wp_unslash( $_POST['dolibarr_url'] ) ) : '';
		$dolibarr_api_key = isset( $_POST['dolibarr_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['dolibarr_api_key'] ) ) : '';
		$cf_access_enabled = isset( $_POST['cf_access_enabled'] ) ? 1 : 0;
		$cf_access_client_id = isset( $_POST['cf_access_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cf_access_client_id'] ) ) : '';
        $cf_access_client_secret = isset( $_POST['cf_access_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['cf_access_client_secret'] ) ) : '';
        $current_cf_access_headers = Dolisync_Config::get_cf_access_headers();
        $cf_access_headers = is_array( $current_cf_access_headers ) ? $current_cf_access_headers : array();

		if ( $cf_access_enabled && '' !== trim( $cf_access_client_id ) ) {
            $cf_access_headers['CF-Access-Client-Id'] = $cf_access_client_id;
        } else {
            unset( $cf_access_headers['CF-Access-Client-Id'] );
        }

		if ( $cf_access_enabled && '' !== trim( $cf_access_client_secret ) ) {
			$cf_access_headers['CF-Access-Client-Secret'] = $cf_access_client_secret;
		}
		if ( ! $cf_access_enabled ) {
			$cf_access_headers = array();
		}
		update_option( 'dolisync_cf_access_enabled', $cf_access_enabled, false );
		$logs_enabled = isset( $_POST['logs_enabled'] ) ? 1 : 0;
		$retain_data_on_uninstall = isset( $_POST['retain_data_on_uninstall'] ) ? 1 : 0;
        $allowed_levels = array( 'ERROR', 'WARNING', 'INFO', 'DEBUG', 'TRACE' );

        $log_level = isset( $_POST['log_level'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_POST['log_level'] ) ) )
            : 'INFO';

        if ( ! in_array( $log_level, $allowed_levels, true ) ) {
            $log_level = 'INFO';
        }
        $log_retention_days = isset( $_POST['log_retention_days'] )
            ? max( 1, absint( wp_unslash( $_POST['log_retention_days'] ) ) )
            : 7;
        $cron_interval = isset( $_POST['cron_interval'] ) ? sanitize_text_field( wp_unslash( $_POST['cron_interval'] ) ) : 'off';
        $allowed_cron = array( 'off', 'hourly', 'twicedaily', 'daily' );
        if ( ! in_array( $cron_interval, $allowed_cron, true ) ) {
            $cron_interval = 'off';
        }
		$stock_sync_interval = isset( $_POST['stock_sync_interval'] ) ? sanitize_text_field( wp_unslash( $_POST['stock_sync_interval'] ) ) : 'off';
		$allowed_stock_cron = array( 'off', 'm5', 'm10', 'm30', 'hourly' );
		if ( ! in_array( $stock_sync_interval, $allowed_stock_cron, true ) ) {
			$stock_sync_interval = 'off';
		}
		$tax_mapping = array();
		$submitted_tax_mapping = isset( $_POST['tax_mapping'] ) && is_array( $_POST['tax_mapping'] ) ? wp_unslash( $_POST['tax_mapping'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( $submitted_tax_mapping as $rate_id => $rate ) {
			$rate_id = absint( $rate_id );
			$rate = str_replace( ',', '.', sanitize_text_field( $rate ) );
			if ( $rate_id > 0 && is_numeric( $rate ) && (float) $rate >= 0 && (float) $rate <= 100 ) {
				$tax_mapping[ (string) $rate_id ] = (string) (float) $rate;
			}
		}
		$url_parts = wp_parse_url( $dolibarr_url );
		if ( '' === $dolibarr_url || empty( $url_parts['host'] ) || ! in_array( strtolower( (string) ( $url_parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || isset( $url_parts['user'] ) || isset( $url_parts['pass'] ) ) {
			$this->add_notice(
                'error',
                __( 'Debes indicar una URL válida.', 'dolisync' )
            );

            wp_safe_redirect(
                admin_url( 'admin.php?page=dolisync_settings&tab=settings' )
            );

            exit;
		}

		$config = array(
			'dolibarr_url'       => $dolibarr_url,
			'logs_enabled'       => $logs_enabled,
			'retain_data_on_uninstall' => $retain_data_on_uninstall,
			'log_level'          => $log_level,
            'log_retention_days' => $log_retention_days,
            'cron_interval'      => $cron_interval,
			'product_sync_interval' => 'off',
			'stock_sync_interval' => $stock_sync_interval,
			'tax_mapping'       => $tax_mapping,
            'cf_access_headers'  => $cf_access_headers,
		);

		if ( '' !== $dolibarr_api_key ) {
			$config['dolibarr_api_key'] = $dolibarr_api_key;
		}

		try {
			$saved = Dolisync_Config::set_multiple( $config );
		} catch ( Throwable $e ) {
			$saved = false;
			$this->add_notice( 'error', sprintf( __( 'No se pudo guardar la configuración: %s', 'dolisync' ), $e->getMessage() ) );
		}
		if ( ! $saved ) {
			wp_safe_redirect( admin_url( 'admin.php?page=dolisync_settings&tab=settings&dolisync-save-error=database_error' ) );
			exit;
		}

        require_once DOLISYNC_PLUGIN_DIR . 'includes/class-dolisync-cron.php';
        Dolisync_Cron::schedule( $cron_interval );
		wp_clear_scheduled_hook( 'dolisync_product_autosync' );
		Dolisync_Cron::schedule_stock_sync( $stock_sync_interval );

		$this->add_notice(
            'success',
            __( 'Configuración guardada correctamente.', 'dolisync' )
        );

        wp_safe_redirect(
            admin_url( 'admin.php?page=dolisync_settings&tab=settings' )
        );

        exit;
	}


	public function render_page() {
		if ( ! $this->user_can_access_settings() ) {
			wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'dolisync' ) );
		}

		require_once DOLISYNC_PLUGIN_DIR . 'includes/admin/class-dolisync-settings-page.php';
		$page = new Dolisync_Settings_Page();
		$page->render();
	}

	private function handle_save_warehouse() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$warehouse_id = isset( $_POST['warehouse_id'] ) ? absint( wp_unslash( $_POST['warehouse_id'] ) ) : 0;
		if ( $warehouse_id <= 0 ) {
			$this->add_notice( 'error', __( 'Selecciona un almacén de Dolibarr.', 'dolisync' ) );
		} else {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
			$response = ( new Dolisync_API_Client() )->get( '/warehouses/' . $warehouse_id );
			$warehouse = ! empty( $response['success'] ) ? (array) ( $response['data'] ?? array() ) : array();
			$status = (int) ( $warehouse['statut'] ?? $warehouse['status'] ?? 0 );
			if ( empty( $warehouse ) || 1 !== $status ) {
				$this->add_notice( 'error', __( 'El almacén seleccionado no existe o no está activo en Dolibarr.', 'dolisync' ) );
			} else {
				$warehouse_name = sanitize_text_field( (string) ( $warehouse['label'] ?? $warehouse['libelle'] ?? $warehouse['ref'] ?? sprintf( __( 'Almacén #%d', 'dolisync' ), $warehouse_id ) ) );
				if ( Dolisync_Config::set_multiple( array( 'warehouse_id' => $warehouse_id, 'warehouse_name' => $warehouse_name ) ) ) {
					$this->add_notice( 'success', __( 'Almacén guardado correctamente.', 'dolisync' ) );
				} else {
					$this->add_notice( 'error', __( 'No se pudo guardar el almacén.', 'dolisync' ) );
				}
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=dolisync_settings&tab=warehouses' ) );
		exit;
	}

	public function ajax_get_warehouses() {
		check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );
		if ( ! $this->user_can_access_settings() ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para consultar almacenes.', 'dolisync' ) ), 403 );
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		if ( ! Dolisync_Config::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Configura primero la URL y la clave API de Dolibarr.', 'dolisync' ) ), 400 );
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		$response = ( new Dolisync_API_Client() )->get( '/warehouses', array( 'sortfield' => 't.ref', 'sortorder' => 'ASC', 'limit' => 1000 ) );
		if ( empty( $response['success'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $response['message'] ?? __( 'No se pudieron obtener los almacenes.', 'dolisync' ) ) ) );
		}
		$data = $response['data'] ?? array();
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}
		$warehouses = array();
		foreach ( (array) $data as $warehouse ) {
			if ( is_object( $warehouse ) ) {
				$warehouse = (array) $warehouse;
			}
			$id = absint( $warehouse['id'] ?? $warehouse['rowid'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$name = trim( (string) ( $warehouse['label'] ?? $warehouse['libelle'] ?? $warehouse['ref'] ?? sprintf( __( 'Almacén #%d', 'dolisync' ), $id ) ) );
			$warehouses[] = array( 'id' => $id, 'name' => $name, 'ref' => sanitize_text_field( (string) ( $warehouse['ref'] ?? '' ) ), 'active' => 1 === (int) ( $warehouse['statut'] ?? $warehouse['status'] ?? 0 ) );
		}
		wp_send_json_success( array( 'warehouses' => $warehouses ) );
	}

	public function render_products_page() {
		if ( ! $this->user_can_access_settings() ) {
			wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'dolisync' ) );
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/admin/class-dolisync-products-page.php';
		$this->render_guarded_page( array( 'Dolisync_Products_Page', 'render' ) );
	}

	public function render_orders_page() {
		if ( ! $this->user_can_access_settings() ) {
			wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'dolisync' ) );
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/admin/class-dolisync-orders-page.php';
		$this->render_guarded_page( array( 'Dolisync_Orders_Page', 'render' ) );
	}

	public function render_customers_page() {
		if ( ! $this->user_can_access_settings() ) {
			wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'dolisync' ) );
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/admin/class-dolisync-customers-page.php';
		$this->render_guarded_page( array( 'Dolisync_Customers_Page', 'render' ) );
	}

	private function render_guarded_page( $callback ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$test = Dolisync_Config::get_last_connection_test();
		$ready = Dolisync_Config::is_configured() && 'success' === ( $test['status'] ?? 'pending' );
		if ( $ready ) {
			call_user_func( $callback );
			return;
		}
		ob_start();
		call_user_func( $callback );
		$content = ob_get_clean();
		echo '<div class="dolisync-connection-locked">' . $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="dolisync-lock-card"><span class="dashicons dashicons-warning"></span><h2>' . esc_html__( 'DoliSync no está conectado', 'dolisync' ) . '</h2><p>' . esc_html__( 'Configura el endpoint y completa una prueba de conexión correcta para utilizar esta sección.', 'dolisync' ) . '</p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=dolisync_settings&onboarding=1' ) ) . '">' . esc_html__( 'Configurar conexión', 'dolisync' ) . '</a></div></div>';
	}

	public function ajax_onboarding_save_test() {
		check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );
		if ( ! $this->user_can_access_settings() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'dolisync' ) ), 403 );
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$url_input = isset( $_POST['dolibarr_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['dolibarr_url'] ) ) ) : '';
		if ( '' !== $url_input && ! preg_match( '#^https?://#i', $url_input ) ) {
			$url_input = 'https://' . $url_input;
		}
		$url = esc_url_raw( $url_input );
		$key = isset( $_POST['dolibarr_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['dolibarr_api_key'] ) ) : '';
		if ( '' === $key ) {
			$key = Dolisync_Config::get_dolibarr_api_key();
		}
		$url_parts = wp_parse_url( $url );
		$url_is_valid = '' !== $url && ! empty( $url_parts['host'] ) && in_array( strtolower( (string) ( $url_parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) && ! isset( $url_parts['user'] ) && ! isset( $url_parts['pass'] );
		if ( ! $url_is_valid || '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Indica una URL y una clave API válidas.', 'dolisync' ) ) );
		}
		$headers = Dolisync_Config::get_cf_access_headers();
		$cf_enabled = ! empty( $_POST['cf_access_enabled'] );
		if ( $cf_enabled ) {
			$client_id = sanitize_text_field( wp_unslash( $_POST['cf_access_client_id'] ?? '' ) );
			$client_secret = sanitize_text_field( wp_unslash( $_POST['cf_access_client_secret'] ?? '' ) );
			if ( '' !== $client_id ) { $headers['CF-Access-Client-Id'] = $client_id; }
			if ( '' !== $client_secret ) { $headers['CF-Access-Client-Secret'] = $client_secret; }
		} else {
			$headers = array();
		}
		Dolisync_Config::set_multiple( array( 'dolibarr_url' => $url, 'dolibarr_api_key' => $key, 'cf_access_headers' => $headers ) );
		update_option( 'dolisync_cf_access_enabled', $cf_enabled ? 1 : 0, false );
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		$result = ( new Dolisync_API_Client() )->test_connection();
		if ( ! empty( $result['success'] ) && 'unexpected_status' !== ( $result['code'] ?? '' ) ) {
			Dolisync_Config::set_connection_test_success( (int) ( $result['time_ms'] ?? 0 ) );
		} else {
			Dolisync_Config::set_connection_test_failed( (string) ( $result['message'] ?? __( 'Error de conexión.', 'dolisync' ) ) );
		}
		wp_send_json_success( array( 'connected' => ! empty( $result['success'] ) && 'unexpected_status' !== ( $result['code'] ?? '' ), 'result' => $result ) );
	}

	public function ajax_onboarding_complete() {
		check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );
		if ( ! $this->user_can_access_settings() ) { wp_send_json_error( array(), 403 ); }
		update_option( 'dolisync_onboarding_complete', 1, false );
		delete_option( 'dolisync_onboarding_pending' );
		wp_send_json_success( array( 'redirect' => admin_url( 'admin.php?page=dolisync_products' ) ) );
	}

	private function user_can_access_settings() {
		return current_user_can( 'manage_options' );
	}

    private function add_notice( $type, $message ) {
        $notices = get_transient( 'dolisync_admin_notices' );

        if ( ! is_array( $notices ) ) {
            $notices = array();
        }

        $notices[] = array(
            'type'    => $type,
            'message' => $message,
        );

        set_transient( 'dolisync_admin_notices', $notices, 30 );
    }

    public function render_notices() {
        $notices = get_transient( 'dolisync_admin_notices' );

        if ( empty( $notices ) || ! is_array( $notices ) ) {
            return;
        }

        delete_transient( 'dolisync_admin_notices' );

        foreach ( $notices as $notice ) {

            $type = sanitize_html_class( $notice['type'] ?? 'info' );
            $message = $notice['message'] ?? '';
			$icons = array(
				'success' => 'yes-alt',
				'error'   => 'dismiss',
				'warning' => 'warning',
				'info'    => 'info-outline',
			);
			$icon = $icons[ $type ] ?? $icons['info'];

            ?>
            <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible dolisync-admin-notice">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?> dolisync-admin-notice-icon" aria-hidden="true"></span>
                <p><?php echo esc_html( $message ); ?></p>
            </div>
            <?php
        }
    }

    public function ajax_get_last_check_time() {
        check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );

        require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
        $last_test = Dolisync_Config::get_last_connection_test();

        if ( ! empty( $last_test['timestamp'] ) ) {
            $timestamp = strtotime( get_gmt_from_date( $last_test['timestamp'] ) );

            wp_send_json_success(
                array(
                    'timestamp' => $timestamp,
                    'time_ago'   => human_time_diff( $timestamp, current_time( 'timestamp', true ) ),
                )
            );
        }

        wp_send_json_error( array( 'message' => __( 'Sin fecha', 'dolisync' ) ) );
    }


    public function ajax_test_connection() {

        check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );

        if ( ! $this->user_can_access_settings() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'No autorizado.', 'dolisync' ),
                ),
                403
            );
        }

        require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
        require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';

        $url = Dolisync_Config::get_dolibarr_url();
        $api_key = Dolisync_Config::get_dolibarr_api_key();

        if ( empty( $url ) || empty( $api_key ) ) {

            wp_send_json_error(
                array(
                    'message' => __( 'Configuración incompleta.', 'dolisync' ),
                )
            );
        }

        $client = new Dolisync_API_Client();

        $result = $client->test_connection();

        if ( 'unexpected_status' === ( $result['code'] ?? '' ) ) {
            Dolisync_Config::set_connection_test_warning(
                (string) ( $result['message'] ?? __( 'La conexión responde, pero la respuesta no parece provenir de Dolibarr.', 'dolisync' ) )
            );

            wp_send_json_success(
                array(
                    'message'      => $result['message'] ?? __( 'La conexión responde, pero la respuesta no parece provenir de Dolibarr.', 'dolisync' ),
                    'time_ms'      => $result['time_ms'] ?? 0,
                    'notice_type'  => 'warning',
                    'warning_code' => 'unexpected_status',
                )
            );
        }

        if ( ! empty( $result['success'] ) ) {

            Dolisync_Config::set_connection_test_success(
                (int) ( $result['time_ms'] ?? 0 )
            );

            wp_send_json_success(
                array(
                    'message' => __( 'Conexión exitosa.', 'dolisync' ),
                    'time_ms' => $result['time_ms'] ?? 0,
                    'notice_type' => 'success',
                )
            );
        }

        Dolisync_Config::set_connection_test_failed(
            (string) ( $result['message'] ?? '' )
        );

        wp_send_json_error(
            array(
                'message' => $result['message'] ?? __( 'Error desconocido.', 'dolisync' ),
            )
        );
    }
}

