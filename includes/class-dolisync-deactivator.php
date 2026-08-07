<?php
/**
 * Desactivador del plugin DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Deactivator {
	public static function deactivate() {
		self::cancel_scheduled_events();
		self::clear_transients();
		update_option( 'dolisync_deactivated', current_time( 'mysql' ) );
	}

	private static function cancel_scheduled_events() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'dolisync_hourly_sync' );
			wp_clear_scheduled_hook( 'dolisync_cleanup_logs' );
			wp_clear_scheduled_hook( 'dolisync_connection_autocheck' );
			wp_clear_scheduled_hook( 'dolisync_product_autosync' );
			wp_clear_scheduled_hook( 'dolisync_stock_autosync' );
			wp_clear_scheduled_hook( 'dolisync_stock_autosync_batch' );
			wp_clear_scheduled_hook( 'dolisync_retry_invoice_delivery' );
			wp_clear_scheduled_hook( 'dolisync_retry_invoice_email' );
			wp_clear_scheduled_hook( 'dolisync_process_order_queue' );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'dolisync_retry_invoice_delivery', 'dolisync_retry_invoice_email', 'dolisync_process_order_queue' ) as $hook ) {
				as_unschedule_all_actions( $hook, array(), 'dolisync' );
			}
		}
	}

	private static function clear_transients() {
		delete_transient( 'dolisync_logs_cache' );
		delete_transient( 'dolisync_config_cache' );
		delete_transient( 'dolisync_stock_sync_lock' );
		delete_transient( 'dolisync_order_queue_watchdog' );
		delete_option( 'dolisync_stock_sync_lock' );
		delete_option( 'dolisync_lock_products_dolibarr_to_woo' );
		delete_option( 'dolisync_lock_products_woo_to_dolibarr' );
		delete_option( 'dolisync_lock_products_catalog' );
	}
}
