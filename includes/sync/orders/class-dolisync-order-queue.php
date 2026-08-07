<?php
/** Cola asíncrona de pedidos para Dolibarr. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Order_Queue {
	private const HOOK = 'dolisync_process_order_queue';
	// Un intento inicial y tres reintentos: 1, 5 y 15 minutos.
	private const MAX_ATTEMPTS = 4;
	private const RETRY_DELAYS = array( 60, 300, 900 );

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 1 );
		add_action( 'init', array( __CLASS__, 'recover_stalled_jobs' ), 20 );
	}

	public static function recover_stalled_jobs() {
		if ( get_transient( 'dolisync_order_queue_watchdog' ) ) {
			return;
		}
		set_transient( 'dolisync_order_queue_watchdog', 1, MINUTE_IN_SECONDS );
		global $wpdb;
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		try {
			Dolisync_Schema::ensure_order_relations_table();
		} catch ( Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $e->getMessage(), array( 'source' => 'dolisync-order-queue' ) );
			}
			return;
		}
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$now = current_time( 'mysql' );
		$stale = wp_date( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS, wp_timezone() );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET sync_status = 'queued', queue_locked_at = NULL, queue_next_attempt_at = %s, updated_at = %s WHERE sync_status = 'processing' AND queue_locked_at < %s", $now, $now, $stale ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$order_ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT wc_order_id FROM {$table} WHERE sync_status = 'queued' AND (queue_next_attempt_at IS NULL OR queue_next_attempt_at <= %s) ORDER BY updated_at ASC LIMIT 20", $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $order_ids as $order_id ) {
			self::schedule( (int) $order_id, time() + 1 );
		}
	}

	public static function process_now( $order_id ) {
		$order_id = absint( $order_id );
		self::set_progress( $order_id, 'queued', __( 'Preparando el reintento manual…', 'dolisync' ) );
		self::enqueue( $order_id, true );
		$timestamp = wp_next_scheduled( self::HOOK, array( $order_id ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK, array( $order_id ) );
		}
		self::process( $order_id, true );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT sync_status, last_error_message FROM {$wpdb->prefix}dolisync_order_relations WHERE wc_order_id = %d", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 'success' !== (string) ( $row['sync_status'] ?? '' ) ) {
			throw new RuntimeException( (string) ( $row['last_error_message'] ?? __( 'La sincronización manual no se completó.', 'dolisync' ) ) );
		}
		return true;
	}

	public static function set_progress( $order_id, $stage, $message ) {
		set_transient(
			'dolisync_order_progress_' . absint( $order_id ),
			array( 'stage' => sanitize_key( $stage ), 'message' => sanitize_text_field( $message ), 'updated_at' => current_time( 'mysql' ) ),
			HOUR_IN_SECONDS
		);
	}

	public static function get_progress( $order_id ) {
		$progress = get_transient( 'dolisync_order_progress_' . absint( $order_id ) );
		return is_array( $progress ) ? $progress : array( 'stage' => 'pending', 'message' => __( 'Esperando el inicio del proceso…', 'dolisync' ), 'updated_at' => '' );
	}

	public static function enqueue( $order_id, $force = false ) {
		global $wpdb;
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		Dolisync_Schema::ensure_order_relations_table();
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT sync_status, queue_attempts FROM {$table} WHERE wc_order_id = %d", $order->get_id() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $force ) {
			$existing_status = (string) ( $existing['sync_status'] ?? '' );
			if ( in_array( $existing_status, array( 'success', 'processing', 'failed' ), true ) ) {
				return true;
			}
			if ( 'queued' === $existing_status ) {
				return true;
			}
		}
		$now = current_time( 'mysql' );
		$data = array(
			'wc_order_id' => $order->get_id(), 'wc_order_number' => $order->get_order_number(),
			'order_status' => $order->get_status(), 'sync_status' => 'queued',
			'last_error_message' => '', 'queue_next_attempt_at' => $now,
			'queue_locked_at' => null, 'updated_at' => $now,
		);
		if ( $force ) {
			$data['queue_attempts'] = 0;
		}
		if ( $existing ) {
			$saved = $wpdb->update( $table, $data, array( 'wc_order_id' => $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$data['queue_attempts'] = 0;
			$data['created_at'] = $now;
			$saved = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( false === $saved ) {
			throw new RuntimeException( __( 'No se pudo guardar el pedido en la cola local.', 'dolisync' ) );
		}
		if ( ! self::schedule( $order->get_id(), time() + 1 ) ) {
			throw new RuntimeException( __( 'WordPress no pudo programar el procesamiento del pedido.', 'dolisync' ) );
		}
		return true;
	}

	public static function process( $order_id, $manual = false ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$stale = wp_date( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS, wp_timezone() );
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET sync_status = 'processing', queue_locked_at = %s, queue_next_attempt_at = NULL, queue_attempts = queue_attempts + 1, updated_at = %s WHERE wc_order_id = %d AND sync_status IN ('queued','error') AND (queue_locked_at IS NULL OR queue_locked_at < %s)", current_time( 'mysql' ), current_time( 'mysql' ), $order_id, $stale ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $claimed ) {
			return;
		}
		self::set_progress( $order_id, 'starting', __( 'Iniciando la sincronización con Dolibarr…', 'dolisync' ) );
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			self::fail( $order_id, __( 'El pedido ya no existe en WooCommerce.', 'dolisync' ) );
			return;
		}
		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-order-sync.php';
			Dolisync_Order_Sync::sync_order( $order_id, $order );
			if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
				self::set_progress( $order_id, 'payment', __( 'Sincronizando el estado de pago…', 'dolisync' ) );
				Dolisync_Order_Sync::sync_invoice_payment_status( $order_id, $order->get_status() );
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_order_id = %d", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 'success' !== (string) ( $row['sync_status'] ?? '' ) ) {
				throw new RuntimeException( (string) ( $row['last_error_message'] ?? __( 'Dolibarr no completó la sincronización.', 'dolisync' ) ) );
			}
			$wpdb->update( $table, array( 'queue_locked_at' => null, 'queue_next_attempt_at' => null, 'updated_at' => current_time( 'mysql' ) ), array( 'wc_order_id' => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-invoice-pdf.php';
			self::set_progress( $order_id, 'email', __( 'Enviando la factura al cliente por email…', 'dolisync' ) );
			if ( ! Dolisync_Invoice_PDF::send_customer_invoice( $order ) ) {
				if ( $manual ) {
					throw new RuntimeException( __( 'No se pudo enviar el correo con la factura.', 'dolisync' ) );
				}
				// Dolisync_Invoice_PDF ya programa sus propios reintentos. No se debe
				// reintentar también el pedido completo porque duplicaría el correo.
				self::set_progress( $order_id, 'email_retry', __( 'Factura sincronizada; el email queda en cola para reintento.', 'dolisync' ) );
				return;
			}
			self::set_progress( $order_id, 'complete', __( 'Factura sincronizada y enviada correctamente.', 'dolisync' ) );
		} catch ( Throwable $e ) {
			self::fail( $order_id, $e->getMessage(), $manual );
		}
	}

	private static function fail( $order_id, $message, $manual = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT queue_attempts FROM {$table} WHERE wc_order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $manual || $attempts >= self::MAX_ATTEMPTS ) {
			$wpdb->update( $table, array( 'sync_status' => 'failed', 'last_error_message' => sanitize_text_field( $message ), 'queue_locked_at' => null, 'queue_next_attempt_at' => null, 'updated_at' => current_time( 'mysql' ) ), array( 'wc_order_id' => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$order = wc_get_order( $order_id );
			if ( ! $manual && $order instanceof WC_Order ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-invoice-pdf.php';
				Dolisync_Invoice_PDF::send_invoice_unavailable_notice( $order );
			}
			self::set_progress( $order_id, 'failed', sprintf( __( 'Error: %s', 'dolisync' ), $message ) );
			return;
		}
		$delay = self::RETRY_DELAYS[ max( 0, $attempts - 1 ) ] ?? 900;
		$timestamp = time() + $delay;
		$next = wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() );
		$wpdb->update( $table, array( 'sync_status' => 'queued', 'last_error_message' => sanitize_text_field( $message ), 'queue_locked_at' => null, 'queue_next_attempt_at' => $next, 'updated_at' => current_time( 'mysql' ) ), array( 'wc_order_id' => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::schedule( $order_id, $timestamp );
	}

	private static function schedule( $order_id, $timestamp ) {
		$args = array( (int) $order_id );
		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			$result = wp_schedule_single_event( $timestamp, self::HOOK, $args, true );
			return ! is_wp_error( $result ) && false !== $result;
		}
		return true;
	}
}
