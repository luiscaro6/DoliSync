<?php
/**
 * Manejador AJAX para sincronización de contactos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Sync_Handler {
	private const LOCK_TTL = 900;
	public static function init() {
		add_action( 'wp_ajax_dolisync_sync_contacts', array( __CLASS__, 'handle_sync_request' ) );
		add_action( 'wp_ajax_dolisync_sync_contacts_reverse', array( __CLASS__, 'handle_sync_reverse_request' ) );
		add_action( 'wp_ajax_dolisync_sync_products', array( __CLASS__, 'handle_product_sync_request' ) );
		add_action( 'wp_ajax_dolisync_sync_product_categories', array( __CLASS__, 'handle_product_categories_sync_request' ) );
		add_action( 'wp_ajax_dolisync_sync_products_reverse', array( __CLASS__, 'handle_product_sync_reverse_request' ) );
		add_action( 'wp_ajax_dolisync_sync_stock', array( __CLASS__, 'handle_stock_sync_request' ) );
	}

	private static function start_operation_context( $prefix ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-operation-context.php';
		$provided = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		return Dolisync_Operation_Context::start( $prefix, $provided );
	}

	/**
	 * Manejar solicitud de sincronización Dolibarr → WooCommerce AJAX.
	 */
	public static function handle_sync_request() {
		self::start_operation_context( 'contacts-import' );
		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permisos insuficientes', 'dolisync' ) ),
				403
			);
		}

		// Verificar nonce
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, DOLISYNC_NONCE_ACTION ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Error de validación de seguridad', 'dolisync' ) ),
				403
			);
		}

		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-sync.php';
			$sync = new Dolisync_Contact_Sync();
			$result = $sync->sync();

			if ( $result['success'] ) {
				wp_send_json_success( array(
					'message' => $result['message'],
					'stats'   => $result['stats'],
				) );
			} else {
				wp_send_json_error( array(
					'message' => $result['message'],
				) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array(
				'message' => __( 'Error durante la sincronización: ', 'dolisync' ) . $e->getMessage(),
			) );
		}
	}

	/**
	 * Manejar solicitud de sincronización inversa WooCommerce → Dolibarr AJAX.
	 */
	public static function handle_sync_reverse_request() {
		self::start_operation_context( 'contacts-export' );
		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permisos insuficientes', 'dolisync' ) ),
				403
			);
		}

		// Verificar nonce
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, DOLISYNC_NONCE_ACTION ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Error de validación de seguridad', 'dolisync' ) ),
				403
			);
		}

		// Iniciar buffer de output para capturar cualquier error
		ob_start();
		
		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-sync-reverse.php';
			$sync = new Dolisync_Contact_Sync_Reverse();
			$result = $sync->sync();

			// Limpiar cualquier output capturado
			ob_end_clean();

			if ( $result['success'] ) {
				wp_send_json_success( array(
					'message' => $result['message'],
					'stats'   => $result['stats'],
				) );
			} else {
				wp_send_json_error( array(
					'message' => $result['message'],
				) );
			}
		} catch ( Throwable $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			self::log_system_error( 'contactos_woo_dolibarr', $e );
			wp_send_json_error( array(
				'message' => __( 'Error durante la sincronización inversa: ', 'dolisync' ) . $e->getMessage(),
			) );
		}
	}

	/**
	 * Manejar solicitud de sincronización de productos Dolibarr → WooCommerce AJAX.
	 */
	public static function handle_product_sync_request() {
		self::start_operation_context( 'products-import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes', 'dolisync' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, DOLISYNC_NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Error de validación de seguridad', 'dolisync' ) ), 403 );
		}
		$page = isset( $_POST['page_number'] ) ? max( 0, absint( wp_unslash( $_POST['page_number'] ) ) ) : 0;
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$lock = 'products_catalog';
		$run_id = self::reserve_paged_lock( $lock, 0 === $page, $run_id );
		if ( '' === $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Ya hay una sincronización de productos en curso. Espera a que finalice antes de iniciar otra.', 'dolisync' ) ), 409 );
		}

		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-sync.php';
			$sync = new Dolisync_Product_Sync();
			$per_page = isset( $_POST['per_page'] ) ? max( 1, min( 100, absint( wp_unslash( $_POST['per_page'] ) ) ) ) : 25;
			$result = $sync->sync( $page, $per_page );

			if ( ! empty( $result['success'] ) ) {
				if ( empty( $result['pagination']['has_more'] ) ) {
					self::release_paged_lock( $lock, $run_id );
				}
				wp_send_json_success( array( 'message' => $result['message'], 'stats' => $result['stats'], 'pagination' => $result['pagination'] ?? array(), 'run_id' => $run_id ) );
			}

			self::release_paged_lock( $lock, $run_id );
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Error desconocido', 'dolisync' ) ) );
		} catch ( Throwable $e ) {
			self::release_paged_lock( $lock, $run_id );
			self::log_system_error( 'productos_dolibarr_woo', $e );
			wp_send_json_error( array( 'message' => __( 'Error durante la sincronización de productos: ', 'dolisync' ) . $e->getMessage() ) );
		}
	}

	/**
	 * Manejar solicitud de sincronización de categorías de producto Dolibarr → WooCommerce AJAX.
	 */
	public static function handle_product_categories_sync_request() {
		self::start_operation_context( 'categories' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes', 'dolisync' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, DOLISYNC_NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Error de validación de seguridad', 'dolisync' ) ), 403 );
		}

		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-sync.php';
			$sync = new Dolisync_Product_Sync();
			$result = $sync->sync_categories();

			if ( ! empty( $result['success'] ) ) {
				wp_send_json_success( array( 'message' => $result['message'], 'stats' => $result['stats'] ) );
			}

			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Error desconocido', 'dolisync' ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Error durante la sincronización de categorías: ', 'dolisync' ) . $e->getMessage() ) );
		}
	}

	/**
	 * Manejar solicitud de sincronización manual WooCommerce → Dolibarr.
	 */
	public static function handle_product_sync_reverse_request() {
		self::start_operation_context( 'products-export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes', 'dolisync' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, DOLISYNC_NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Error de validación de seguridad', 'dolisync' ) ), 403 );
		}
		$page = isset( $_POST['page_number'] ) ? max( 1, absint( wp_unslash( $_POST['page_number'] ) ) ) : 1;
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$lock = 'products_catalog';
		$run_id = self::reserve_paged_lock( $lock, 1 === $page, $run_id );
		if ( '' === $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Ya hay una exportación de productos en curso. Espera a que finalice antes de iniciar otra.', 'dolisync' ) ), 409 );
		}

		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-sync-reverse.php';
			$sync = new Dolisync_Product_Sync_Reverse();
			$per_page = isset( $_POST['per_page'] ) ? max( 1, min( 100, absint( wp_unslash( $_POST['per_page'] ) ) ) ) : 25;
			$result = $sync->sync( $page, $per_page );

			if ( ! empty( $result['success'] ) ) {
				if ( empty( $result['pagination']['has_more'] ) ) {
					self::release_paged_lock( $lock, $run_id );
				}
				wp_send_json_success( array( 'message' => $result['message'], 'stats' => $result['stats'], 'pagination' => $result['pagination'] ?? array(), 'run_id' => $run_id ) );
			}

			self::release_paged_lock( $lock, $run_id );
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Error desconocido', 'dolisync' ) ) );
		} catch ( Throwable $e ) {
			self::release_paged_lock( $lock, $run_id );
			self::log_system_error( 'productos_woo_dolibarr', $e );
			wp_send_json_error( array( 'message' => __( 'Error durante la sincronización manual de productos: ', 'dolisync' ) . $e->getMessage() ) );
		}
	}

	public static function handle_stock_sync_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'dolisync' ) ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, DOLISYNC_NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.', 'dolisync' ) ), 403 );
		}

		$offset = isset( $_POST['offset'] ) ? max( 0, absint( wp_unslash( $_POST['offset'] ) ) ) : 0;
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$lock_key = 'dolisync_stock_sync_lock';
		$lock_value = (string) get_option( $lock_key, '' );
		list( $active_run, $lock_time ) = array_pad( explode( '|', $lock_value, 2 ), 2, 0 );
		if ( '' !== $active_run && ( time() - (int) $lock_time ) >= self::LOCK_TTL ) {
			delete_option( $lock_key );
			$active_run = '';
		}

		if ( 0 === $offset ) {
			if ( '' !== $active_run ) {
				wp_send_json_error( array( 'message' => __( 'Ya hay una sincronización de stock en curso.', 'dolisync' ) ), 409 );
			}
			$run_id = wp_generate_uuid4();
			if ( ! add_option( $lock_key, $run_id . '|' . time(), '', false ) ) {
				wp_send_json_error( array( 'message' => __( 'No se pudo reservar la sincronización de stock.', 'dolisync' ) ), 409 );
			}
		} elseif ( '' === $run_id || '' === $active_run || ! hash_equals( $active_run, $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'La sincronización de stock ha caducado. Iníciala de nuevo.', 'dolisync' ) ), 409 );
		} else {
			update_option( $lock_key, $run_id . '|' . time(), false );
		}
		self::start_operation_context( 'stock' );

		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-stock-sync.php';
			$result = ( new Dolisync_Stock_Sync() )->sync_batch( $offset, 'manual' );
			if ( empty( $result['has_more'] ) ) {
				delete_option( $lock_key );
			}
			wp_send_json_success( array(
				'message' => empty( $result['has_more'] ) ? __( 'Sincronización manual de stock completada.', 'dolisync' ) : __( 'Lote de stock procesado.', 'dolisync' ),
				'stats' => $result['stats'] ?? array(),
				'has_more' => ! empty( $result['has_more'] ),
				'next_offset' => (int) ( $result['next_offset'] ?? 0 ),
				'run_id' => $run_id,
			) );
		} catch ( Throwable $e ) {
			delete_option( $lock_key );
			self::log_system_error( 'stock_manual', $e );
			wp_send_json_error( array( 'message' => sprintf( __( 'No se pudo sincronizar el stock: %s', 'dolisync' ), $e->getMessage() ) ) );
		}
	}

	private static function reserve_paged_lock( $name, $is_first_page, $run_id ) {
		$key = 'dolisync_lock_' . sanitize_key( $name );
		$value = (string) get_option( $key, '' );
		list( $active_run, $lock_time, $lock_user_id ) = array_pad( explode( '|', $value, 3 ), 3, 0 );
		$current_user_id = get_current_user_id();

		if ( '' !== $active_run && ( time() - (int) $lock_time ) >= self::LOCK_TTL ) {
			delete_option( $key );
			$active_run = '';
		}

		if ( $is_first_page ) {
			if ( '' !== $active_run ) {
				// Una ejecución interrumpida puede reiniciarla su propietario. Los
				// bloqueos antiguos no tenían propietario y no pueden continuarse.
				if ( 0 === (int) $lock_user_id || $current_user_id === (int) $lock_user_id ) {
					delete_option( $key );
					$active_run = '';
				} else {
					return '';
				}
			}
			$run_id = wp_generate_uuid4();
			return add_option( $key, $run_id . '|' . time() . '|' . $current_user_id, '', false ) ? $run_id : '';
		}

		if ( '' === $run_id || '' === $active_run || ! hash_equals( $active_run, $run_id ) ) {
			return '';
		}

		update_option( $key, $run_id . '|' . time() . '|' . $current_user_id, false );
		return $run_id;
	}

	private static function release_paged_lock( $name, $run_id ) {
		$key = 'dolisync_lock_' . sanitize_key( $name );
		$value = (string) get_option( $key, '' );
		list( $active_run ) = array_pad( explode( '|', $value, 2 ), 1, '' );
		if ( '' !== $run_id && '' !== $active_run && hash_equals( $active_run, $run_id ) ) {
			delete_option( $key );
		}
	}

	private static function log_system_error( $action, Throwable $error ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
		Dolisync_Action_Logger::log_action(
			'sistema',
			$action,
			'error',
			sprintf( __( 'La sincronización se interrumpió: %s', 'dolisync' ), $error->getMessage() ),
			get_current_user_id()
		);
	}
}

Dolisync_Sync_Handler::init();
