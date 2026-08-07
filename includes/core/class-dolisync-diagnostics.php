<?php
/** Diagnóstico operativo y exportación anonimizada. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Diagnostics {
	public static function get_summary() {
		global $wpdb;
		$logs = $wpdb->prefix . 'dolisync_logs';
		$orders = $wpdb->prefix . 'dolisync_order_relations';
		$has_logs = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs ) ) === $logs; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_orders = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders ) ) === $orders; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$errors_24h = $has_logs ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs} WHERE log_level = 'ERROR' AND created_at >= (NOW() - INTERVAL 24 HOUR)" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$avg_ms = $has_logs ? (int) $wpdb->get_var( "SELECT COALESCE(AVG(response_time_ms),0) FROM {$logs} WHERE created_at >= (NOW() - INTERVAL 24 HOUR)" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$failed_jobs = $has_orders ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} WHERE sync_status = 'failed'" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stalled_jobs = $has_orders ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders} WHERE sync_status = 'processing' AND queue_locked_at < %s", wp_date( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS, wp_timezone() ) ) ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array(
			'errors_24h'  => $errors_24h,
			'avg_time_ms' => $avg_ms,
			'failed_jobs' => $failed_jobs,
			'stalled_jobs'=> $stalled_jobs,
			'alert'       => $errors_24h >= 10 || $failed_jobs >= 5 || $stalled_jobs > 0,
		);
	}

	public static function get_anonymized_report() {
		global $wpdb;
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-migrations.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		$configured = false;
		if ( class_exists( 'Dolisync_Config' ) ) {
			$configured = Dolisync_Config::is_configured();
		}
		$schema = Dolisync_Schema::get_schema_status();
		foreach ( $schema['tables'] as &$table ) {
			$table['table'] = 0 === strpos( (string) $table['table'], $wpdb->prefix ) ? substr( (string) $table['table'], strlen( $wpdb->prefix ) ) : 'dolisync_table';
		}
		unset( $table );
		return array(
			'generated_at' => current_time( 'mysql' ),
			'dolisync'     => DOLISYNC_VERSION,
			'database'     => Dolisync_Migrations::get_status(),
			'schema'       => $schema,
			'wordpress'    => get_bloginfo( 'version' ),
			'woocommerce'  => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'php'          => PHP_VERSION,
			'configured'   => $configured,
			'https'        => is_ssl(),
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'action_scheduler' => function_exists( 'as_schedule_single_action' ),
			'openssl_gcm'  => class_exists( 'Dolisync_Encryption' ) && Dolisync_Encryption::is_available(),
			'operations'   => self::get_summary(),
		);
	}
}
