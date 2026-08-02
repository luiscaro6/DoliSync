<?php
/**
 * Hooks y handlers genéricos de DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Hooks {
	public static function init() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-invoice-pdf.php';
		Dolisync_Invoice_PDF::init();
		// Hook cuando se elimina un usuario de WordPress: limpiar la relación local
		add_action( 'delete_user', array( __CLASS__, 'on_wp_user_deleted' ), 10, 1 );

		// Hooks para envío de eventos a Dolibarr
		if ( function_exists( 'add_action' ) ) {
			// El tercero se crea al procesar el pedido, cuando ya disponemos de todos
			// los datos fiscales y de facturación. No hacerlo en user_register evita
			// terceros incompletos durante el checkout.
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_woocommerce_order_created' ), 20, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_order_created' ), 20, 1 );
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 15, 4 );
		}
	}

	public static function on_store_api_order_created( $order ) {
		if ( $order instanceof WC_Order ) {
			self::on_woocommerce_order_created( $order->get_id(), null, $order );
		}
	}

	public static function on_order_status_changed( $order_id, $from_status, $to_status, $order ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		if ( Dolisync_Ignored_Items::is_ignored( 'order', (int) $order_id, 0 ) ) {
			return;
		}
		if ( in_array( (string) $to_status, array( 'processing', 'on-hold', 'completed' ), true ) ) {
			self::on_woocommerce_order_created( $order_id, null, $order );
			if ( in_array( (string) $to_status, array( 'processing', 'completed' ), true ) && class_exists( 'Dolisync_Order_Sync' ) ) {
				try {
					Dolisync_Order_Sync::sync_invoice_payment_status( $order_id, $to_status );
				} catch ( Throwable $e ) {
					require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
					Dolisync_Action_Logger::log_action( 'factura', 'sincronización_pago', 'error', sprintf( __( 'No se pudo sincronizar el estado de pago del pedido WooCommerce %1$d: %2$s', 'dolisync' ), (int) $order_id, $e->getMessage() ), get_current_user_id() );
				}
			}
		}
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
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $e->getMessage(), array( 'source' => 'dolisync', 'user_id' => (int) $user_id ) );
			}
		}
	}

	public static function on_woocommerce_order_created( $order_id, $posted_data = null, $order = null ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		if ( Dolisync_Ignored_Items::is_ignored( 'order', (int) $order_id, 0 ) ) {
			return;
		}

		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-order-sync.php';
			if ( class_exists( 'Dolisync_Order_Sync' ) ) {
				Dolisync_Order_Sync::sync_order( $order_id, $order );
			}
		} catch ( Throwable $e ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
			Dolisync_Action_Logger::log_action( 'pedido', 'sincronización', 'error', sprintf( __( 'El pedido WooCommerce %1$d se creó, pero la sincronización con Dolibarr se interrumpió: %2$s', 'dolisync' ), (int) $order_id, $e->getMessage() ), get_current_user_id() );
			$wc_order = $order instanceof WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
			if ( $wc_order instanceof WC_Order ) {
				$wc_order->add_order_note( __( 'DoliSync no pudo completar el envío a Dolibarr. Consulta el registro de actividad y vuelve a intentarlo.', 'dolisync' ) );
			}
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $e->getMessage(), array( 'source' => 'dolisync', 'order_id' => (int) $order_id ) );
			}
		}
	}



	/**
	 * Cuando se elimina un usuario en WP, borrar la fila de relación si existe.
	 * No tocar Dolibarr.
	 *
	 * @param int $user_id
	 */
	public static function on_wp_user_deleted( $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_contact_relations';

		$relation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wp_user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $relation ) {
			$deleted = $wpdb->delete( $table, array( 'wp_user_id' => $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false !== $deleted ) {
				if ( class_exists( 'Dolisync_Action_Logger' ) ) {
					// Mensaje genérico que cubre eliminación manual o por sincronización
					Dolisync_Action_Logger::log_action(
						'contacto',
						'eliminación_usuario_wp_y_relación',
						'finalizado',
						sprintf( __( 'Se ha eliminado el usuario WP %d y su relación local (dolibarr_id: %d). No se han modificado recursos en Dolibarr.', 'dolisync' ), (int) $user_id, (int) $relation->dolibarr_contact_id ),
						get_current_user_id()
					);
				}
			}
		}
	}
}
