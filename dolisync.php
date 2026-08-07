<?php
/**
 * DoliSync - Sincronización Dolibarr CRM ↔ WooCommerce
 *
 * @package       DoliSync
 * @author        Luis Caro
 * @license       GPL-3.0-or-later
 * @wordpress-plugin
 * Plugin Name:   DoliSync
 * Plugin URI:    https://github.com/luiscaro6/DoliSync
 * Description:   Sincronización Dolibarr CRM ↔ WooCommerce vía API REST
 * Version:       1.0.0
 * Author:        Luis Caro	
 * License:       GPL-3.0-or-later
 * License URI:   https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:   dolisync
 * Domain Path:   /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DOLISYNC_VERSION' ) ) {
	define( 'DOLISYNC_VERSION', '1.0.0' );
}

if ( ! defined( 'DOLISYNC_PLUGIN_DIR' ) ) {
	define( 'DOLISYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'DOLISYNC_PLUGIN_URL' ) ) {
	define( 'DOLISYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'DOLISYNC_PLUGIN_BASENAME' ) ) {
	define( 'DOLISYNC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'DOLISYNC_NONCE_ACTION' ) ) {
	define( 'DOLISYNC_NONCE_ACTION', 'dolisync_admin' );
}

require_once DOLISYNC_PLUGIN_DIR . 'includes/class-dolisync-activator.php';
require_once DOLISYNC_PLUGIN_DIR . 'includes/class-dolisync-deactivator.php';
require_once DOLISYNC_PLUGIN_DIR . 'includes/class-dolisync-cron.php';
require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-migrations.php';

register_activation_hook( __FILE__, array( 'Dolisync_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Dolisync_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', 'dolisync_init' );
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

function dolisync_init() {
	load_plugin_textdomain( 'dolisync', false, dirname( DOLISYNC_PLUGIN_BASENAME ) . '/languages' );
	Dolisync_Migrations::maybe_migrate();

	if ( class_exists( 'WooCommerce' ) ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/woocommerce/class-dolisync-woocommerce-users.php';
		Dolisync_WooCommerce_Users::init();
	}
	if ( is_admin() && ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'DoliSync necesita WooCommerce activo para sincronizar clientes, productos, stock y pedidos.', 'dolisync' ) . '</p></div>';
		} );
	}

	if ( is_admin() ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/admin/class-dolisync-admin.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/admin/class-dolisync-products-page.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/admin/class-dolisync-orders-page.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/admin/class-dolisync-customers-page.php';
		Dolisync_Admin::get_instance();

		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/class-dolisync-sync-handler.php';
	}

	// Inicializar hooks del plugin (borrado de usuarios, etc.)
	require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-hooks.php';
	Dolisync_Hooks::init();
}
