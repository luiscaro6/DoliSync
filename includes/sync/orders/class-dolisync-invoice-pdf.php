<?php
/**
 * Generación, descarga y adjunto del PDF fiscal emitido por Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Invoice_PDF {
	private const META_PATH = '_dolisync_dolibarr_invoice_pdf_path';
	private const META_REF = '_dolisync_dolibarr_invoice_ref';
	private const META_PENDING_ID = '_dolisync_dolibarr_invoice_pending_id';
	private const META_RETRY_COUNT = '_dolisync_dolibarr_invoice_pdf_retries';
	private const META_EMAILED_AT = '_dolisync_dolibarr_invoice_pdf_emailed_at';
	private const META_EMAIL_HISTORY = '_dolisync_email_history';
	private const MAX_PDF_BYTES = 26214400;
	private const MAX_EMAIL_ATTEMPTS = 3;
	private const EMAIL_RETRY_DELAY = 300;
	private static $initialized = false;
	private static $pending_email_attachments = array();

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'on_mail_succeeded' ), 10, 1 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_mail_failed' ), 10, 1 );
		add_action( 'woocommerce_email_sent', array( __CLASS__, 'on_woocommerce_email_sent' ), 10, 3 );
		add_action( 'dolisync_retry_invoice_delivery', array( __CLASS__, 'retry_delivery' ), 10, 2 );
		add_action( 'dolisync_retry_invoice_email', array( __CLASS__, 'retry_email' ), 10, 1 );
	}

	public static function has_pdf( WC_Order $order ) {
		$path = self::get_pdf_path( $order );
		return self::is_safe_stored_pdf( $path );
	}

	public static function get_pdf_path( WC_Order $order ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$path = (string) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_pdf_path FROM {$table} WHERE wc_order_id = %d LIMIT 1", $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '' !== $path ? $path : (string) $order->get_meta( self::META_PATH, true );
	}

	public static function generate_and_store( WC_Order $order, Dolisync_API_Client $api, $invoice_id ) {
		$invoice_id = (int) $invoice_id;
		if ( $invoice_id <= 0 ) {
			return '';
		}
		$order->update_meta_data( self::META_PENDING_ID, $invoice_id );
		$order->save_meta_data();
		self::update_relation( $order, array( 'invoice_pdf_status' => 'downloading', 'invoice_pdf_last_error' => '' ) );

		$invoice_response = $api->get( '/invoices/' . $invoice_id );
		if ( empty( $invoice_response['success'] ) ) {
			self::log_failure( $order, 'lectura_factura', $invoice_response['message'] ?? __( 'No se pudo consultar la factura validada.', 'dolisync' ) );
			return '';
		}
		$invoice = self::normalize_array( $invoice_response['data'] ?? array() );
		$invoice_ref = trim( (string) ( $invoice['ref'] ?? '' ) );
		if ( '' === $invoice_ref || false !== strpos( $invoice_ref, '..' ) || preg_match( '#[\\\\/]#', $invoice_ref ) ) {
			self::log_failure( $order, 'referencia_factura', __( 'Dolibarr devolvió una referencia de factura vacía o no válida.', 'dolisync' ) );
			return '';
		}

		$default_relative_file = $invoice_ref . '/' . $invoice_ref . '.pdf';
		$list_response = $api->get( '/documents', array( 'modulepart' => 'invoice', 'id' => $invoice_id, 'sortfield' => 'date', 'sortorder' => 'desc' ) );
		$relative_file = ! empty( $list_response['success'] ) ? self::find_pdf_path( $list_response['data'] ?? null, '' ) : '';
		if ( '' !== $relative_file && false === strpos( $relative_file, '/' ) ) {
			$relative_file = $invoice_ref . '/' . $relative_file;
		}
		if ( '' === $relative_file ) {
			$build_response = $api->put( '/documents/builddoc', array(
				'modulepart' => 'invoice',
				'original_file' => $default_relative_file,
				'doctemplate' => '',
				'langcode' => 'es_ES',
			) );
			if ( empty( $build_response['success'] ) ) {
				self::log_failure( $order, 'generacion_pdf', $build_response['message'] ?? __( 'Dolibarr rechazó la generación del documento.', 'dolisync' ) );
				return '';
			}
			$relative_file = self::find_pdf_path( $build_response['data'] ?? null, $default_relative_file );
		}
		$download = $api->get( '/documents/download', array( 'modulepart' => 'invoice', 'original_file' => $relative_file ) );
		if ( empty( $download['success'] ) ) {
			// Versiones anteriores de Dolibarr usan el alias histórico "facture" para la descarga.
			$download = $api->get( '/documents/download', array( 'modulepart' => 'facture', 'original_file' => $relative_file ) );
		}
		if ( empty( $download['success'] ) ) {
			self::log_failure( $order, 'descarga_pdf', $download['message'] ?? __( 'Dolibarr no permitió descargar el documento generado.', 'dolisync' ) );
			return '';
		}
		$data = self::normalize_array( $download['data'] ?? array() );
		$content = (string) ( $data['content'] ?? '' );
		$binary = 'base64' === strtolower( (string) ( $data['encoding'] ?? 'base64' ) ) ? base64_decode( $content, true ) : $content;
		if ( false === $binary || strlen( $binary ) < 5 || strlen( $binary ) > self::MAX_PDF_BYTES || '%PDF-' !== substr( $binary, 0, 5 ) ) {
			self::log_failure( $order, 'validacion_pdf', __( 'La respuesta descargada no contiene un PDF válido.', 'dolisync' ) );
			return '';
		}

		$path = self::store_pdf( $order, $invoice_ref, $binary );
		if ( '' === $path ) {
			self::log_failure( $order, 'guardado_pdf', __( 'WordPress no pudo guardar el PDF en el directorio privado de medios.', 'dolisync' ) );
			return '';
		}
		$order->update_meta_data( self::META_PATH, $path );
		$order->update_meta_data( self::META_REF, $invoice_ref );
		$order->delete_meta_data( self::META_PENDING_ID );
		$order->delete_meta_data( self::META_RETRY_COUNT );
		$order->save();
		self::update_relation(
			$order,
			array(
				'invoice_ref'               => $invoice_ref,
				'invoice_pdf_path'          => $path,
				'invoice_pdf_status'        => 'available',
				'invoice_pdf_downloaded_at' => current_time( 'mysql' ),
				'invoice_pdf_last_error'    => '',
			)
		);
		$order->add_order_note( sprintf( __( 'Factura fiscal %s generada y descargada desde Dolibarr.', 'dolisync' ), $invoice_ref ) );
		return $path;
	}

	public static function send_customer_invoice( WC_Order $order ) {
		self::init();
		// El correo normal de agradecimiento no espera a Dolibarr. La factura se
		// envía después, desde el worker, como un mensaje independiente.
		return self::has_pdf( $order ) && self::send_invoice_mail( $order, 'invoice_automatic' );
	}

	public static function resend_customer_invoice( WC_Order $order ) {
		self::init();
		return self::has_pdf( $order ) && self::send_invoice_mail( $order, 'invoice_manual' );
	}

	public static function send_invoice_unavailable_notice( WC_Order $order ) {
		if ( '' === (string) $order->get_billing_email() ) {
			return false;
		}
		$subject = sprintf( __( 'La factura de tu pedido #%s', 'dolisync' ), $order->get_order_number() );
		$body = sprintf( __( 'Hola %1$s,<br><br>Gracias por tu pedido #%2$s. Tu factura aún no está disponible. La estamos revisando y te la remitiremos en cuanto esté preparada.<br><br>%3$s', 'dolisync' ), esc_html( self::get_current_customer_name( $order ) ), esc_html( $order->get_order_number() ), esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) );
		$sent = wp_mail( $order->get_billing_email(), $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		self::record_email( $order, 'invoice_unavailable', $sent );
		self::update_relation( $order, array( 'invoice_email_status' => $sent ? 'unavailable_sent' : 'failed', 'invoice_email_sent_at' => $sent ? current_time( 'mysql' ) : null, 'invoice_email_last_error' => $sent ? '' : __( 'No se pudo enviar el aviso de factura no disponible.', 'dolisync' ) ) );
		return $sent;
	}

	private static function send_invoice_mail( WC_Order $order, $type ) {
		$path = self::get_pdf_path( $order );
		if ( ! self::is_safe_stored_pdf( $path ) || '' === (string) $order->get_billing_email() ) {
			return false;
		}
		$subject = sprintf( __( 'Aquí tienes la factura de tu pedido #%s', 'dolisync' ), $order->get_order_number() );
		$body = sprintf( __( 'Hola %1$s,<br><br>Gracias por tu pedido #%2$s. Tu factura ya está disponible y la encontrarás adjunta a este correo.<br><br>%3$s', 'dolisync' ), esc_html( self::get_current_customer_name( $order ) ), esc_html( $order->get_order_number() ), esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) );
		self::increment_email_attempts( $order, 'sending', '', true );
		self::$pending_email_attachments[ wp_normalize_path( $path ) ] = $order->get_id();
		$sent = wp_mail( $order->get_billing_email(), $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ), array( $path ) );
		self::record_email( $order, $type, $sent );
		if ( ! $sent ) {
			self::update_relation( $order, array( 'invoice_email_status' => 'error', 'invoice_email_last_error' => __( 'El servidor de correo rechazó el envío de la factura.', 'dolisync' ) ) );
			self::queue_email_retry( $order );
		}
		return $sent;
	}

	private static function get_current_customer_name( WC_Order $order ) {
		global $wpdb;
		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			$thirdparty_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT dolibarr_thirdparty_id FROM {$wpdb->prefix}dolisync_order_relations WHERE wc_order_id = %d LIMIT 1", $order->get_id() )
			);
			if ( $thirdparty_id > 0 ) {
				$user_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare( "SELECT wp_user_id FROM {$wpdb->prefix}dolisync_contact_relations WHERE dolibarr_contact_id = %d LIMIT 1", $thirdparty_id )
				);
			}
		}
		if ( $user_id > 0 ) {
			$first_name = sanitize_text_field( (string) get_user_meta( $user_id, 'first_name', true ) );
			if ( '' === $first_name ) {
				$first_name = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_first_name', true ) );
			}
			if ( '' !== $first_name ) {
				return $first_name;
			}
			$user = get_userdata( $user_id );
			if ( $user instanceof WP_User && '' !== trim( (string) $user->display_name ) ) {
				return sanitize_text_field( (string) $user->display_name );
			}
		}
		$name = sanitize_text_field( (string) $order->get_billing_first_name() );
		return '' !== $name ? $name : __( 'cliente', 'dolisync' );
	}

	private static function legacy_resend_customer_invoice( WC_Order $order ) {
		self::init();
		if ( ! self::has_pdf( $order ) || '' === (string) $order->get_billing_email() ) {
			return false;
		}
		$mailer = WC()->mailer();
		$emails = is_object( $mailer ) ? $mailer->get_emails() : array();
		$class_name = $order->has_status( 'on-hold' ) ? 'WC_Email_Customer_On_Hold_Order' : 'WC_Email_Customer_Processing_Order';
		$email = $emails[ $class_name ] ?? null;
		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			self::update_relation( $order, array( 'invoice_email_status' => 'error', 'invoice_email_last_error' => __( 'WooCommerce no tiene disponible el correo de confirmación.', 'dolisync' ) ) );
			return false;
		}
		self::increment_email_attempts( $order, 'sending', '', true );
		$email->trigger( $order->get_id(), $order );
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_email_status FROM {$table} WHERE wc_order_id = %d LIMIT 1", $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 'sent' !== $status ) {
			self::update_relation( $order, array( 'invoice_email_status' => 'error', 'invoice_email_last_error' => __( 'WooCommerce no ejecutó el envío; comprueba que el correo para clientes esté habilitado.', 'dolisync' ) ) );
			self::queue_email_retry( $order );
			return false;
		}
		return true;
	}

	public static function on_order_status_changed( $order_id, $from_status, $to_status, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		if ( Dolisync_Ignored_Items::is_ignored( 'order', $order->get_id(), 0 ) ) {
			return;
		}
		if ( ! self::has_pdf( $order ) ) {
			$invoice_id = (int) $order->get_meta( self::META_PENDING_ID, true );
			if ( $invoice_id > 0 ) {
				self::retry_delivery( $order->get_id(), $invoice_id );
			}
			return;
		}
		// Si el correo de procesamiento se genera a continuación, el filtro de
		// adjuntos utilizará el PDF que ya está almacenado.
	}

	public static function retry_delivery( $order_id, $invoice_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! self::has_pdf( $order ) ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
			$api = new Dolisync_API_Client();
			if ( '' === self::generate_and_store( $order, $api, (int) $invoice_id ) ) {
				return;
			}
		}
	}

	public static function retry_email( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order instanceof WC_Order || ! self::has_pdf( $order ) ) {
			return;
		}
		self::update_relation( $order, array( 'invoice_email_status' => 'retrying', 'invoice_email_next_retry_at' => null ) );
		self::send_invoice_mail( $order, 'invoice_automatic_retry' );
	}

	public static function attach_to_email( $attachments, $email_id, $object, $email = null ) {
		$attachments = is_array( $attachments ) ? $attachments : array();
		$order_confirmation_emails = array( 'customer_processing_order', 'customer_on_hold_order' );
		if ( ! in_array( (string) $email_id, $order_confirmation_emails, true ) || ! $object instanceof WC_Order ) {
			return $attachments;
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		if ( Dolisync_Ignored_Items::is_ignored( 'order', $object->get_id(), 0 ) ) {
			return $attachments;
		}
		$path = self::get_pdf_path( $object );
		if ( ! self::is_safe_stored_pdf( $path ) ) {
			$invoice_id = self::get_invoice_id( $object );
			if ( $invoice_id > 0 ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
				$path = self::generate_and_store( $object, new Dolisync_API_Client(), $invoice_id );
			}
		}
		if ( self::is_safe_stored_pdf( $path ) ) {
			$attachments[] = $path;
			self::$pending_email_attachments[ wp_normalize_path( $path ) ] = $object->get_id();
			self::increment_email_attempts( $object, 'sending', '' );
		}
		return array_values( array_unique( $attachments ) );
	}

	public static function on_mail_succeeded( $mail_data ) {
		$attachments = is_array( $mail_data ) ? (array) ( $mail_data['attachments'] ?? array() ) : array();
		foreach ( $attachments as $attachment ) {
			$path = wp_normalize_path( (string) $attachment );
			$order_id = (int) ( self::$pending_email_attachments[ $path ] ?? 0 );
			if ( $order_id <= 0 ) {
				continue;
			}
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( $order instanceof WC_Order ) {
				$order->update_meta_data( self::META_EMAILED_AT, current_time( 'mysql' ) );
				$order->save_meta_data();
				self::update_relation( $order, array( 'invoice_email_status' => 'sent', 'invoice_email_sent_at' => current_time( 'mysql' ), 'invoice_email_next_retry_at' => null, 'invoice_email_last_error' => '' ) );
			}
			unset( self::$pending_email_attachments[ $path ] );
		}
	}

	public static function on_mail_failed( $error ) {
		$data = $error instanceof WP_Error ? (array) $error->get_error_data() : array();
		foreach ( (array) ( $data['attachments'] ?? array() ) as $attachment ) {
			$path = wp_normalize_path( (string) $attachment );
			$order_id = (int) ( self::$pending_email_attachments[ $path ] ?? 0 );
			$order = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( $order instanceof WC_Order ) {
				self::update_relation( $order, array( 'invoice_email_status' => 'error', 'invoice_email_last_error' => $error->get_error_message() ) );
				self::queue_email_retry( $order );
			}
			unset( self::$pending_email_attachments[ $path ] );
		}
	}

	public static function on_woocommerce_email_sent( $sent, $email_id, $email ) {
		if ( ! in_array( (string) $email_id, array( 'customer_processing_order', 'customer_on_hold_order' ), true ) ) {
			return;
		}
		$order = is_object( $email ) && isset( $email->object ) ? $email->object : null;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		self::record_email( $order, 'order_thanks', (bool) $sent );
	}

	public static function get_email_history( WC_Order $order ) {
		$history = $order->get_meta( self::META_EMAIL_HISTORY, true );
		return is_array( $history ) ? array_reverse( $history ) : array();
	}

	private static function record_email( WC_Order $order, $type, $sent ) {
		$history = $order->get_meta( self::META_EMAIL_HISTORY, true );
		$history = is_array( $history ) ? $history : array();
		$history[] = array(
			'type'   => sanitize_key( $type ),
			'status' => $sent ? 'accepted' : 'failed',
			'at'     => current_time( 'mysql' ),
		);
		$history = array_slice( $history, -30 );
		$order->update_meta_data( self::META_EMAIL_HISTORY, $history );
		$order->save_meta_data();
	}

	private static function queue_email_retry( WC_Order $order ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_email_attempts FROM {$table} WHERE wc_order_id = %d LIMIT 1", $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $attempts >= self::MAX_EMAIL_ATTEMPTS ) {
			self::update_relation( $order, array( 'invoice_email_status' => 'failed', 'invoice_email_next_retry_at' => null ) );
			return;
		}
		$args = array( $order->get_id() );
		$timestamp = time() + self::EMAIL_RETRY_DELAY;
		if ( ! wp_next_scheduled( 'dolisync_retry_invoice_email', $args ) ) {
			wp_schedule_single_event( $timestamp, 'dolisync_retry_invoice_email', $args );
		}
		self::update_relation( $order, array( 'invoice_email_status' => 'queued', 'invoice_email_next_retry_at' => wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() ) ) );
	}

	private static function get_invoice_id( WC_Order $order ) {
		$invoice_id = (int) $order->get_meta( self::META_PENDING_ID, true );
		if ( $invoice_id > 0 ) {
			return $invoice_id;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 0;
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT dolibarr_invoice_id FROM {$table} WHERE wc_order_id = %d AND invoice_status IN ('validated', 'paid') LIMIT 1",
				$order->get_id()
			)
		);
	}

	private static function log_failure( WC_Order $order, $action, $message ) {
		if ( ! class_exists( 'Dolisync_Action_Logger' ) ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
		}
		Dolisync_Action_Logger::log_action( 'factura', $action, 'error', sprintf( __( 'Pedido WooCommerce %1$d: %2$s', 'dolisync' ), $order->get_id(), sanitize_text_field( (string) $message ) ), get_current_user_id() );
		self::update_relation( $order, array( 'invoice_pdf_status' => 'error', 'invoice_pdf_last_error' => sanitize_text_field( (string) $message ) ) );
		$invoice_id = (int) $order->get_meta( self::META_PENDING_ID, true );
		$retry_count = (int) $order->get_meta( self::META_RETRY_COUNT, true );
		$args = array( $order->get_id(), $invoice_id );
		if ( $invoice_id > 0 && $retry_count < 3 && ! wp_next_scheduled( 'dolisync_retry_invoice_delivery', $args ) ) {
			$order->update_meta_data( self::META_RETRY_COUNT, $retry_count + 1 );
			$order->save_meta_data();
			wp_schedule_single_event( time() + 60, 'dolisync_retry_invoice_delivery', $args );
		}
	}

	private static function increment_email_attempts( WC_Order $order, $status, $error, $force = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$where = $force ? '' : " AND invoice_email_status <> 'sending'";
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET invoice_email_attempts = invoice_email_attempts + 1, invoice_email_status = %s, invoice_email_last_error = %s, updated_at = %s WHERE wc_order_id = %d{$where}", $status, $error, current_time( 'mysql' ), $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function update_relation( WC_Order $order, array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $table, $data, array( 'wc_order_id' => $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function store_pdf( WC_Order $order, $invoice_ref, $binary ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$private_dir = trailingslashit( $uploads['basedir'] ) . 'dolisync-private-' . substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 20 );
		if ( ! wp_mkdir_p( $private_dir ) ) {
			return '';
		}
		if ( ! file_exists( $private_dir . '/index.php' ) ) {
			file_put_contents( $private_dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $private_dir . '/.htaccess' ) ) {
			file_put_contents( $private_dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$filename = 'factura-' . sanitize_file_name( $invoice_ref ) . '-pedido-' . $order->get_id() . '.pdf';
		$path = wp_normalize_path( trailingslashit( $private_dir ) . $filename );
		return false !== file_put_contents( $path, $binary, LOCK_EX ) ? $path : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function is_safe_stored_pdf( $path ) {
		if ( '' === (string) $path || ! is_file( $path ) || ! is_readable( $path ) || 'pdf' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return false;
		}
		$uploads = wp_upload_dir();
		$base = wp_normalize_path( trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . 'dolisync-private-' );
		return '' !== $base && 0 === strpos( wp_normalize_path( $path ), $base );
	}

	private static function find_pdf_path( $data, $fallback ) {
		$walk = function ( $value ) use ( &$walk ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				foreach ( (array) $value as $child ) {
					$found = $walk( $child );
					if ( '' !== $found ) {
						return $found;
					}
				}
				return '';
			}
			$value = str_replace( '\\', '/', (string) $value );
			return preg_match( '/\.pdf$/i', $value ) && false === strpos( $value, '..' ) && false === strpos( $value, ':' ) && '/' !== substr( $value, 0, 1 ) ? $value : '';
		};
		$found = $walk( $data );
		if ( '' === $fallback ) {
			return $found;
		}
		$expected_directory = trailingslashit( dirname( $fallback ) );
		return '' !== $found && 0 === strpos( $found, $expected_directory ) ? $found : $fallback;
	}

	private static function normalize_array( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		return is_array( $data ) ? $data : array();
	}
}
