<?php
/**
 * Gestor de configuración de DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Config {
	private static $table_name = '';

	private static function init_table_name() {
		if ( empty( self::$table_name ) ) {
			global $wpdb;
			self::$table_name = $wpdb->prefix . 'dolisync_config';
			if ( file_exists( DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php' ) ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
				Dolisync_Schema::ensure_config_table();
				Dolisync_Schema::ensure_config_columns();
			}
		}
	}

	public static function get_defaults() {
		return array(
			'id'                    => 1,
			'dolibarr_url'          => '',
			'dolibarr_api_key'      => '',
			'cf_access_headers'     => '{}',
			'last_connection_test'   => null,
			'connection_test_status' => 'pending',
			'last_error_message'     => null,
			'cron_interval'          => 'off',
			'product_sync_interval'  => 'off',
			'stock_sync_interval'    => 'off',
			'warehouse_id'           => 0,
			'warehouse_name'         => '',
			'tax_mapping'            => '{}',
			'logs_enabled'           => 1,
			'log_level'              => 'INFO',
			'log_retention_days'     => 7,
			'retain_data_on_uninstall' => 1,
			'created_at'             => current_time( 'mysql' ),
			'updated_at'             => current_time( 'mysql' ),
		);
	}

	public static function get_all() {
		self::init_table_name();
		global $wpdb;
		$config = $wpdb->get_row( "SELECT * FROM " . self::$table_name . " ORDER BY id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $config ? $config : self::get_defaults();
	}

	public static function get( $key, $default = null ) {
		$config = self::get_all();
		return array_key_exists( $key, $config ) ? $config[ $key ] : $default;
	}

	public static function get_value( $key, $default = null ) {
		return self::get( $key, $default );
	}

	public static function set( $key, $value ) {
		return self::set_multiple( array( $key => $value ) );
	}

	public static function set_multiple( $config ) {
		self::init_table_name();
		global $wpdb;
		$columns = $wpdb->get_col( "DESCRIBE " . self::$table_name, 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! is_array( $columns ) || empty( $columns ) ) {
			return false;
		}

		$data = array();
		foreach ( $config as $key => $value ) {
			if ( in_array( $key, $columns, true ) ) {
				$data[ $key ] = self::validate_value( $key, $value );
			}
		}

		if ( empty( $data ) ) {
			return true;
		}

		$existing_id = $wpdb->get_var( "SELECT id FROM " . self::$table_name . " ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( empty( $existing_id ) ) {
			$insert_data = self::get_defaults();
			foreach ( $data as $key => $value ) {
				$insert_data[ $key ] = $value;
			}
			$insert_data = array_intersect_key( $insert_data, array_flip( $columns ) );
			$insert_data['id'] = 1;

			$inserted = $wpdb->insert( self::$table_name, $insert_data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( false !== $inserted ) {
				return true;
			}

			if ( array_key_exists( 'cf_access_headers', $insert_data ) ) {
				unset( $insert_data['cf_access_headers'] );
				$retry_inserted = $wpdb->insert( self::$table_name, $insert_data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false !== $retry_inserted;
			}

			return false;
		}

		$data = array_intersect_key( $data, array_flip( $columns ) );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::$table_name,
			$data,
			array( 'id' => (int) $existing_id )
		);

		if ( false !== $updated ) {
			return true;
		}

		if ( array_key_exists( 'cf_access_headers', $data ) ) {
			unset( $data['cf_access_headers'] );

			if ( empty( $data ) ) {
				return true;
			}

			$retry_updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::$table_name,
				$data,
				array( 'id' => (int) $existing_id )
			);

			return false !== $retry_updated;
		}

		return false;
	}

	private static function validate_value( $key, $value ) {
		switch ( $key ) {
			case 'dolibarr_url':
				return esc_url_raw( $value );
			case 'dolibarr_api_key':
				if ( empty( $value ) ) {
					return '';
				}
				require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-encryption.php';
				return Dolisync_Encryption::encrypt( (string) $value );
			case 'cf_access_headers':
				return self::sanitize_cf_access_headers( $value );
			case 'logs_enabled':
			case 'retain_data_on_uninstall':
				return (int) $value;
			case 'log_level':
				$levels = array( 'ERROR', 'WARNING', 'INFO', 'DEBUG', 'TRACE' );
				return in_array( $value, $levels, true ) ? $value : 'INFO';
			case 'log_retention_days':
				$value = (int) $value;
				return ( $value >= 1 && $value <= 365 ) ? $value : 7;
			case 'cron_interval':
				$allowed = array( 'off', 'hourly', 'twicedaily', 'daily' );
				return in_array( $value, $allowed, true ) ? $value : 'off';
			case 'product_sync_interval':
				$allowed = array( 'off', 'hourly', 'twicedaily', 'daily' );
				return in_array( $value, $allowed, true ) ? $value : 'off';
			case 'stock_sync_interval':
				$allowed = array( 'off', 'm5', 'm10', 'm30', 'hourly' );
				return in_array( $value, $allowed, true ) ? $value : 'off';
			case 'warehouse_id':
				return absint( $value );
			case 'warehouse_name':
				return sanitize_text_field( (string) $value );
			case 'tax_mapping':
				return self::sanitize_tax_mapping( $value );
			default:
				return $value;
		}
	}

	public static function get_dolibarr_url() {
		return (string) self::get( 'dolibarr_url', '' );
	}

	public static function get_dolibarr_api_key() {
		$encrypted = (string) self::get( 'dolibarr_api_key', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-encryption.php';
		$decrypted = Dolisync_Encryption::decrypt( $encrypted );
		return false === $decrypted ? '' : $decrypted;
	}

	public static function get_cron_interval() {
		return (string) self::get( 'cron_interval', 'off' );
	}

	public static function get_product_sync_interval() {
		return (string) self::get( 'product_sync_interval', 'off' );
	}

	public static function get_stock_sync_interval() {
		return (string) self::get( 'stock_sync_interval', 'off' );
	}

	public static function get_warehouse_id() {
		return absint( self::get( 'warehouse_id', 0 ) );
	}

	public static function get_tax_mapping() {
		$value = self::get( 'tax_mapping', '{}' );
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		return is_array( $value ) ? $value : array();
	}

	private static function sanitize_tax_mapping( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : array();
		}
		$mapping = array();
		foreach ( (array) $value as $rate_id => $rate ) {
			$rate_id = absint( $rate_id );
			$rate = str_replace( ',', '.', (string) $rate );
			if ( $rate_id > 0 && is_numeric( $rate ) ) {
				$rate = (float) $rate;
				if ( $rate >= 0 && $rate <= 100 ) {
					$mapping[ (string) $rate_id ] = self::format_tax_rate( $rate );
				}
			}
		}
		return wp_json_encode( $mapping );
	}

	private static function format_tax_rate( $rate ) {
		$formatted = number_format( (float) $rate, 4, '.', '' );
		return rtrim( rtrim( $formatted, '0' ), '.' );
	}

	public static function get_cf_access_headers() {
		$stored = self::get( 'cf_access_headers', '{}' );

		if ( is_array( $stored ) ) {
			return self::normalize_cf_access_headers_array( $stored );
		}

		if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
			return array();
		}

		$decoded = json_decode( $stored, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return self::normalize_cf_access_headers_array( $decoded );
	}

	public static function is_configured() {
		return '' !== self::get_dolibarr_url() && '' !== self::get_dolibarr_api_key();
	}

	public static function set_connection_test_success( $response_time_ms ) {
		return self::set_multiple(
			array(
				'last_connection_test'   => current_time( 'mysql' ),
				'connection_test_status' => 'success',
				'last_error_message'     => '',
			)
		);
	}

	public static function set_connection_test_warning( $warning_message ) {
		return self::set_multiple(
			array(
				'last_connection_test'   => current_time( 'mysql' ),
				'connection_test_status' => 'warning',
				'last_error_message'     => sanitize_text_field( $warning_message ),
			)
		);
	}

	public static function set_connection_test_failed( $error_message ) {
		return self::set_multiple(
			array(
				'last_connection_test'   => current_time( 'mysql' ),
				'connection_test_status' => 'failed',
				'last_error_message'     => sanitize_text_field( $error_message ),
			)
		);
	}

	public static function get_last_connection_test() {
		$config = self::get_all();
		return array(
			'timestamp'      => $config['last_connection_test'] ?? null,
			'status'         => $config['connection_test_status'] ?? 'pending',
			'error_message'  => $config['last_error_message'] ?? null,
		);
	}

	public static function reset() {
		self::init_table_name();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::$table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->insert( self::$table_name, self::get_defaults() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function sanitize_cf_access_headers( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			}
		}

		if ( ! is_array( $value ) ) {
			$value = array();
		}

		$normalized = self::normalize_cf_access_headers_array( $value );
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-encryption.php';
		foreach ( $normalized as $key => $plain_value ) {
			$encrypted = Dolisync_Encryption::encrypt( $plain_value );
			if ( false === $encrypted ) {
				throw new RuntimeException( __( 'El servidor no permite cifrar de forma segura las credenciales de Cloudflare.', 'dolisync' ) );
			}
			$normalized[ $key ] = $encrypted;
		}

		return wp_json_encode(
			$normalized,
			JSON_UNESCAPED_UNICODE |
			JSON_UNESCAPED_SLASHES |
			JSON_PARTIAL_OUTPUT_ON_ERROR
		);
	}

	private static function normalize_cf_access_headers_array( $headers ) {
		$allowed_keys = array(
			'CF-Access-Client-Id',
			'CF-Access-Client-Secret',
		);

		$normalized = array();

		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $headers[ $key ] ) ) {
				continue;
			}

			$value = trim( sanitize_text_field( (string) $headers[ $key ] ) );
			if ( '' !== $value ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-encryption.php';
				$decrypted = Dolisync_Encryption::decrypt( $value );
				if ( false !== $decrypted ) {
					$value = trim( (string) $decrypted );
				}
			}
			if ( '' !== $value ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}
}
