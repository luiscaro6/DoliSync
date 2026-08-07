<?php
/** Hooks y handlers genéricos de DoliSync. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Hooks {
	public static function init() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-order-queue.php';
		Dolisync_Order_Queue::init();
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-invoice-pdf.php';
		Dolisync_Invoice_PDF::init();
		add_action( 'delete_user', array( __CLASS__, 'on_wp_user_deleted' ), 10, 1 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'on_wp_user_registered' ), 30, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_woocommerce_order_created' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_order_created' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 15, 4 );
	}

	public static function on_store_api_order_created( $order ) {
		if ( $order instanceof WC_Order ) {
			self::on_woocommerce_order_created( $order->get_id(), null, $order );
		}
	}

	public static function on_order_status_changed( $order_id, $from_status, $to_status, $order ) {
		if ( in_array( (string) $to_status, array( 'processing', 'completed' ), true ) ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
			if ( ! Dolisync_Ignored_Items::is_ignored( 'order', (int) $order_id, 0 ) ) {
				try {
					require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-order-sync.php';
					Dolisync_Order_Sync::sync_invoice_payment_status( (int) $order_id, (string) $to_status );
				} catch ( Throwable $e ) {
					require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
					Dolisync_Action_Logger::log_action( 'factura', 'sincronización_pago', 'error', sprintf( __( 'El pedido WooCommerce %1$d pasó a %2$s, pero no se pudo sincronizar el pago en Dolibarr: %3$s', 'dolisync' ), (int) $order_id, (string) $to_status, $e->getMessage() ), get_current_user_id() );
				}
			}
		}

		self::maybe_enqueue_order( $order_id, $order );
	}

	public static function on_wp_user_registered( $user_id ) {
		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-event-sender.php';
			if ( class_exists( 'Dolisync_Event_Sender' ) ) {
				Dolisync_Event_Sender::on_wp_user_registered( $user_id );
			}
		} catch ( Throwable $e ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
			Dolisync_Action_Logger::log_action( 'contacto', 'registro_woocommerce', 'error', sprintf( __( 'El usuario WooCommerce %1$d se creó correctamente, pero su envío a Dolibarr se interrumpió: %2$s', 'dolisync' ), (int) $user_id, $e->getMessage() ), get_current_user_id() );
		}
	}

	public static function on_woocommerce_order_created( $order_id, $posted_data = null, $order = null ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null );
		if ( $order instanceof WC_Order && (int) $order->get_user_id() > 0 ) {
			self::on_wp_user_registered( (int) $order->get_user_id() );
		}
		self::maybe_enqueue_order( $order_id, $order );
	}

	private static function maybe_enqueue_order( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null );
		if ( ! $order instanceof WC_Order || ! in_array( (string) $order->get_status(), array( 'on-hold', 'processing', 'completed' ), true ) ) {
			return;
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		if ( Dolisync_Ignored_Items::is_ignored( 'order', (int) $order_id, 0 ) ) {
			return;
		}
		try {
			Dolisync_Order_Queue::enqueue( $order_id );
		} catch ( Throwable $e ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
			Dolisync_Action_Logger::log_action( 'pedido', 'encolado', 'error', sprintf( __( 'El pedido WooCommerce %1$d se creó, pero no pudo añadirse a la cola de Dolibarr: %2$s', 'dolisync' ), (int) $order_id, $e->getMessage() ), get_current_user_id() );
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $e->getMessage(), array( 'source' => 'dolisync', 'order_id' => (int) $order_id ) );
			}
		}
	}

	public static function on_wp_user_deleted( $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_relations';
		$relation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wp_user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $relation ) {
			$deleted = $wpdb->delete( $table, array( 'wp_user_id' => $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false !== $deleted && class_exists( 'Dolisync_Action_Logger' ) ) {
				Dolisync_Action_Logger::log_action( 'contacto', 'eliminación_usuario_wp_y_relación', 'finalizado', sprintf( __( 'Se ha eliminado el usuario WP %d y su relación local (dolibarr_id: %d). No se han modificado recursos en Dolibarr.', 'dolisync' ), (int) $user_id, (int) $relation->dolibarr_contact_id ), get_current_user_id() );
			}
		}
	}
}
