<?php
/**
 * Logger de DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Logger {
	private $config = array();
	private static $table_columns = null;
	private static $last_cleanup = 0;
	private const MAX_STORED_PAYLOAD_BYTES = 65535;

	private static $levels = array(
		'ERROR'   => 1,
		'WARNING' => 2,
		'INFO'    => 3,
		'DEBUG'   => 4,
		'TRACE'   => 5,
	);

	public function __construct() {
		if ( ! class_exists( 'Dolisync_Config' ) ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		}
		$this->config = Dolisync_Config::get_all();
	}

	public function log( $level, $endpoint, $method, $request_payload = null, $response_body = null, $http_code = null, $response_time_ms = null, $error_message = null, $origin = null, $executor = null, $cron_interval = null ) {
		if ( ! $this->should_log( $level ) ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_logs';

		$request_payload = $this->serialize_and_limit( $request_payload );
		$response_body = $this->serialize_and_limit( $response_body );
		$error_message = $this->limit_text( $error_message, 4000 );

		// Construimos insert dinámico solo con columnas existentes para evitar errores si la tabla no tiene los campos añadidos.
		if ( null === self::$table_columns ) {
			self::$table_columns = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$columns = (array) self::$table_columns;

		$insert_data = array(
			'log_level'        => strtoupper( $level ),
			'endpoint'         => (string) $endpoint,
			'http_method'      => strtoupper( $method ),
			'http_code'        => is_null( $http_code ) ? null : (int) $http_code,
			'request_payload'  => $request_payload,
			'response_body'    => $response_body,
			'response_time_ms' => is_null( $response_time_ms ) ? null : (int) $response_time_ms,
			'error_message'    => $error_message,
			'user_id'          => get_current_user_id() ?: null,
			'created_at'       => current_time( 'mysql' ),
		);

		if ( in_array( 'origin', $columns, true ) && null !== $origin ) {
			$insert_data['origin'] = (string) $origin;
		}

		if ( in_array( 'executor', $columns, true ) && null !== $executor ) {
			$insert_data['executor'] = (string) $executor;
		}

		if ( in_array( 'cron_interval', $columns, true ) && null !== $cron_interval ) {
			$insert_data['cron_interval'] = (string) $cron_interval;
		}

		$inserted = $wpdb->insert( $table, $insert_data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false !== $inserted ) {
			// Una limpieza por petición es suficiente y evita un DELETE tras cada llamada API.
			if ( 0 === self::$last_cleanup ) {
				self::$last_cleanup = time();
				$this->cleanup_old_logs();
			}
			if ( 'ERROR' === strtoupper( $level ) ) {
				$this->update_error_stats( $http_code );
			}
		}

		return false !== $inserted ? $wpdb->insert_id : false;
	}

	public function log_error( $endpoint, $method, $request_payload = null, $response_body = null, $http_code = null, $response_time_ms = null, $error_message = null ) {
		return $this->log( 'ERROR', $endpoint, $method, $request_payload, $response_body, $http_code, $response_time_ms, $error_message );
	}

	public function log_warning( $endpoint, $method, $request_payload = null, $response_body = null, $http_code = null, $response_time_ms = null, $error_message = null ) {
		return $this->log( 'WARNING', $endpoint, $method, $request_payload, $response_body, $http_code, $response_time_ms, $error_message );
	}

	public function log_info( $endpoint, $method, $request_payload = null, $response_body = null, $http_code = null, $response_time_ms = null, $error_message = null ) {
		return $this->log( 'INFO', $endpoint, $method, $request_payload, $response_body, $http_code, $response_time_ms, $error_message );
	}

	public function log_debug( $endpoint, $method, $request_payload = null, $response_body = null, $http_code = null, $response_time_ms = null, $error_message = null ) {
		return $this->log( 'DEBUG', $endpoint, $method, $request_payload, $response_body, $http_code, $response_time_ms, $error_message );
	}

	public function log_trace( $endpoint, $method, $request_payload = null, $response_body = null, $http_code = null, $response_time_ms = null, $error_message = null ) {
		return $this->log( 'TRACE', $endpoint, $method, $request_payload, $response_body, $http_code, $response_time_ms, $error_message );
	}

	public function should_log( $level ) {
		if ( empty( $this->config['logs_enabled'] ) ) {
			return false;
		}

		$min_level = self::$levels[ $this->config['log_level'] ?? 'INFO' ] ?? 3;
		$current_level = self::$levels[ strtoupper( $level ) ] ?? 3;

		return $current_level <= $min_level;
	}

	public function cleanup_old_logs() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_logs';
		$retention_days = max( 1, (int) ( $this->config['log_retention_days'] ?? 7 ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < (NOW() - INTERVAL %d DAY)", $retention_days ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function update_error_stats( $http_code = null ) {
		global $wpdb;
		$logs_table = $wpdb->prefix . 'dolisync_logs';
		$stats_table = $wpdb->prefix . 'dolisync_error_stats';

		if ( ! $this->table_exists( $stats_table ) ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
			Dolisync_Schema::ensure_error_stats_table();
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table} WHERE log_level = 'ERROR'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count_24h = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table} WHERE log_level = 'ERROR' AND created_at >= (NOW() - INTERVAL 24 HOUR)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$by_code_rows = $wpdb->get_results( "SELECT http_code, COUNT(*) AS total FROM {$logs_table} WHERE log_level = 'ERROR' GROUP BY http_code", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$by_type = array();

		foreach ( (array) $by_code_rows as $row ) {
			$key = (string) ( $row['http_code'] ?? '0' );
			$by_type[ $key ] = (int) ( $row['total'] ?? 0 );
		}

		$existing = $wpdb->get_var( "SELECT id FROM {$stats_table} ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$data = array(
			'error_count_total'   => $total,
			'error_count_24h'     => $count_24h,
			'error_count_by_type' => wp_json_encode( $by_type ),
			'updated_at'          => current_time( 'mysql' ),
		);

		if ( empty( $existing ) ) {
			$wpdb->insert( $stats_table, array_merge( array( 'id' => 1 ), $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->update( $stats_table, $data, array( 'id' => (int) $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	private function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function serialize_and_limit( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		}
		return $this->limit_text( $value, self::MAX_STORED_PAYLOAD_BYTES );
	}

	private function limit_text( $value, $max_bytes ) {
		if ( null === $value ) {
			return null;
		}
		$value = (string) $value;
		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}
		return substr( $value, 0, max( 0, $max_bytes - 80 ) ) . sprintf( '\n[Registro truncado: %d bytes omitidos]', strlen( $value ) - $max_bytes );
	}

	public function get_error_stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_error_stats';

		if ( ! $this->table_exists( $table ) ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
			Dolisync_Schema::ensure_error_stats_table();
		}

		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $row ? $row : array( 'error_count_total' => 0, 'error_count_24h' => 0, 'error_count_by_type' => wp_json_encode( array() ) );
	}

	public function get_logs( $limit = 50, $offset = 0, $filters = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_logs';
		$where = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['level'] ) ) {
			$where[] = 'log_level = %s';
			$params[] = strtoupper( $filters['level'] );
		}
		if ( ! empty( $filters['endpoint'] ) ) {
			$where[] = 'endpoint LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['endpoint'] ) . '%';
		}
		if ( ! empty( $filters['http_code'] ) ) {
			$where[] = 'http_code = %d';
			$params[] = (int) $filters['http_code'];
		}
		if ( ! empty( $filters['keyword'] ) ) {
			$where[] = '(request_payload LIKE %s OR response_body LIKE %s OR error_message LIKE %s)';
			$needle = '%' . $wpdb->esc_like( $filters['keyword'] ) . '%';
			$params[] = $needle;
			$params[] = $needle;
			$params[] = $needle;
		}

		if ( ! empty( $filters['origin'] ) ) {
			$where[] = 'origin = %s';
			$params[] = sanitize_text_field( $filters['origin'] );
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
		$params[] = (int) $limit;
		$params[] = (int) $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function get_logs_count( $filters = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_logs';
		$where = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['level'] ) ) {
			$where[] = 'log_level = %s';
			$params[] = strtoupper( $filters['level'] );
		}
		if ( ! empty( $filters['endpoint'] ) ) {
			$where[] = 'endpoint LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['endpoint'] ) . '%';
		}

		if ( ! empty( $filters['origin'] ) ) {
			$where[] = 'origin = %s';
			$params[] = sanitize_text_field( $filters['origin'] );
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
