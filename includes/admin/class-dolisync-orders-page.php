<?php
/**
 * Panel de seguimiento de pedidos WooCommerce enviados a Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Orders_Page {
	const PAGE_SIZE = 20;

	public static function init() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		Dolisync_Schema::ensure_ignored_items_table();
		add_action( 'wp_ajax_dolisync_orders_catalog', array( __CLASS__, 'ajax_catalog' ) );
		add_action( 'wp_ajax_dolisync_order_action', array( __CLASS__, 'ajax_order_action' ) );
		add_action( 'wp_ajax_dolisync_order_download_pdf', array( __CLASS__, 'ajax_download_pdf' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'dolisync' ) );
		}
		?>
		<div class="wrap dolisync-container dolisync-products-app dolisync-orders-app">
			<div class="dolisync-products-hero dolisync-orders-hero">
				<div>
					<span class="dolisync-products-eyebrow"><?php echo esc_html__( 'Seguimiento de pedidos', 'dolisync' ); ?></span>
					<h1><?php echo esc_html__( 'Pedidos', 'dolisync' ); ?></h1>
					<p><?php echo esc_html__( 'Comprueba la sincronización con Dolibarr, el PDF local y el envío de la factura al cliente.', 'dolisync' ); ?></p>
				</div>
				<button type="button" class="button dolisync-orders-reload"><span class="dashicons dashicons-update"></span> <?php echo esc_html__( 'Actualizar pedidos', 'dolisync' ); ?></button>
			</div>
			<div class="dolisync-products-toolbar">
				<label class="dolisync-products-search">
					<span class="dashicons dashicons-search"></span>
					<input type="search" id="dolisync-orders-search" placeholder="<?php echo esc_attr__( 'Buscar por pedido, cliente, email o factura…', 'dolisync' ); ?>">
				</label>
				<label class="dolisync-products-filter">
					<span><?php echo esc_html__( 'Estado', 'dolisync' ); ?></span>
					<select id="dolisync-orders-status-filter">
						<option value="all"><?php echo esc_html__( 'Todos', 'dolisync' ); ?></option>
						<option value="ok"><?php echo esc_html__( 'Completos', 'dolisync' ); ?></option>
						<option value="pending"><?php echo esc_html__( 'Pendientes', 'dolisync' ); ?></option>
						<option value="error"><?php echo esc_html__( 'Con errores', 'dolisync' ); ?></option>
						<option value="ignored"><?php echo esc_html__( 'Omitidos', 'dolisync' ); ?></option>
					</select>
				</label>
				<div class="dolisync-orders-bulk">
					<button type="button" class="button" data-bulk-operation="ignore" disabled><?php echo esc_html__( 'Omitir seleccionados', 'dolisync' ); ?></button>
					<button type="button" class="button" data-bulk-operation="restore" disabled><?php echo esc_html__( 'Restaurar seleccionados', 'dolisync' ); ?></button>
					<button type="button" class="button dolisync-ignore-all-orders"><?php echo esc_html__( 'Omitir todos los pedidos', 'dolisync' ); ?></button>
				</div>
				<div id="dolisync-orders-summary" class="dolisync-products-summary"></div>
			</div>
			<div id="dolisync-orders-notice" aria-live="polite"></div>
			<div id="dolisync-orders-table" class="dolisync-products-table-wrap">
				<div class="dolisync-products-loading"><span class="spinner is-active"></span><?php echo esc_html__( 'Leyendo pedidos…', 'dolisync' ); ?></div>
			</div>
			<div id="dolisync-orders-pagination" class="dolisync-products-pagination"></div>
		</div>
		<?php
	}

	public static function ajax_catalog() {
		self::guard_ajax();

		try {
			$rows = self::build_catalog();
			wp_send_json_success(
				array(
					'rows'      => $rows,
					'page_size' => self::PAGE_SIZE,
					'summary'   => array(
						'total'   => count( $rows ),
						'ok'      => count( array_filter( $rows, static function ( $row ) { return 'ok' === $row['overall']; } ) ),
						'emailed' => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['email']['sent'] ); } ) ),
						'pdf'     => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['pdf']['available'] ); } ) ),
						'ignored' => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['ignored'] ); } ) ),
					),
				)
			);
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public static function ajax_order_action() {
		self::guard_ajax();
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order && ! in_array( $operation, array( 'bulk_ignore', 'bulk_restore', 'bulk_ignore_all' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'El pedido no existe.', 'dolisync' ) ), 404 );
		}

		try {
			if ( 'bulk_ignore_all' === $operation ) {
				$order_ids = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) );
				require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
				$count = Dolisync_Ignored_Items::set_many_orders( (array) $order_ids, true );
				wp_send_json_success( array( 'message' => sprintf( __( '%d pedidos se han marcado como omitidos.', 'dolisync' ), $count ) ) );
			}
			if ( in_array( $operation, array( 'ignore', 'restore' ), true ) ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
				if ( ! Dolisync_Ignored_Items::set( 'order', $order_id, 0, 'ignore' === $operation ) ) {
					throw new RuntimeException( __( 'No se pudo actualizar la omisión del pedido.', 'dolisync' ) );
				}
				wp_send_json_success( array( 'message' => 'ignore' === $operation ? __( 'Pedido omitido.', 'dolisync' ) : __( 'Pedido restaurado.', 'dolisync' ) ) );
			}
			if ( in_array( $operation, array( 'bulk_ignore', 'bulk_restore' ), true ) ) {
				$order_ids = isset( $_POST['order_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) ) ) ) ) : array();
				if ( empty( $order_ids ) ) {
					throw new InvalidArgumentException( __( 'Selecciona al menos un pedido.', 'dolisync' ) );
				}
				require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
				foreach ( $order_ids as $selected_order_id ) {
					Dolisync_Ignored_Items::set( 'order', $selected_order_id, 0, 'bulk_ignore' === $operation );
				}
				wp_send_json_success( array( 'message' => sprintf( __( '%d pedidos actualizados.', 'dolisync' ), count( $order_ids ) ) ) );
			}
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-invoice-pdf.php';
			$relation = self::get_relation( $order_id );
			$invoice_id = (int) ( $relation['dolibarr_invoice_id'] ?? 0 );
			if ( 'refresh' === $operation ) {
				if ( $invoice_id <= 0 ) {
					throw new RuntimeException( __( 'El pedido no tiene una factura Dolibarr vinculada.', 'dolisync' ) );
				}
				require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
				$api = new Dolisync_API_Client();
				$response = $api->get( '/invoices/' . $invoice_id );
				if ( empty( $response['success'] ) ) {
					throw new RuntimeException( (string) ( $response['message'] ?? __( 'No se pudo consultar la factura en Dolibarr.', 'dolisync' ) ) );
				}
				$invoice = self::normalize_array( $response['data'] ?? array() );
				$status = ! empty( $invoice['paye'] ) ? 'paid' : ( (int) ( $invoice['statut'] ?? $invoice['status'] ?? 0 ) > 0 ? 'validated' : 'draft' );
				self::update_relation( $order_id, array( 'invoice_status' => $status, 'invoice_ref' => (string) ( $invoice['ref'] ?? '' ), 'last_error_message' => '', 'synced_at' => current_time( 'mysql' ) ) );
				if ( '' === Dolisync_Invoice_PDF::generate_and_store( $order, $api, $invoice_id ) ) {
					throw new RuntimeException( __( 'La factura se actualizó, pero no se pudo descargar su PDF.', 'dolisync' ) );
				}
				if ( in_array( $status, array( 'validated', 'paid' ), true ) ) {
					self::update_relation( $order_id, array( 'sync_status' => 'success', 'last_error_message' => '' ) );
				}
				wp_send_json_success( array( 'message' => __( 'Factura y PDF actualizados desde Dolibarr.', 'dolisync' ) ) );
			}

			if ( 'resend_email' === $operation ) {
				if ( ! Dolisync_Invoice_PDF::has_pdf( $order ) ) {
					if ( $invoice_id <= 0 ) {
						throw new RuntimeException( __( 'El pedido no tiene una factura disponible.', 'dolisync' ) );
					}
					require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
					Dolisync_Invoice_PDF::generate_and_store( $order, new Dolisync_API_Client(), $invoice_id );
				}
				if ( ! Dolisync_Invoice_PDF::resend_customer_invoice( $order ) ) {
					throw new RuntimeException( __( 'WooCommerce no pudo completar el reenvío. Comprueba que el correo para clientes esté habilitado; el detalle también aparece en esta fila.', 'dolisync' ) );
				}
				wp_send_json_success( array( 'message' => __( 'Correo reenviado con la factura adjunta.', 'dolisync' ) ) );
			}

			throw new InvalidArgumentException( __( 'Acción no válida.', 'dolisync' ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public static function ajax_download_pdf() {
		self::guard_ajax();
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'El pedido no existe.', 'dolisync' ), '', array( 'response' => 404 ) );
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-invoice-pdf.php';
		$path = Dolisync_Invoice_PDF::get_pdf_path( $order );
		if ( ! Dolisync_Invoice_PDF::is_safe_stored_pdf( $path ) ) {
			wp_die( esc_html__( 'El PDF no está disponible.', 'dolisync' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	private static function build_catalog() {
		global $wpdb;
		$relation_table = $wpdb->prefix . 'dolisync_order_relations';
		$relations = array();
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		$ignored_orders = Dolisync_Ignored_Items::get_map( 'order' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relation_table ) ) === $relation_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$relation_table} ORDER BY wc_order_id DESC", ARRAY_A ) as $relation ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$relations[ (int) $relation['wc_order_id'] ] = $relation;
			}
		}

		$orders = wc_get_orders(
			array(
				'limit'   => -1,
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$rows = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$id = (int) $order->get_id();
			$ignored_key = Dolisync_Ignored_Items::key( $id, 0 );
			$ignored = isset( $ignored_orders[ $ignored_key ] );
			$relation = $relations[ $id ] ?? array();
			$path = (string) ( $relation['invoice_pdf_path'] ?? '' );
			$pdf_available = 'available' === (string) ( $relation['invoice_pdf_status'] ?? '' ) && self::is_local_pdf( $path );
			$emailed_at = (string) ( $relation['invoice_email_sent_at'] ?? '' );
			$sync_status = (string) ( $relation['sync_status'] ?? '' );
			$invoice_id = (int) ( $relation['dolibarr_invoice_id'] ?? 0 );
			$invoice_status = (string) ( $relation['invoice_status'] ?? '' );
			$sent_to_dolibarr = $invoice_id > 0 && in_array( $invoice_status, array( 'validated', 'paid' ), true );
			$has_error = 'error' === $sync_status || ! empty( $relation['last_error_message'] );
			$email_sent = 'sent' === (string) ( $relation['invoice_email_status'] ?? '' );
			$has_error = $has_error || 'error' === (string) ( $relation['invoice_pdf_status'] ?? '' ) || in_array( (string) ( $relation['invoice_email_status'] ?? '' ), array( 'error', 'failed' ), true );
			$overall = $ignored ? 'ignored' : ( $has_error ? 'error' : ( $sent_to_dolibarr && $pdf_available && $email_sent ? 'ok' : 'pending' ) );
			$date = $order->get_date_created();
			$name = trim( $order->get_formatted_billing_full_name() );
			$invoice_ref = (string) ( $relation['invoice_ref'] ?? '' );

			$rows[] = array(
				'id'       => $id,
				'number'   => (string) $order->get_order_number(),
				'date'     => $date ? wc_format_datetime( $date ) : '',
				'customer' => '' !== $name ? $name : __( 'Invitado', 'dolisync' ),
				'email_address' => (string) $order->get_billing_email(),
				'total'    => trim( str_replace( "\xc2\xa0", ' ', html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) ) ),
				'status'   => wc_get_order_status_name( $order->get_status() ),
				'edit_url' => $order->get_edit_order_url(),
				'dolibarr' => array(
					'sent'           => $sent_to_dolibarr,
					'invoice_id'     => $invoice_id,
					'invoice_ref'    => $invoice_ref,
					'invoice_status' => $invoice_status,
					'sync_status'    => $sync_status,
					'synced_at'      => (string) ( $relation['synced_at'] ?? '' ),
					'error'          => (string) ( $relation['last_error_message'] ?? '' ),
				),
				'email' => array(
					'sent' => $email_sent,
					'at'   => $emailed_at,
					'status' => (string) ( $relation['invoice_email_status'] ?? 'pending' ),
					'attempts' => (int) ( $relation['invoice_email_attempts'] ?? 0 ),
					'next_retry_at' => (string) ( $relation['invoice_email_next_retry_at'] ?? '' ),
					'error' => (string) ( $relation['invoice_email_last_error'] ?? '' ),
				),
				'pdf' => array(
					'available' => $pdf_available,
					'filename'  => $pdf_available ? basename( $path ) : '',
					'status'    => (string) ( $relation['invoice_pdf_status'] ?? 'pending' ),
					'downloaded_at' => (string) ( $relation['invoice_pdf_downloaded_at'] ?? '' ),
					'error' => (string) ( $relation['invoice_pdf_last_error'] ?? '' ),
				),
				'overall' => $overall,
				'ignored' => $ignored,
				'ignored_at' => (string) ( $ignored_orders[ $ignored_key ] ?? '' ),
				'search'  => self::search_key( implode( ' ', array( $id, $order->get_order_number(), $name, $order->get_billing_email(), $invoice_id, $invoice_ref ) ) ),
			);
		}
		return $rows;
	}

	private static function guard_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'dolisync' ) ), 403 );
		}
		check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );
	}

	private static function get_relation( $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_order_id = %d LIMIT 1", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : array();
	}

	private static function update_relation( $order_id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $wpdb->prefix . 'dolisync_order_relations', $data, array( 'wc_order_id' => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function normalize_array( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}
		return is_array( $data ) ? $data : array();
	}

	private static function is_local_pdf( $path ) {
		if ( '' === (string) $path || ! is_file( $path ) || ! is_readable( $path ) || 'pdf' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return false;
		}
		$uploads = wp_upload_dir();
		$private_prefix = wp_normalize_path( trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . 'dolisync-private-' );
		return '' !== $private_prefix && 0 === strpos( wp_normalize_path( $path ), $private_prefix );
	}

	private static function search_key( $value ) {
		return strtolower( remove_accents( trim( preg_replace( '/\s+/', ' ', (string) $value ) ) ) );
	}
}

Dolisync_Orders_Page::init();
