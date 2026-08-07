<?php
/**
 * Esquema y migraciones ligeras de DoliSync (comprobaciones rápidas de compatibilidad).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dolisync_Schema {
	/** Ejecuta una operación DDL y convierte cualquier fallo de MySQL en una excepción visible. */
	private static function execute_schema_query( $sql, $context ) {
		global $wpdb;
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			$error = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : __( 'MySQL no devolvió detalles del error.', 'dolisync' );
			throw new RuntimeException( sprintf( __( 'Error de esquema en %1$s: %2$s', 'dolisync' ), $context, $error ) );
		}
		return $result;
	}
	/**
	 * Devuelve el inventario de tablas y columnas que necesita DoliSync.
	 *
	 * Las definiciones se usan también para añadir columnas ausentes sin borrar
	 * ni recrear tablas que ya contienen datos.
	 */
	private static function get_expected_schema() {
		return array(
			'dolisync_config' => array(
				'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'dolibarr_url' => "VARCHAR(255) NOT NULL DEFAULT ''", 'dolibarr_api_key' => 'LONGTEXT NULL', 'cf_access_headers' => 'LONGTEXT NULL', 'last_connection_test' => 'DATETIME NULL DEFAULT NULL', 'connection_test_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'", 'last_error_message' => 'LONGTEXT NULL', 'cron_interval' => "VARCHAR(50) NULL DEFAULT 'off'", 'product_sync_interval' => "VARCHAR(50) NULL DEFAULT 'off'", 'stock_sync_interval' => "VARCHAR(50) NULL DEFAULT 'off'", 'warehouse_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'warehouse_name' => "VARCHAR(255) NULL DEFAULT ''", 'tax_mapping' => 'LONGTEXT NULL', 'logs_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1', 'log_level' => "VARCHAR(20) NOT NULL DEFAULT 'INFO'", 'log_retention_days' => 'INT(11) NOT NULL DEFAULT 7', 'retain_data_on_uninstall' => 'TINYINT(1) NOT NULL DEFAULT 1', 'first_synced_at' => 'DATETIME NULL DEFAULT NULL', 'source' => 'VARCHAR(50) NULL DEFAULT NULL', 'created_at' => 'DATETIME NULL DEFAULT NULL', 'updated_at' => 'DATETIME NULL DEFAULT NULL',
			),
			'dolisync_logs' => array(
				'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'log_level' => 'VARCHAR(20) NOT NULL', 'endpoint' => 'VARCHAR(255) NULL', 'http_method' => 'VARCHAR(10) NULL', 'http_code' => 'INT(11) NULL', 'request_payload' => 'LONGTEXT NULL', 'response_body' => 'LONGTEXT NULL', 'response_time_ms' => 'INT(11) NULL', 'error_message' => 'LONGTEXT NULL', 'user_id' => 'BIGINT(20) UNSIGNED NULL', 'origin' => 'VARCHAR(50) NULL', 'executor' => 'VARCHAR(100) NULL', 'cron_interval' => 'VARCHAR(50) NULL', 'correlation_id' => 'VARCHAR(64) NULL', 'created_at' => 'DATETIME NULL DEFAULT NULL',
			),
			'dolisync_error_stats' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL', 'error_count_total' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0', 'error_count_24h' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0', 'error_count_by_type' => 'LONGTEXT NULL', 'updated_at' => 'DATETIME NULL DEFAULT NULL' ),
			'dolisync_actions' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'tipo' => 'VARCHAR(50) NOT NULL', 'accion' => 'VARCHAR(100) NOT NULL', 'estado' => 'VARCHAR(20) NOT NULL', 'descripcion' => 'LONGTEXT NOT NULL', 'timestamp' => 'DATETIME NOT NULL', 'usuario_id' => 'BIGINT(20) UNSIGNED NULL', 'correlation_id' => 'VARCHAR(64) NULL', 'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP' ),
			'dolisync_contact_relations' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'dolibarr_contact_id' => 'BIGINT(20) UNSIGNED NOT NULL', 'wp_user_id' => 'BIGINT(20) UNSIGNED NOT NULL', 'dni' => 'VARCHAR(50) NOT NULL', 'email' => 'VARCHAR(190) NOT NULL', 'first_name' => "VARCHAR(100) NOT NULL DEFAULT ''", 'last_name' => "VARCHAR(100) NOT NULL DEFAULT ''", 'synced_at' => 'DATETIME NULL DEFAULT NULL', 'first_synced_at' => 'DATETIME NULL DEFAULT NULL', 'source' => 'VARCHAR(50) NULL DEFAULT NULL', 'created_at' => 'DATETIME NULL DEFAULT NULL', 'updated_at' => 'DATETIME NULL DEFAULT NULL' ),
			'dolisync_contact_conflicts' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'conflict_key' => 'VARCHAR(191) NOT NULL', 'direction' => 'VARCHAR(30) NOT NULL', 'conflict_type' => 'VARCHAR(50) NOT NULL', 'wp_user_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'dolibarr_contact_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'wp_data' => 'LONGTEXT NULL', 'dolibarr_data' => 'LONGTEXT NULL', 'message' => 'LONGTEXT NULL', 'status' => "VARCHAR(20) NOT NULL DEFAULT 'open'", 'resolution' => 'VARCHAR(20) NULL DEFAULT NULL', 'created_at' => 'DATETIME NULL DEFAULT NULL', 'updated_at' => 'DATETIME NULL DEFAULT NULL', 'resolved_at' => 'DATETIME NULL DEFAULT NULL', 'resolved_by' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL' ),
			'dolisync_product_relations' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'dolibarr_product_id' => 'BIGINT(20) UNSIGNED NOT NULL', 'wc_product_id' => 'BIGINT(20) UNSIGNED NOT NULL', 'sku' => "VARCHAR(190) NOT NULL DEFAULT ''", 'name' => "VARCHAR(255) NOT NULL DEFAULT ''", 'description' => 'LONGTEXT NULL', 'short_description' => 'LONGTEXT NULL', 'price' => 'DECIMAL(18,6) NULL DEFAULT NULL', 'currency' => 'VARCHAR(10) NULL DEFAULT NULL', 'stock_qty' => 'DECIMAL(18,6) NULL DEFAULT NULL', 'product_type' => 'VARCHAR(50) NULL DEFAULT NULL', 'status' => 'VARCHAR(20) NULL DEFAULT NULL', 'categories_json' => 'LONGTEXT NULL', 'image_url' => 'LONGTEXT NULL', 'last_sync_status' => 'VARCHAR(20) NULL DEFAULT NULL', 'last_error_message' => 'LONGTEXT NULL', 'first_synced_at' => 'DATETIME NULL DEFAULT NULL', 'synced_at' => 'DATETIME NULL DEFAULT NULL', 'created_at' => 'DATETIME NULL DEFAULT NULL', 'updated_at' => 'DATETIME NULL DEFAULT NULL' ),
			'dolisync_product_conflicts' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'conflict_key' => 'VARCHAR(191) NOT NULL', 'direction' => 'VARCHAR(30) NOT NULL', 'conflict_type' => 'VARCHAR(50) NOT NULL', 'wc_product_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'dolibarr_product_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'wc_data' => 'LONGTEXT NULL', 'dolibarr_data' => 'LONGTEXT NULL', 'message' => 'LONGTEXT NULL', 'status' => "VARCHAR(20) NOT NULL DEFAULT 'open'", 'resolution' => 'VARCHAR(20) NULL DEFAULT NULL', 'created_at' => 'DATETIME NULL DEFAULT NULL', 'updated_at' => 'DATETIME NULL DEFAULT NULL', 'resolved_at' => 'DATETIME NULL DEFAULT NULL', 'resolved_by' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL' ),
			'dolisync_order_relations' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'wc_order_id' => 'BIGINT(20) UNSIGNED NOT NULL', 'wc_order_number' => "VARCHAR(100) NOT NULL DEFAULT ''", 'dolibarr_thirdparty_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'dolibarr_order_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'dolibarr_invoice_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'order_status' => 'VARCHAR(50) NULL DEFAULT NULL', 'invoice_status' => 'VARCHAR(50) NULL DEFAULT NULL', 'sync_status' => 'VARCHAR(20) NULL DEFAULT NULL', 'last_error_message' => 'LONGTEXT NULL', 'queue_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'queue_next_attempt_at' => 'DATETIME NULL DEFAULT NULL', 'queue_locked_at' => 'DATETIME NULL DEFAULT NULL', 'invoice_ref' => 'VARCHAR(255) NULL DEFAULT NULL', 'invoice_pdf_path' => 'TEXT NULL', 'invoice_pdf_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'", 'invoice_pdf_downloaded_at' => 'DATETIME NULL DEFAULT NULL', 'invoice_pdf_last_error' => 'LONGTEXT NULL', 'invoice_email_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'", 'invoice_email_sent_at' => 'DATETIME NULL DEFAULT NULL', 'invoice_email_next_retry_at' => 'DATETIME NULL DEFAULT NULL', 'invoice_email_last_error' => 'LONGTEXT NULL', 'invoice_email_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'synced_at' => 'DATETIME NULL DEFAULT NULL', 'created_at' => 'DATETIME NULL DEFAULT NULL', 'updated_at' => 'DATETIME NULL DEFAULT NULL' ),
			'dolisync_ignored_items' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'resource_type' => 'VARCHAR(20) NOT NULL', 'wc_id' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0', 'dolibarr_id' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0', 'ignored_by' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0', 'ignored_at' => 'DATETIME NOT NULL', 'updated_at' => 'DATETIME NOT NULL' ),
			'dolisync_product_category_mappings' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'dolibarr_category_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'dolibarr_parent_category_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'wc_category_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'wc_parent_category_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'category_name' => "VARCHAR(255) NOT NULL DEFAULT ''", 'synced_at' => 'DATETIME NULL DEFAULT NULL', 'created_at' => 'DATETIME NULL DEFAULT NULL', 'updated_at' => 'DATETIME NULL DEFAULT NULL' ),
			'dolisync_product_variation_relations' => array( 'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'dolibarr_product_id' => 'BIGINT(20) UNSIGNED NOT NULL', 'wc_product_id' => 'BIGINT(20) UNSIGNED NOT NULL', 'dolibarr_variation_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'dolibarr_combination_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'wc_variation_id' => 'BIGINT(20) UNSIGNED NOT NULL', 'sku' => "VARCHAR(190) NOT NULL DEFAULT ''", 'price' => 'DECIMAL(18,6) NULL DEFAULT NULL', 'stock_qty' => 'DECIMAL(18,6) NULL DEFAULT NULL', 'attributes_json' => 'LONGTEXT NULL', 'synced_at' => 'DATETIME NULL DEFAULT NULL', 'created_at' => 'DATETIME NULL DEFAULT NULL', 'updated_at' => 'DATETIME NULL DEFAULT NULL' ),
		);
	}

	/** Comprueba, sin modificar datos, todas las tablas y columnas del plugin. */
	public static function get_schema_status() {
		global $wpdb;
		$details = array();
		$total_issues = 0;
		foreach ( self::get_expected_schema() as $suffix => $expected_columns ) {
			$table = $wpdb->prefix . $suffix;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$actual_columns = $exists ? (array) $wpdb->get_col( "DESCRIBE `{$table}`", 0 ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$missing = $exists ? array_values( array_diff( array_keys( $expected_columns ), $actual_columns ) ) : array_keys( $expected_columns );
			$total_issues += $exists ? count( $missing ) : 1;
			$details[] = array( 'table' => $table, 'exists' => $exists, 'missing_columns' => $missing );
		}
		return array( 'healthy' => 0 === $total_issues, 'issues' => $total_issues, 'tables' => $details );
	}

	/** Repara tablas o columnas ausentes sin eliminar ni reemplazar datos. */
	public static function repair_schema() {
		global $wpdb;
		self::ensure_config_table();
		self::ensure_logs_table();
		self::ensure_error_stats_table();
		self::ensure_actions_table();
		self::ensure_contact_relations_table();
		self::ensure_contact_conflicts_table();
		self::ensure_product_relations_table();
		self::ensure_product_conflicts_table();
		self::ensure_order_relations_table();
		self::ensure_ignored_items_table();
		self::ensure_product_category_mappings_table();
		self::ensure_product_variation_relations_table();
		self::ensure_config_columns();
		self::ensure_log_columns();
		self::ensure_product_variation_relation_columns();

		foreach ( self::get_expected_schema() as $suffix => $expected_columns ) {
			$table = $wpdb->prefix . $suffix;
			$actual_columns = (array) $wpdb->get_col( "DESCRIBE `{$table}`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( array_diff( array_keys( $expected_columns ), $actual_columns ) as $column ) {
				self::execute_schema_query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$expected_columns[$column]}", sprintf( 'añadir %s.%s', $table, $column ) );
			}
		}
		return self::get_schema_status();
	}
    /**
     * Asegura la tabla principal de configuración del plugin.
     */
    public static function ensure_config_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'dolisync_config';
        $charset_collate = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists === $table ) {
			self::ensure_contact_relation_schema( $table );
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            dolibarr_url VARCHAR(255) NOT NULL DEFAULT '',
            dolibarr_api_key LONGTEXT NULL,
            cf_access_headers LONGTEXT NULL,
            last_connection_test DATETIME NULL DEFAULT NULL,
            connection_test_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            last_error_message LONGTEXT NULL,
            cron_interval VARCHAR(50) NULL DEFAULT 'off',
			product_sync_interval VARCHAR(50) NULL DEFAULT 'off',
			stock_sync_interval VARCHAR(50) NULL DEFAULT 'off',
			warehouse_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			warehouse_name VARCHAR(255) NULL DEFAULT '',
			tax_mapping LONGTEXT NULL,
            logs_enabled TINYINT(1) NOT NULL DEFAULT 1,
            log_level VARCHAR(20) NOT NULL DEFAULT 'INFO',
            log_retention_days INT(11) NOT NULL DEFAULT 7,
            retain_data_on_uninstall TINYINT(1) NOT NULL DEFAULT 1,
            first_synced_at DATETIME NULL DEFAULT NULL,
            source VARCHAR(50) NULL DEFAULT NULL,
            created_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

		self::execute_schema_query( $sql, 'crear tabla de configuración' );
    }

    /**
     * Asegura la tabla de relación entre contactos de Dolibarr y usuarios de WooCommerce.
     */
    public static function ensure_contact_relations_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'dolisync_contact_relations';
        $charset_collate = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists === $table ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            dolibarr_contact_id BIGINT(20) UNSIGNED NOT NULL,
            wp_user_id BIGINT(20) UNSIGNED NOT NULL,
            dni VARCHAR(50) NOT NULL,
            email VARCHAR(190) NOT NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            synced_at DATETIME NULL DEFAULT NULL,
            first_synced_at DATETIME NULL DEFAULT NULL,
            source VARCHAR(50) NULL DEFAULT NULL,
            created_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY email (email),
            UNIQUE KEY dolibarr_contact_id (dolibarr_contact_id),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY dni (dni)
        ) {$charset_collate};";

		self::execute_schema_query( $sql, 'crear tabla de relaciones de contactos' );
    }

    private static function ensure_contact_relation_schema( $table ) {
        global $wpdb;

        $email_index = $wpdb->get_row( "SHOW INDEX FROM {$table} WHERE Key_name = 'email'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $email_index && '0' === (string) $email_index->Non_unique ) {
			self::execute_schema_query( "ALTER TABLE {$table} DROP INDEX email, ADD INDEX email (email)", 'normalizar índice de email de contactos' );
        }
    }

	/** Asegura el historial de conflictos de identidad de contactos. */
	public static function ensure_contact_conflicts_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_conflicts';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conflict_key VARCHAR(191) NOT NULL,
			direction VARCHAR(30) NOT NULL,
			conflict_type VARCHAR(50) NOT NULL,
			wp_user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			dolibarr_contact_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			wp_data LONGTEXT NULL,
			dolibarr_data LONGTEXT NULL,
			message LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			resolution VARCHAR(20) NULL DEFAULT NULL,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			resolved_at DATETIME NULL DEFAULT NULL,
			resolved_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY conflict_key (conflict_key),
			KEY status (status),
			KEY wp_user_id (wp_user_id),
			KEY dolibarr_contact_id (dolibarr_contact_id)
		) {$charset_collate};";
		self::execute_schema_query( $sql, 'crear tabla de conflictos de contactos' );
	}

    /**
     * Asegura la tabla principal de relaciones de productos.
     */
    public static function ensure_product_relations_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'dolisync_product_relations';
        $charset_collate = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists === $table ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            dolibarr_product_id BIGINT(20) UNSIGNED NOT NULL,
            wc_product_id BIGINT(20) UNSIGNED NOT NULL,
            sku VARCHAR(190) NOT NULL DEFAULT '',
            name VARCHAR(255) NOT NULL DEFAULT '',
            description LONGTEXT NULL,
            short_description LONGTEXT NULL,
            price DECIMAL(18,6) NULL DEFAULT NULL,
            currency VARCHAR(10) NULL DEFAULT NULL,
            stock_qty DECIMAL(18,6) NULL DEFAULT NULL,
            product_type VARCHAR(50) NULL DEFAULT NULL,
            status VARCHAR(20) NULL DEFAULT NULL,
            categories_json LONGTEXT NULL,
            image_url LONGTEXT NULL,
            last_sync_status VARCHAR(20) NULL DEFAULT NULL,
            last_error_message LONGTEXT NULL,
            first_synced_at DATETIME NULL DEFAULT NULL,
            synced_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dolibarr_product_id (dolibarr_product_id),
            UNIQUE KEY wc_product_id (wc_product_id),
            KEY sku (sku)
        ) {$charset_collate};";

		self::execute_schema_query( $sql, 'crear tabla de relaciones de productos' );
    }

	/** Asegura el registro de conflictos de identidad de productos. */
	public static function ensure_product_conflicts_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_conflicts';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conflict_key VARCHAR(191) NOT NULL,
			direction VARCHAR(30) NOT NULL,
			conflict_type VARCHAR(50) NOT NULL,
			wc_product_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			dolibarr_product_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			wc_data LONGTEXT NULL,
			dolibarr_data LONGTEXT NULL,
			message LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			resolution VARCHAR(20) NULL DEFAULT NULL,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			resolved_at DATETIME NULL DEFAULT NULL,
			resolved_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY (id), UNIQUE KEY conflict_key (conflict_key), KEY status (status),
			KEY wc_product_id (wc_product_id), KEY dolibarr_product_id (dolibarr_product_id)
		) {$charset_collate};";
		self::execute_schema_query( $sql, 'crear tabla de conflictos de productos' );
	}

    /**
     * Asegura la tabla de relaciones entre pedidos WooCommerce y Dolibarr.
     */
	public static function ensure_order_relations_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'dolisync_order_relations';
        $charset_collate = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists === $table ) {
			self::ensure_order_queue_columns();
			return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wc_order_id BIGINT(20) UNSIGNED NOT NULL,
            wc_order_number VARCHAR(100) NOT NULL DEFAULT '',
            dolibarr_thirdparty_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            dolibarr_order_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            dolibarr_invoice_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            order_status VARCHAR(50) NULL DEFAULT NULL,
            invoice_status VARCHAR(50) NULL DEFAULT NULL,
            sync_status VARCHAR(20) NULL DEFAULT NULL,
            last_error_message LONGTEXT NULL,
			queue_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			queue_next_attempt_at DATETIME NULL DEFAULT NULL,
			queue_locked_at DATETIME NULL DEFAULT NULL,
			invoice_ref VARCHAR(255) NULL DEFAULT NULL,
			invoice_pdf_path TEXT NULL,
			invoice_pdf_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			invoice_pdf_downloaded_at DATETIME NULL DEFAULT NULL,
			invoice_pdf_last_error LONGTEXT NULL,
			invoice_email_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			invoice_email_sent_at DATETIME NULL DEFAULT NULL,
			invoice_email_next_retry_at DATETIME NULL DEFAULT NULL,
			invoice_email_last_error LONGTEXT NULL,
			invoice_email_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            synced_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY wc_order_id (wc_order_id),
            UNIQUE KEY dolibarr_order_id (dolibarr_order_id),
            UNIQUE KEY dolibarr_invoice_id (dolibarr_invoice_id),
            KEY dolibarr_thirdparty_id (dolibarr_thirdparty_id)
        ) {$charset_collate};";

		self::execute_schema_query( $sql, 'crear tabla de relaciones de pedidos' );
    }

	public static function ensure_order_queue_columns() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$columns = (array) $wpdb->get_col( "DESCRIBE `{$table}`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$required = array(
			'queue_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0',
			'queue_next_attempt_at' => 'DATETIME NULL DEFAULT NULL',
			'queue_locked_at' => 'DATETIME NULL DEFAULT NULL',
		);
		foreach ( $required as $column => $definition ) {
			if ( ! in_array( $column, $columns, true ) ) {
				self::execute_schema_query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}", "añadir {$table}.{$column}" );
			}
		}
	}

	public static function ensure_ignored_items_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_ignored_items';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			resource_type VARCHAR(20) NOT NULL,
			wc_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			dolibarr_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ignored_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ignored_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY resource_item (resource_type, wc_id, dolibarr_id),
			KEY resource_type (resource_type)
		) {$charset_collate};";
		self::execute_schema_query( $sql, 'crear tabla de elementos omitidos' );
	}

    /**
     * Asegura la tabla global de mapeo de categorías.
     */
    public static function ensure_product_category_mappings_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'dolisync_product_category_mappings';
        $charset_collate = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists === $table ) {
            self::drop_legacy_product_category_relations_table();
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            dolibarr_category_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            dolibarr_parent_category_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            wc_category_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            wc_parent_category_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            category_name VARCHAR(255) NOT NULL DEFAULT '',
            synced_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dolibarr_category_id (dolibarr_category_id),
            UNIQUE KEY wc_category_id (wc_category_id),
            KEY dolibarr_parent_category_id (dolibarr_parent_category_id),
            KEY wc_parent_category_id (wc_parent_category_id)
        ) {$charset_collate};";

		self::execute_schema_query( $sql, 'crear tabla de categorías' );
        self::drop_legacy_product_category_relations_table();
    }

    /**
     * Elimina la tabla legacy de relaciones por producto/categoría si existe.
     */
    public static function drop_legacy_product_category_relations_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'dolisync_product_category_relations';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists === $table ) {
			self::execute_schema_query( "DROP TABLE {$table}", 'eliminar tabla antigua de categorías' );
        }
    }

    /**
     * Asegura la tabla de relaciones de variaciones de producto.
     */
    public static function ensure_product_variation_relations_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'dolisync_product_variation_relations';
        $charset_collate = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists === $table ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            dolibarr_product_id BIGINT(20) UNSIGNED NOT NULL,
            wc_product_id BIGINT(20) UNSIGNED NOT NULL,
            dolibarr_variation_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            dolibarr_combination_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            wc_variation_id BIGINT(20) UNSIGNED NOT NULL,
            sku VARCHAR(190) NOT NULL DEFAULT '',
            price DECIMAL(18,6) NULL DEFAULT NULL,
            stock_qty DECIMAL(18,6) NULL DEFAULT NULL,
            attributes_json LONGTEXT NULL,
            synced_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY wc_variation_id (wc_variation_id),
            KEY dolibarr_product_id (dolibarr_product_id),
            KEY wc_product_id (wc_product_id),
            KEY dolibarr_variation_id (dolibarr_variation_id),
            KEY dolibarr_combination_id (dolibarr_combination_id)
        ) {$charset_collate};";

		self::execute_schema_query( $sql, 'crear tabla de relaciones de variaciones' );
    }

    /**
     * Añade campos de trazabilidad de combinaciones a instalaciones existentes.
     */
    public static function ensure_product_variation_relation_columns() {
        global $wpdb;

        self::ensure_product_variation_relations_table();
        $table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$columns = (array) $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( ! in_array( 'dolibarr_combination_id', (array) $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN dolibarr_combination_id BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER dolibarr_variation_id, ADD KEY dolibarr_combination_id (dolibarr_combination_id)", 'añadir trazabilidad de combinaciones' );
        }
    }

    /**
     * Asegura columnas adicionales en la tabla de configuración.
     */
    public static function ensure_config_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'dolisync_config';

		self::ensure_config_table();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists !== $table ) {
            return;
        }

        $columns = (array) $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! in_array( 'retain_data_on_uninstall', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `retain_data_on_uninstall` TINYINT(1) NOT NULL DEFAULT 1 AFTER `log_retention_days`", 'añadir preferencia de conservación' );
		}

        // Añadir columna first_synced_at si no existe
        if ( ! in_array( 'first_synced_at', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `first_synced_at` DATETIME NULL DEFAULT NULL", 'añadir fecha de primera sincronización' );
        }

        // Añadir columna source si no existe
        if ( ! in_array( 'source', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `source` VARCHAR(50) NULL DEFAULT NULL", 'añadir origen de sincronización' );
        }
        if ( ! in_array( 'cron_interval', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `cron_interval` VARCHAR(50) NULL DEFAULT 'off' AFTER `last_error_message`", 'añadir intervalo de cron' );
        }

        if ( ! in_array( 'cf_access_headers', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `cf_access_headers` LONGTEXT NULL", 'añadir cabeceras de Cloudflare' );
        }

        if ( ! in_array( 'product_sync_interval', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `product_sync_interval` VARCHAR(50) NULL DEFAULT 'off' AFTER `cron_interval`", 'añadir intervalo de productos' );
        }

		if ( ! in_array( 'stock_sync_interval', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `stock_sync_interval` VARCHAR(50) NULL DEFAULT 'off' AFTER `product_sync_interval`", 'añadir intervalo de stock' );
		}

		if ( ! in_array( 'warehouse_id', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `warehouse_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `stock_sync_interval`", 'añadir almacén' );
		}
		if ( ! in_array( 'warehouse_name', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `warehouse_name` VARCHAR(255) NULL DEFAULT '' AFTER `warehouse_id`", 'añadir nombre de almacén' );
		}

		if ( ! in_array( 'tax_mapping', $columns, true ) ) {
			self::execute_schema_query( "ALTER TABLE {$table} ADD COLUMN `tax_mapping` LONGTEXT NULL AFTER `stock_sync_interval`", 'añadir mapeo fiscal' );
		}
    }

    /**
     * Asegura columnas adicionales en la tabla de logs.
     */
    public static function ensure_log_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'dolisync_logs';

        // Si la tabla no existe, no intentamos modificarla aquí.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists !== $table ) {
            return;
        }

        $columns = (array) $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $queries = array();

        if ( ! in_array( 'origin', $columns, true ) ) {
            $queries[] = "ALTER TABLE {$table} ADD COLUMN `origin` VARCHAR(50) NULL";
        }

        if ( ! in_array( 'executor', $columns, true ) ) {
            $queries[] = "ALTER TABLE {$table} ADD COLUMN `executor` VARCHAR(100) NULL";
        }

        if ( ! in_array( 'cron_interval', $columns, true ) ) {
            $queries[] = "ALTER TABLE {$table} ADD COLUMN `cron_interval` VARCHAR(50) NULL";
        }

        foreach ( $queries as $q ) {
			self::execute_schema_query( $q, 'actualizar tabla de logs' );
        }
    }

    /**
     * Asegura la tabla de logs.
     */
    public static function ensure_logs_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'dolisync_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists === $table ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            log_level VARCHAR(20) NOT NULL,
            endpoint VARCHAR(255) NULL,
            http_method VARCHAR(10) NULL,
            http_code INT(11) NULL,
            request_payload LONGTEXT NULL,
            response_body LONGTEXT NULL,
            response_time_ms INT(11) NULL,
            error_message LONGTEXT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            origin VARCHAR(50) NULL,
            executor VARCHAR(100) NULL,
            cron_interval VARCHAR(50) NULL,
            created_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id),
			KEY log_level (log_level),
			KEY http_code (http_code),
			KEY origin (origin),
			KEY created_at (created_at)
        ) {$charset_collate};";

		self::execute_schema_query( $sql, 'crear tabla de logs' );
    }

    /**
     * Asegura la tabla de estadísticas agregadas de errores HTTP.
     */
    public static function ensure_error_stats_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'dolisync_error_stats';
        $charset_collate = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists === $table ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL,
            error_count_total BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            error_count_24h BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            error_count_by_type LONGTEXT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

		self::execute_schema_query( $sql, 'crear tabla de estadísticas de errores' );
    }

    /**
     * Asegura la tabla de logs de acciones internas y BD.
     */
    public static function ensure_actions_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'dolisync_actions';
        $charset_collate = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $exists === $table ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo VARCHAR(50) NOT NULL,
            accion VARCHAR(100) NOT NULL,
            estado VARCHAR(20) NOT NULL,
            descripcion LONGTEXT NOT NULL,
            timestamp DATETIME NOT NULL,
            usuario_id BIGINT(20) UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tipo (tipo),
            KEY accion (accion),
            KEY estado (estado),
            KEY timestamp (timestamp),
            KEY usuario_id (usuario_id)
        ) {$charset_collate};";

		self::execute_schema_query( $sql, 'crear tabla de acciones' );
    }
}
