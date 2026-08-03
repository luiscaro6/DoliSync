<?php
/**
 * Desinstalación del plugin DoliSync.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Las tareas y cachés de ejecución nunca deben sobrevivir al plugin.
foreach ( array( 'dolisync_logs_cache', 'dolisync_config_cache', 'dolisync_admin_notices', 'dolisync_stock_sync_lock' ) as $transient ) {
	delete_transient( $transient );
}

foreach ( array( 'dolisync_hourly_sync', 'dolisync_cleanup_logs', 'dolisync_connection_autocheck', 'dolisync_product_autosync', 'dolisync_stock_autosync', 'dolisync_stock_autosync_batch', 'dolisync_retry_invoice_delivery', 'dolisync_retry_invoice_email' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

$config_table = $wpdb->prefix . 'dolisync_config';
$retain_data = true;
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $config_table ) ) === $config_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$columns = (array) $wpdb->get_col( "DESCRIBE `{$config_table}`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( in_array( 'retain_data_on_uninstall', $columns, true ) ) {
		$stored_preference = $wpdb->get_var( "SELECT retain_data_on_uninstall FROM `{$config_table}` ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null !== $stored_preference ) {
			$retain_data = 1 === (int) $stored_preference;
		}
	}
}

// Instalaciones antiguas o incompletas conservan los datos por seguridad.
if ( $retain_data ) {
	exit;
}

/**
 * Elimina exclusivamente los directorios privados creados por DoliSync.
 *
 * @param string $directory Directorio validado dentro de uploads.
 */
function dolisync_uninstall_remove_private_directory( $directory ) {
	$items = scandir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $directory . DIRECTORY_SEPARATOR . $item;
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		} elseif ( is_dir( $path ) ) {
			dolisync_uninstall_remove_private_directory( $path );
		}
	}
	rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

$uploads = wp_upload_dir();
$uploads_base = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
if ( false !== $uploads_base ) {
	$private_directories = glob( $uploads_base . DIRECTORY_SEPARATOR . 'dolisync-private-*', GLOB_ONLYDIR );
	foreach ( false !== $private_directories ? $private_directories : array() as $private_directory ) {
		$resolved = realpath( $private_directory );
		$expected_prefix = $uploads_base . DIRECTORY_SEPARATOR . 'dolisync-private-';
		if ( false !== $resolved && 0 === strpos( $resolved, $expected_prefix ) ) {
			dolisync_uninstall_remove_private_directory( $resolved );
		}
	}
}

$tables = array(
	$wpdb->prefix . 'dolisync_config',
	$wpdb->prefix . 'dolisync_logs',
	$wpdb->prefix . 'dolisync_error_stats',
	$wpdb->prefix . 'dolisync_actions',
	$wpdb->prefix . 'dolisync_contact_relations',
	$wpdb->prefix . 'dolisync_product_relations',
	$wpdb->prefix . 'dolisync_order_relations',
	$wpdb->prefix . 'dolisync_ignored_items',
	$wpdb->prefix . 'dolisync_product_category_mappings',
	// Tabla conservada en instalaciones anteriores a la migración de categorías.
	$wpdb->prefix . 'dolisync_product_category_relations',
	$wpdb->prefix . 'dolisync_product_variation_relations',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

$options = array(
	'dolisync_version',
	'dolisync_activated',
	'dolisync_deactivated',
	'dolisync_migrations_result',
	'dolisync_lock_products_dolibarr_to_woo',
	'dolisync_lock_products_woo_to_dolibarr',
	'dolisync_lock_products_catalog',
	'dolisync_stock_sync_lock',
	'dolisync_onboarding_complete',
	'dolisync_onboarding_pending',
	'dolisync_cf_access_enabled',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

$meta_keys = array( 'dolisync_document_id', '_dolisync_last_import_hash', '_dolisync_last_export_hash', '_dolisync_dolibarr_document_key', '_dolisync_dolibarr_document_hash', '_dolisync_dolibarr_document_signature', '_dolisync_dolibarr_image_uploads', '_dolisync_dolibarr_invoice_pdf_path', '_dolisync_dolibarr_invoice_ref', '_dolisync_dolibarr_invoice_pdf_sent', '_dolisync_dolibarr_invoice_pdf_emailed_at', '_dolisync_dolibarr_invoice_pending_id', '_dolisync_dolibarr_invoice_pdf_retries', '_dolisync_dolibarr_invoice_lines_complete' );
foreach ( $meta_keys as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

delete_metadata( 'user', 0, 'dolisync_document_id', '', true );

// WooCommerce HPOS guarda los metadatos de pedidos fuera de wp_postmeta.
$orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta_table ) ) === $orders_meta_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$orders_meta_table}` WHERE meta_key IN ({$placeholders})", $meta_keys ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
