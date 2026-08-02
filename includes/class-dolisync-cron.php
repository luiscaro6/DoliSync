<?php
/**
 * Manejador de tareas programadas (WP-Cron) para DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dolisync_Cron {
    public static function init() {
        add_action( 'dolisync_connection_autocheck', array( __CLASS__, 'run_connection_check' ) );
		add_action( 'dolisync_stock_autosync', array( __CLASS__, 'run_stock_sync' ), 10, 2 );
		add_action( 'dolisync_stock_autosync_batch', array( __CLASS__, 'run_stock_sync' ), 10, 2 );

        // Añadimos schedules personalizados y aseguramos esquema de logs
        add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_schedules' ) );
		self::maybe_schedule_stock_sync();
		wp_clear_scheduled_hook( 'dolisync_product_autosync' );

        // Intentamos crear columnas adicionales en la tabla de logs para registrar origen/executor/interval
        if ( file_exists( DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php' ) ) {
            require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
            Dolisync_Schema::ensure_logs_table();
            Dolisync_Schema::ensure_log_columns();
        }
    }

    public static function add_custom_schedules( $schedules ) {
        $schedules['m1'] = array( 'interval' => 60, 'display' => __( 'Cada minuto', 'dolisync' ) );
        $schedules['m5'] = array( 'interval' => 300, 'display' => __( 'Cada 5 minutos', 'dolisync' ) );
        $schedules['m10'] = array( 'interval' => 600, 'display' => __( 'Cada 10 minutos', 'dolisync' ) );
		$schedules['m30'] = array( 'interval' => 1800, 'display' => __( 'Cada 30 minutos', 'dolisync' ) );
        return $schedules;
    }

    /**
     * Programa o desprograma la comprobación automática.
     *
     * @param string $interval 'off'|'hourly'|'twicedaily'|'daily'
     * @return void
     */
    public static function schedule( $interval ) {
        if ( ! in_array( $interval, array( 'off', 'hourly', 'twicedaily', 'daily' ), true ) ) {
            $interval = 'off';
        }

        // Limpiar cualquier tarea previa
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( 'dolisync_connection_autocheck' );
        }

        if ( 'off' === $interval ) {
            return;
        }

        if ( ! wp_next_scheduled( 'dolisync_connection_autocheck' ) ) {
            $timestamp = time();
            wp_schedule_event( $timestamp, $interval, 'dolisync_connection_autocheck' );
        }
    }

	public static function schedule_stock_sync( $interval ) {
		$allowed = array( 'off', 'm5', 'm10', 'm30', 'hourly' );
		$interval = in_array( $interval, $allowed, true ) ? $interval : 'off';
		wp_clear_scheduled_hook( 'dolisync_stock_autosync' );
		wp_clear_scheduled_hook( 'dolisync_stock_autosync_batch' );
		delete_option( 'dolisync_stock_sync_lock' );
		if ( 'off' !== $interval ) {
			wp_schedule_event( time() + 10, $interval, 'dolisync_stock_autosync' );
		}
	}

	private static function maybe_schedule_stock_sync() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$interval = Dolisync_Config::get_stock_sync_interval();
		if ( 'off' !== $interval && ! wp_next_scheduled( 'dolisync_stock_autosync' ) ) {
			wp_schedule_event( time() + 10, $interval, 'dolisync_stock_autosync' );
		}
	}

	public static function run_stock_sync( $offset = 0, $run_id = '' ) {
		$offset = max( 0, (int) $offset );
		$lock_key = 'dolisync_stock_sync_lock';
		$lock_value = (string) get_option( $lock_key, '' );
		list( $active_run, $lock_time ) = array_pad( explode( '|', $lock_value, 2 ), 2, 0 );
		if ( '' !== $active_run && ( time() - (int) $lock_time ) >= 15 * MINUTE_IN_SECONDS ) {
			delete_option( $lock_key );
			$active_run = '';
		}
		if ( 0 === $offset ) {
			if ( '' !== $active_run ) {
				return;
			}
			$run_id = wp_generate_uuid4();
			if ( ! add_option( $lock_key, $run_id . '|' . time(), '', false ) ) {
				return;
			}
		} elseif ( '' === $run_id || ! hash_equals( $active_run, (string) $run_id ) ) {
			return;
		}

		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-stock-sync.php';
			$sync = new Dolisync_Stock_Sync();
			$result = $sync->sync_batch( $offset );
			if ( ! empty( $result['has_more'] ) ) {
				update_option( $lock_key, $run_id . '|' . time(), false );
				$scheduled = wp_schedule_single_event( time() + 2, 'dolisync_stock_autosync_batch', array( (int) $result['next_offset'], $run_id ) );
				if ( false !== $scheduled && ! is_wp_error( $scheduled ) ) {
					return;
				}
				throw new Exception( 'No se pudo programar el siguiente lote de stock.' );
			}
		} catch ( Throwable $e ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
			Dolisync_Action_Logger::log_action( 'stock', 'sincronización_automática', 'error', sprintf( __( 'La sincronización automática de stock se interrumpió: %s', 'dolisync' ), $e->getMessage() ), null );
		}
		delete_option( $lock_key );
	}

    /**
     * Callback que ejecuta la comprobación de conexión y registra resultados.
     *
     * @return void
     */
    public static function run_connection_check() {
        require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
        require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
        require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-logger.php';

        $url = Dolisync_Config::get_dolibarr_url();
        $api_key = (string) Dolisync_Config::get( 'dolibarr_api_key', '' );

        if ( empty( $url ) || empty( $api_key ) ) {
            Dolisync_Config::set_connection_test_failed( __( 'Configuración incompleta.', 'dolisync' ) );
            return;
        }

        $client = new Dolisync_API_Client();
        $result = $client->test_connection();

        if ( 'unexpected_status' === ( $result['code'] ?? '' ) ) {
            Dolisync_Config::set_connection_test_warning(
                (string) ( $result['message'] ?? __( 'La conexión responde, pero la respuesta no parece provenir de Dolibarr.', 'dolisync' ) )
            );
            return;
        }

        if ( ! empty( $result['success'] ) ) {
            Dolisync_Config::set_connection_test_success( (int) ( $result['time_ms'] ?? 0 ) );
        } else {
            Dolisync_Config::set_connection_test_failed( (string) ( $result['message'] ?? __( 'Error desconocido.', 'dolisync' ) ) );
        }
    }

}

// Inicializar hooks
add_action( 'init', array( 'Dolisync_Cron', 'init' ) );
