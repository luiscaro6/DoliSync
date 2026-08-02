<?php
/**
 * Gestor de base de datos de DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_DB_Manager {
	public function get_table_name( $table_name ) {
		global $wpdb;
		return $wpdb->prefix . 'dolisync_' . sanitize_key( $table_name );
	}

	public function run_migrations() {
		$migrations_dir = DOLISYNC_PLUGIN_DIR . 'includes/database/migrations/';
		$migration_files = glob( $migrations_dir . '*.php' );
		$errors = array();

		if ( empty( $migration_files ) ) {
			return array(
				'success' => true,
				'errors'  => array(),
			);
		}

		sort( $migration_files );

		foreach ( $migration_files as $file ) {
			require_once $file;
			$function_name = 'dolisync_migration_' . str_replace( array( '.php', '-' ), array( '', '_' ), basename( $file ) );

			if ( function_exists( $function_name ) ) {
				$result = call_user_func( $function_name );
				if ( false === $result ) {
					$errors[] = sprintf( 'Error ejecutando %s', basename( $file ) );
				}
			} else {
				$errors[] = sprintf( 'No existe la función de migración para %s', basename( $file ) );
			}
		}

		update_option( 'dolisync_migrations_result', array( 'success' => empty( $errors ), 'errors' => $errors ) );

		return array(
			'success' => empty( $errors ),
			'errors'  => $errors,
		);
	}

	public function table_exists( $table_name ) {
		global $wpdb;
		$table = $this->get_table_name( $table_name );
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function get_tables_info() {
		global $wpdb;
		$tables = array( 'config', 'logs', 'error_stats' );
		$info = array();

		foreach ( $tables as $table_name ) {
			$table = $this->get_table_name( $table_name );
			$exists = $this->table_exists( $table_name );
			$info[ $table_name ] = array(
				'exists'    => $exists,
				'row_count'  => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0, // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'table_name' => $table,
			);
		}

		return $info;
	}
}
