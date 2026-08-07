<?php
/**
 * Sincronización de pedidos WooCommerce hacia Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Order_Sync {
	private const META_INVOICE_LINES_COMPLETE = '_dolisync_dolibarr_invoice_lines_complete';
	public static function sync_invoice_payment_status( $order_id, $wc_status = '' ) {
		if ( ! in_array( (string) $wc_status, array( 'processing', 'completed' ), true ) ) {
			return;
		}
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-identity-resolver.php';
		Dolisync_Schema::ensure_order_relations_table();
		$relation = self::get_order_relation( (int) $order_id );
		$invoice_id = (int) ( $relation['dolibarr_invoice_id'] ?? 0 );
		if ( $invoice_id <= 0 ) {
			return;
		}

		$api = new Dolisync_API_Client();
		$invoice_response = $api->get( '/invoices/' . $invoice_id );
		if ( empty( $invoice_response['success'] ) ) {
			Dolisync_Action_Logger::log_action( 'factura', 'sincronización_pago', 'error', sprintf( __( 'No se pudo consultar la factura Dolibarr %1$d para sincronizar el estado pagado del pedido WooCommerce %2$d.', 'dolisync' ), $invoice_id, (int) $order_id ), get_current_user_id() );
			return;
		}
		$invoice = self::first_api_item( $invoice_response['data'] ?? array() );
		$is_paid = ! empty( $invoice['paye'] ) || (int) ( $invoice['statut'] ?? $invoice['status'] ?? 0 ) >= 2;
		if ( $is_paid ) {
			return;
		}

		$response = $api->post(
			'/invoices/' . $invoice_id . '/settopaid',
			array()
		);
		if ( empty( $response['success'] ) ) {
			$message = (string) ( $response['api_message'] ?? $response['message'] ?? __( 'Error desconocido', 'dolisync' ) );
			Dolisync_Action_Logger::log_action( 'factura', 'sincronización_pago', 'error', sprintf( __( 'No se pudo marcar como pagada la factura Dolibarr %1$d del pedido WooCommerce %2$d: %3$s', 'dolisync' ), $invoice_id, (int) $order_id, $message ), get_current_user_id() );
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( $order instanceof WC_Order ) {
			self::upsert_order_relation( $order, array( 'invoice_status' => 'paid', 'sync_status' => 'success', 'last_error_message' => '' ) );
			$order->add_order_note( sprintf( __( 'Factura Dolibarr %d marcada como pagada al cambiar el pedido a %s.', 'dolisync' ), $invoice_id, $wc_status ) );
		}
		Dolisync_Action_Logger::log_action( 'factura', 'sincronización_pago', 'finalizado', sprintf( __( 'Factura Dolibarr %1$d marcada como pagada desde el pedido WooCommerce %2$d.', 'dolisync' ), $invoice_id, (int) $order_id ), get_current_user_id() );
	}

	public static function sync_order( $order_id, $order = null ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// Dar margen al flujo completo cuando VeriFactu tarda en responder. Algunos
		// alojamientos no permiten modificar este límite, por eso es best-effort.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-identity-resolver.php';
		Dolisync_Schema::ensure_order_relations_table();
		Dolisync_Schema::ensure_contact_relations_table();

		self::progress( $order_id, 'loading', __( 'Cargando y comprobando los datos del pedido…', 'dolisync' ) );
		$order = self::normalize_order( $order_id, $order );
		if ( ! $order ) {
			Dolisync_Action_Logger::log_action( 'pedido', 'sincronización', 'error', sprintf( __( 'No se pudo cargar el pedido WooCommerce %d.', 'dolisync' ), (int) $order_id ), get_current_user_id() );
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			Dolisync_Action_Logger::log_action( 'pedido', 'sincronización', 'error', sprintf( __( 'El pedido WooCommerce %d no es válido.', 'dolisync' ), (int) $order_id ), get_current_user_id() );
			return;
		}

		$order_id = (int) $order->get_id();
		$relation = self::get_order_relation( $order_id );
		if ( ! empty( $relation['dolibarr_invoice_id'] ) && in_array( (string) ( $relation['invoice_status'] ?? '' ), array( 'validated', 'paid' ), true ) ) {
			self::progress( $order_id, 'recovering_pdf', __( 'La factura ya existe. Rescatando el PDF de Dolibarr…', 'dolisync' ) );
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-invoice-pdf.php';
			if ( ! Dolisync_Invoice_PDF::has_pdf( $order ) ) {
				$recovery_api = new Dolisync_API_Client();
				Dolisync_Invoice_PDF::generate_and_store( $order, $recovery_api, (int) $relation['dolibarr_invoice_id'] );
			}
			if ( Dolisync_Invoice_PDF::has_pdf( $order ) ) {
				self::upsert_order_relation( $order, array( 'sync_status' => 'success', 'invoice_status' => (string) $relation['invoice_status'], 'last_error_message' => '' ) );
			}
			return;
		}
		$api = new Dolisync_API_Client();
		self::progress( $order_id, 'customer', __( 'Validando los datos fiscales y preparando el cliente en Dolibarr…', 'dolisync' ) );
		$document_id = self::get_validated_document_id( $order );
		if ( '' === $document_id ) {
			self::upsert_order_relation( $order, array( 'sync_status' => 'error', 'last_error_message' => __( 'El pedido no contiene un documento fiscal válido.', 'dolisync' ) ) );
			Dolisync_Action_Logger::log_action( 'pedido', 'validación_cliente', 'error', sprintf( __( 'Pedido WooCommerce %d no enviado: falta un DNI/NIE/CIF/pasaporte válido.', 'dolisync' ), $order_id ), get_current_user_id() );
			return;
		}
		$thirdparty_id = self::resolve_thirdparty_id_for_order( $order, $api, $document_id );
		if ( $thirdparty_id <= 0 ) {
			self::upsert_order_relation(
				$order,
				array(
					'sync_status' => 'error',
					'last_error_message' => __( 'No se pudo resolver el tercero en Dolibarr.', 'dolisync' ),
				)
			);
			Dolisync_Action_Logger::log_action( 'pedido', 'sincronización', 'error', sprintf( __( 'Pedido WooCommerce %d omitido: no se pudo resolver el tercero en Dolibarr.', 'dolisync' ), $order_id ), get_current_user_id() );
			return;
		}
		self::persist_customer_thirdparty_relation( $order, $thirdparty_id, $document_id );

		$invoice_external_ref = 'WC-INVOICE-' . $order_id;
		self::progress( $order_id, 'invoice_lookup', __( 'Buscando una factura existente para evitar duplicados…', 'dolisync' ) );
		$existing_invoice_response = $api->get( '/invoices/ref_ext/' . rawurlencode( $invoice_external_ref ) );
		$dolibarr_invoice_id = ! empty( $existing_invoice_response['success'] ) ? self::extract_dolibarr_id( $existing_invoice_response['data'] ?? null ) : 0;
		$invoice_already_validated = false;
		$invoice_was_recovered = $dolibarr_invoice_id > 0;

		if ( $dolibarr_invoice_id > 0 ) {
			self::progress( $order_id, 'invoice_recovered', __( 'Factura encontrada en Dolibarr. Recuperando su estado…', 'dolisync' ) );
			$existing_invoice_data = self::first_api_item( $existing_invoice_response['data'] ?? array() );
			$invoice_already_validated = (int) ( $existing_invoice_data['statut'] ?? $existing_invoice_data['status'] ?? 0 ) > 0;
			self::upsert_order_relation( $order, array( 'dolibarr_thirdparty_id' => $thirdparty_id, 'dolibarr_order_id' => null, 'dolibarr_invoice_id' => $dolibarr_invoice_id, 'invoice_status' => $invoice_already_validated ? 'validated' : 'draft', 'sync_status' => 'recovered', 'last_error_message' => '' ) );
			$lines_complete_for_invoice = (int) $order->get_meta( self::META_INVOICE_LINES_COMPLETE, true );
			if ( ! $invoice_already_validated && $lines_complete_for_invoice !== $dolibarr_invoice_id ) {
				self::upsert_order_relation( $order, array( 'sync_status' => 'error', 'invoice_status' => 'draft', 'last_error_message' => __( 'La factura provisional pertenece a este pedido, pero no consta que todas sus líneas se guardaran correctamente.', 'dolisync' ) ) );
				Dolisync_Action_Logger::log_action( 'factura', 'recuperación', 'error', sprintf( __( 'La factura provisional Dolibarr %1$d está vinculada al pedido WooCommerce %2$d, pero requiere revisión porque la inserción de líneas no finalizó.', 'dolisync' ), $dolibarr_invoice_id, $order_id ), get_current_user_id() );
				return;
			}
		} else {
			self::progress( $order_id, 'invoice_create', __( 'Creando la factura en Dolibarr…', 'dolisync' ) );
			$invoice_response = $api->post( '/invoices', self::build_dolibarr_invoice_payload( $order, $thirdparty_id, $invoice_external_ref ) );
			if ( empty( $invoice_response['success'] ) ) {
				self::upsert_order_relation( $order, array( 'dolibarr_thirdparty_id' => $thirdparty_id, 'sync_status' => 'error', 'last_error_message' => (string) ( $invoice_response['message'] ?? __( 'Error creando la factura en Dolibarr.', 'dolisync' ) ) ) );
				Dolisync_Action_Logger::log_action( 'factura', 'creación', 'error', sprintf( __( 'No se pudo crear la factura del pedido WooCommerce %1$d: %2$s', 'dolisync' ), $order_id, (string) ( $invoice_response['message'] ?? __( 'Error desconocido', 'dolisync' ) ) ), get_current_user_id() );
				return;
			}
			$dolibarr_invoice_id = self::extract_dolibarr_id( $invoice_response['data'] ?? null );
			if ( $dolibarr_invoice_id <= 0 ) {
				$dolibarr_invoice_id = self::extract_dolibarr_id( $invoice_response['message'] ?? null );
			}
			if ( $dolibarr_invoice_id <= 0 ) {
				self::upsert_order_relation( $order, array( 'dolibarr_thirdparty_id' => $thirdparty_id, 'sync_status' => 'error', 'last_error_message' => __( 'Dolibarr no devolvió el ID de la factura creada.', 'dolisync' ) ) );
				return;
			}
			// Guardar el ID antes de añadir líneas evita duplicar la factura si una llamada posterior falla.
			self::upsert_order_relation( $order, array( 'dolibarr_thirdparty_id' => $thirdparty_id, 'dolibarr_order_id' => null, 'dolibarr_invoice_id' => $dolibarr_invoice_id, 'invoice_status' => 'draft', 'sync_status' => 'processing', 'last_error_message' => '' ) );
		}

		if ( ! $invoice_already_validated && ! $invoice_was_recovered ) {
			self::progress( $order_id, 'invoice_lines', __( 'Añadiendo las líneas del pedido a la factura…', 'dolisync' ) );
			foreach ( self::build_invoice_lines( $order ) as $line ) {
				$line_response = $api->post( '/invoices/' . $dolibarr_invoice_id . '/lines', $line );
				if ( empty( $line_response['success'] ) && 'ok' !== (string) ( $line_response['code'] ?? '' ) ) {
					self::upsert_order_relation(
						$order,
						array(
							'dolibarr_thirdparty_id' => $thirdparty_id,
							'dolibarr_invoice_id' => $dolibarr_invoice_id,
							'invoice_status' => 'draft',
							'sync_status' => 'error',
							'last_error_message' => (string) ( $line_response['message'] ?? __( 'Error añadiendo una línea a la factura.', 'dolisync' ) ),
						)
					);
					Dolisync_Action_Logger::log_action( 'factura', 'línea_factura', 'error', sprintf( __( 'Error añadiendo una línea a la factura Dolibarr %1$d del pedido WooCommerce %2$d.', 'dolisync' ), $dolibarr_invoice_id, $order_id ), get_current_user_id() );
					return;
				}
			}
			$order->update_meta_data( self::META_INVOICE_LINES_COMPLETE, $dolibarr_invoice_id );
			$order->save_meta_data();
		}

		if ( ! $invoice_already_validated ) {
			self::progress( $order_id, 'invoice_validate', __( 'Validando la factura y esperando a VeriFactu…', 'dolisync' ) );
			$invoice_validate = $api->post( '/invoices/' . $dolibarr_invoice_id . '/validate', array( 'idwarehouse' => 0, 'notrigger' => 0 ) );
			if ( empty( $invoice_validate['success'] ) ) {
				self::upsert_order_relation(
					$order,
					array(
						'dolibarr_thirdparty_id' => $thirdparty_id,
						'dolibarr_order_id' => null,
						'dolibarr_invoice_id' => $dolibarr_invoice_id,
						'sync_status' => 'error',
						'last_error_message' => (string) ( $invoice_validate['api_message'] ?? $invoice_validate['message'] ?? __( 'Error validando la factura.', 'dolisync' ) ),
					)
				);
				Dolisync_Action_Logger::log_action( 'factura', 'validación', 'error', sprintf( __( 'Error validando la factura Dolibarr %1$d del pedido WooCommerce %2$d: %3$s', 'dolisync' ), $dolibarr_invoice_id, $order_id, (string) ( $invoice_validate['api_message'] ?? $invoice_validate['message'] ?? __( 'Dolibarr no indicó el motivo.', 'dolisync' ) ) ), get_current_user_id() );
				return;
			}
			$validated_invoice_response = $api->get( '/invoices/' . $dolibarr_invoice_id );
			$validated_invoice = ! empty( $validated_invoice_response['success'] ) ? self::first_api_item( $validated_invoice_response['data'] ?? array() ) : array();
			if ( empty( $validated_invoice ) || (int) ( $validated_invoice['statut'] ?? $validated_invoice['status'] ?? 0 ) <= 0 ) {
				$message = (string) ( $validated_invoice_response['api_message'] ?? $validated_invoice_response['message'] ?? __( 'Dolibarr respondió a la validación, pero la factura continúa provisional.', 'dolisync' ) );
				self::upsert_order_relation( $order, array( 'dolibarr_thirdparty_id' => $thirdparty_id, 'dolibarr_invoice_id' => $dolibarr_invoice_id, 'invoice_status' => 'draft', 'sync_status' => 'error', 'last_error_message' => $message ) );
				Dolisync_Action_Logger::log_action( 'factura', 'verificación_validación', 'error', sprintf( __( 'La factura Dolibarr %1$d del pedido WooCommerce %2$d continúa provisional después de solicitar su validación: %3$s', 'dolisync' ), $dolibarr_invoice_id, $order_id, $message ), get_current_user_id() );
				return;
			}
		}

		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/orders/class-dolisync-invoice-pdf.php';
		self::progress( $order_id, 'pdf', __( 'Generando y rescatando el PDF de la factura…', 'dolisync' ) );
		$pdf_path = Dolisync_Invoice_PDF::generate_and_store( $order, $api, $dolibarr_invoice_id );
		if ( '' === $pdf_path ) {
			self::upsert_order_relation( $order, array( 'dolibarr_thirdparty_id' => $thirdparty_id, 'dolibarr_order_id' => null, 'dolibarr_invoice_id' => $dolibarr_invoice_id, 'invoice_status' => 'validated', 'sync_status' => 'error', 'last_error_message' => __( 'La factura está validada, pero no se pudo obtener su PDF fiscal de Dolibarr.', 'dolisync' ) ) );
			Dolisync_Action_Logger::log_action( 'pedido', 'pdf_factura', 'error', sprintf( __( 'La factura Dolibarr %1$d del pedido WooCommerce %2$d está validada, pero su PDF no pudo descargarse.', 'dolisync' ), $dolibarr_invoice_id, $order_id ), get_current_user_id() );
			return;
		}
		self::upsert_order_relation(
			$order,
			array(
				'dolibarr_thirdparty_id' => $thirdparty_id,
				'dolibarr_order_id' => null,
				'dolibarr_invoice_id' => $dolibarr_invoice_id > 0 ? $dolibarr_invoice_id : null,
				'order_status' => (string) $order->get_status(),
				'invoice_status' => $dolibarr_invoice_id > 0 ? 'validated' : null,
				'sync_status' => 'success',
				'last_error_message' => '',
			)
		);

		Dolisync_Action_Logger::log_action( 'factura', 'sincronización', 'finalizado', sprintf( __( 'Pedido WooCommerce %1$d facturado directamente en Dolibarr (factura %2$d validada).', 'dolisync' ), $order_id, $dolibarr_invoice_id ), get_current_user_id() );
	}

	private static function progress( $order_id, $stage, $message ) {
		if ( class_exists( 'Dolisync_Order_Queue' ) ) {
			Dolisync_Order_Queue::set_progress( (int) $order_id, $stage, $message );
		}
	}

	private static function normalize_order( $order_id, $order = null ) {
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return null;
		}

		return function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	}

	private static function get_validated_document_id( WC_Order $order ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/utils/class-dolisync-spanish-document-validator.php';
		$validator = new Dolisync_Spanish_Document_Validator();
		$user_id = (int) $order->get_user_id();
		if ( $user_id > 0 ) {
			$current_customer_document = get_user_meta( $user_id, 'dolisync_document_id', true );
			$current_result = $validator->validate( $current_customer_document );
			if ( ! empty( $current_result['valid'] ) ) {
				$normalized = (string) $current_result['normalized'];
				// En un reintento debe prevalecer la corrección realizada en la ficha
				// del cliente sobre la copia histórica guardada durante el checkout.
				if ( $normalized !== (string) $order->get_meta( 'dolisync_document_id', true ) ) {
					$order->update_meta_data( 'dolisync_document_id', $normalized );
					$order->save_meta_data();
				}
				return $normalized;
			}
		}
		$values = array(
			$order->get_meta( 'dolisync_document_id', true ),
			$order->get_meta( 'dolisync/document-id', true ),
			$order->get_meta( '_wc_other/dolisync/document-id', true ),
		);

		foreach ( $values as $value ) {
			$result = $validator->validate( $value );
			if ( ! empty( $result['valid'] ) ) {
				return (string) $result['normalized'];
			}
		}
		return '';
	}

	private static function resolve_thirdparty_id_for_order( WC_Order $order, Dolisync_API_Client $api, $document_id ) {
		$email = sanitize_email( (string) $order->get_billing_email() );
		$user_id = (int) $order->get_user_id();
		$linked_thirdparty_id = self::get_linked_thirdparty_id( $user_id );
		if ( $linked_thirdparty_id > 0 ) {
			$linked_response = $api->get( '/thirdparties/' . $linked_thirdparty_id );
			$linked_thirdparty = ! empty( $linked_response['success'] ) ? self::first_api_item( $linked_response['data'] ?? array() ) : array();
			$linked_email = sanitize_email( (string) ( $linked_thirdparty['email'] ?? '' ) );
			if ( '' !== $linked_email && '' !== $email && hash_equals( strtolower( $linked_email ), strtolower( $email ) ) ) {
				// La relación local y el email confirman que es el mismo tercero. Esto
				// permite corregir su DNI sin que el valor antiguo provoque conflicto.
				return self::update_thirdparty_document( $api, $linked_thirdparty_id, $document_id );
			}
		}
		$identity = Dolisync_Contact_Identity_Resolver::resolve_dolibarr_thirdparty( $api, $document_id, $email );
		if ( 'conflict' === $identity['status'] ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-conflicts.php';
			$dolibarr_conflict_id = (int) ( $identity['document_match_id'] ?? $identity['email_match_id'] ?? 0 );
			Dolisync_Contact_Conflicts::record( 'order_to_dolibarr', 'identity', $user_id, $dolibarr_conflict_id, Dolisync_Contact_Conflicts::snapshot_wp_user( $user_id ), Dolisync_Contact_Conflicts::fetch_dolibarr_snapshot( $api, $dolibarr_conflict_id ), (string) $identity['message'] );
			Dolisync_Action_Logger::log_action( 'tercero', 'resolución_identidad', 'error', (string) $identity['message'], get_current_user_id() );
			return 0;
		}
		if ( 'matched' === $identity['status'] ) {
			return self::update_thirdparty_document( $api, (int) $identity['id'], $document_id );
		}

		$payload = self::build_thirdparty_payload( $order, $document_id, true );

		$response = $api->post( '/thirdparties', $payload );
		if ( empty( $response['success'] ) ) {
			return 0;
		}

		$dolibarr_id = self::extract_dolibarr_id( $response['data'] ?? null );
		if ( $dolibarr_id <= 0 ) {
			$dolibarr_id = self::extract_dolibarr_id( $response['message'] ?? null );
		}

		if ( $dolibarr_id > 0 && $user_id > 0 ) {
			self::persist_customer_thirdparty_relation( $order, $dolibarr_id, $document_id );
		}

		return (int) $dolibarr_id;
	}

	private static function get_linked_thirdparty_id( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT dolibarr_contact_id FROM {$wpdb->prefix}dolisync_contact_relations WHERE wp_user_id = %d LIMIT 1", $user_id )
		);
	}

	private static function build_thirdparty_payload( WC_Order $order, $document_id, $creating = false ) {
		$first_name = sanitize_text_field( (string) $order->get_billing_first_name() );
		$last_name = sanitize_text_field( (string) $order->get_billing_last_name() );
		$full_name = trim( $first_name . ' ' . $last_name );
		$company = sanitize_text_field( (string) $order->get_billing_company() );
		$name = '' !== $company ? $company : $full_name;
		if ( '' === $name ) {
			$name = sanitize_email( (string) $order->get_billing_email() );
		}

		$address_parts = array_filter(
			array(
				sanitize_text_field( (string) $order->get_billing_address_1() ),
				sanitize_text_field( (string) $order->get_billing_address_2() ),
			)
		);

		$payload = array(
			'name' => $name,
			'name_alias' => $full_name,
			'email' => sanitize_email( (string) $order->get_billing_email() ),
			'phone' => sanitize_text_field( (string) $order->get_billing_phone() ),
			'address' => implode( "\n", $address_parts ),
			'zip' => sanitize_text_field( (string) $order->get_billing_postcode() ),
			'town' => sanitize_text_field( (string) $order->get_billing_city() ),
			'country_code' => strtoupper( sanitize_text_field( (string) $order->get_billing_country() ) ),
			'idprof1' => $document_id,
			'client' => 1,
			'status' => 1,
			'caller' => 'dolisync',
		);
		if ( $creating ) {
			$payload['code_client'] = 'auto';
		}
		return $payload;
	}

	/**
	 * Consolida la relación cliente-tercero aunque el tercero se haya encontrado
	 * por DNI/email y no haya sido creado durante este pedido.
	 */
	private static function persist_customer_thirdparty_relation( WC_Order $order, $thirdparty_id, $document_id ) {
		global $wpdb;
		$user_id = (int) $order->get_user_id();
		$thirdparty_id = absint( $thirdparty_id );
		if ( $user_id <= 0 || $thirdparty_id <= 0 ) {
			return;
		}

		$table = $wpdb->prefix . 'dolisync_contact_relations';
		$by_thirdparty = $wpdb->get_row( $wpdb->prepare( "SELECT id, wp_user_id FROM {$table} WHERE dolibarr_contact_id = %d LIMIT 1", $thirdparty_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! empty( $by_thirdparty ) && (int) $by_thirdparty['wp_user_id'] !== $user_id ) {
			return;
		}

		$now = current_time( 'mysql' );
		$current_first_name = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_first_name', true ) );
		$current_last_name = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_last_name', true ) );
		if ( '' === $current_first_name ) {
			$current_first_name = sanitize_text_field( (string) get_user_meta( $user_id, 'first_name', true ) );
		}
		if ( '' === $current_last_name ) {
			$current_last_name = sanitize_text_field( (string) get_user_meta( $user_id, 'last_name', true ) );
		}
		$data = array(
			'dolibarr_contact_id' => $thirdparty_id,
			'wp_user_id'          => $user_id,
			'dni'                 => (string) $document_id,
			'email'               => sanitize_email( (string) $order->get_billing_email() ),
			'first_name'          => '' !== $current_first_name ? $current_first_name : sanitize_text_field( (string) $order->get_billing_first_name() ),
			'last_name'           => '' !== $current_last_name ? $current_last_name : sanitize_text_field( (string) $order->get_billing_last_name() ),
			'synced_at'           => $now,
			'source'              => 'woocommerce_order',
			'updated_at'          => $now,
		);
		$by_user = $wpdb->get_row( $wpdb->prepare( "SELECT id, first_synced_at, created_at FROM {$table} WHERE wp_user_id = %d LIMIT 1", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! empty( $by_user['id'] ) ) {
			if ( empty( $by_user['first_synced_at'] ) ) {
				$data['first_synced_at'] = ! empty( $by_user['created_at'] ) ? $by_user['created_at'] : $now;
			}
			$wpdb->update( $table, $data, array( 'id' => (int) $by_user['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$data['first_synced_at'] = $now;
		$data['created_at'] = $now;
		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function update_thirdparty_document( Dolisync_API_Client $api, $thirdparty_id, $document_id ) {
		$response = $api->put(
			'/thirdparties/' . (int) $thirdparty_id,
			array(
				'idprof1' => (string) $document_id,
				'caller'  => 'dolisync',
			)
		);
		if ( empty( $response['success'] ) ) {
			Dolisync_Action_Logger::log_action( 'tercero', 'actualización_fiscal', 'error', sprintf( __( 'No se pudo actualizar el documento fiscal del tercero Dolibarr %1$d: %2$s', 'dolisync' ), (int) $thirdparty_id, (string) ( $response['message'] ?? __( 'Error desconocido', 'dolisync' ) ) ), get_current_user_id() );
			return 0;
		}
		return (int) $thirdparty_id;
	}

	private static function thirdparty_accepts_document( Dolisync_API_Client $api, $thirdparty_id, $document_id ) {
		$response = $api->get( '/thirdparties/' . (int) $thirdparty_id );
		if ( empty( $response['success'] ) ) {
			return false;
		}
		$data = is_object( $response['data'] ?? null ) ? json_decode( wp_json_encode( $response['data'] ), true ) : (array) ( $response['data'] ?? array() );
		$current = strtoupper( preg_replace( '/[\s-]/', '', (string) ( $data['idprof1'] ?? $data['siren'] ?? '' ) ) );
		if ( '' === $current ) {
			$update = $api->put( '/thirdparties/' . (int) $thirdparty_id, array( 'idprof1' => $document_id, 'caller' => 'dolisync' ) );
			return ! empty( $update['success'] );
		}
		return hash_equals( $current, (string) $document_id );
	}

	private static function build_dolibarr_invoice_payload( WC_Order $order, $thirdparty_id, $external_ref ) {
		return array(
			'socid' => (int) $thirdparty_id,
			'date' => (int) $order->get_date_created()->getTimestamp(),
			'type' => 0,
			'ref_ext' => sanitize_text_field( $external_ref ),
			'note_private' => sanitize_textarea_field( (string) $order->get_customer_note() ),
			'note_public' => sanitize_textarea_field( sprintf( 'Pedido WooCommerce #%s', $order->get_order_number() ) ),
			'multicurrency_code' => get_woocommerce_currency(),
			'caller' => 'dolisync',
		);
	}

	private static function build_invoice_lines( WC_Order $order ) {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$product_id = $product ? (int) $product->get_id() : 0;
			$qty = (float) $item->get_quantity();
			$total = (float) $item->get_total();
			$tax_total = (float) $item->get_total_tax();
			$tax_rate = self::resolve_item_tax_rate( $item, $total, $tax_total );

			$line = array(
				'desc' => sanitize_text_field( (string) $item->get_name() ),
				'subprice' => $qty > 0 ? round( $total / $qty, 6 ) : $total,
				'qty' => $qty,
				'tva_tx' => $tax_rate,
				'localtax1_tx' => 0,
				'localtax2_tx' => 0,
				'price_base_type' => 'HT',
				'info_bits' => 0,
				'date_start' => null,
				'date_end' => null,
				'product_type' => 0,
				'fk_parent_line' => 0,
				'fk_product' => self::resolve_dolibarr_product_id( $product_id ),
				'fk_fournprice' => null,
				'pa_ht' => 0,
				'label' => sanitize_text_field( (string) $item->get_name() ),
				'special_code' => 0,
				'array_options' => array(),
				'fk_unit' => null,
				'multicurrency_subprice' => null,
				'ref_ext' => 'WC-ITEM-' . $item->get_id(),
				'rang' => -1,
				'caller' => 'dolisync',
			);

			$lines[] = $line;
		}

		$shipping_total = (float) $order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			$shipping_tax = (float) $order->get_shipping_tax();
			$shipping_rate = self::resolve_shipping_tax_rate( $order, $shipping_total, $shipping_tax );

			$lines[] = array(
				'desc' => __( 'Gastos de envío', 'dolisync' ),
				'subprice' => $shipping_total,
				'qty' => 1,
				'tva_tx' => $shipping_rate,
				'localtax1_tx' => 0,
				'localtax2_tx' => 0,
				'price_base_type' => 'HT',
				'info_bits' => 0,
				'date_start' => null,
				'date_end' => null,
				'product_type' => 1,
				'fk_parent_line' => 0,
				'fk_product' => 0,
				'fk_fournprice' => null,
				'pa_ht' => 0,
				'label' => __( 'Shipping', 'dolisync' ),
				'special_code' => 0,
				'array_options' => array(),
				'fk_unit' => null,
				'multicurrency_subprice' => null,
				'ref_ext' => 'WC-SHIPPING-' . $order->get_id(),
				'rang' => -1,
				'caller' => 'dolisync',
			);
		}

		return $lines;
	}

	private static function resolve_item_tax_rate( WC_Order_Item_Product $item, $total, $tax_total ) {
		$taxes = $item->get_taxes();
		$rate_ids = isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? array_keys( $taxes['total'] ) : array();
		foreach ( $rate_ids as $rate_id ) {
			if ( abs( (float) ( $taxes['total'][ $rate_id ] ?? 0 ) ) > 0.000001 ) {
				return self::resolve_exact_tax_rate( $rate_id, $total, $tax_total );
			}
		}
		return self::resolve_exact_tax_rate( reset( $rate_ids ), $total, $tax_total );
	}

	private static function resolve_shipping_tax_rate( WC_Order $order, $total, $tax_total ) {
		$fallback_rate_id = 0;
		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			if ( ! $tax_item instanceof WC_Order_Item_Tax ) {
				continue;
			}
			$rate_id = (int) $tax_item->get_rate_id();
			if ( $rate_id > 0 && 0 === $fallback_rate_id ) {
				$fallback_rate_id = $rate_id;
			}
			if ( abs( (float) $tax_item->get_shipping_tax_total() ) > 0.000001 ) {
				return self::resolve_exact_tax_rate( $rate_id, $total, $tax_total );
			}
		}
		return self::resolve_exact_tax_rate( $fallback_rate_id, $total, $tax_total );
	}

	private static function resolve_exact_tax_rate( $rate_id, $total, $tax_total ) {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
		$rate_id = absint( $rate_id );
		$mapping = Dolisync_Config::get_tax_mapping();
		if ( $rate_id > 0 && isset( $mapping[ (string) $rate_id ] ) && is_numeric( $mapping[ (string) $rate_id ] ) ) {
			return self::normalize_tax_rate( $mapping[ (string) $rate_id ] );
		}

		if ( $rate_id > 0 && class_exists( 'WC_Tax' ) ) {
			$configured_rate = WC_Tax::get_rate_percent_value( $rate_id );
			if ( is_numeric( $configured_rate ) ) {
				return self::normalize_tax_rate( $configured_rate );
			}
		}

		// Solo como último recurso: el cociente de totales puede contener errores
		// de redondeo, por lo que se fuerza a un porcentaje entero exacto.
		$derived = (float) $total !== 0.0 ? ( (float) $tax_total / (float) $total ) * 100 : 0;
		return (int) round( $derived, 0 );
	}

	private static function normalize_tax_rate( $rate ) {
		$rate = round( (float) $rate, 4 );
		return abs( $rate - round( $rate ) ) < 0.00001 ? (int) round( $rate ) : $rate;
	}

	private static function resolve_dolibarr_product_id( $wc_product_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_relations';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT dolibarr_product_id FROM {$table} WHERE wc_product_id = %d LIMIT 1", (int) $wc_product_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return ! empty( $row['dolibarr_product_id'] ) ? (int) $row['dolibarr_product_id'] : 0;
	}

	private static function extract_dolibarr_id( $data ) {
		if ( is_numeric( $data ) ) {
			return (int) $data;
		}

		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}

		if ( is_array( $data ) ) {
			return (int) ( $data['id'] ?? $data['rowid'] ?? $data[0]['id'] ?? $data['invoice']['id'] ?? $data['order']['id'] ?? 0 );
		}

		return 0;
	}

	private static function first_api_item( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}
		return isset( $data[0] ) && is_array( $data[0] ) ? $data[0] : $data;
	}

	private static function get_order_relation( $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_order_id = %d LIMIT 1", (int) $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : array();
	}

	private static function upsert_order_relation( WC_Order $order, array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wc_order_id = %d LIMIT 1", (int) $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$existing = $existing_id > 0 ? self::get_order_relation( (int) $order->get_id() ) : array();
		$payload = array_merge(
			array(
				'wc_order_id' => (int) $order->get_id(),
				'wc_order_number' => (string) $order->get_order_number(),
				'dolibarr_thirdparty_id' => null,
				'dolibarr_order_id' => null,
				'dolibarr_invoice_id' => null,
				'order_status' => (string) $order->get_status(),
				'invoice_status' => null,
				'sync_status' => 'pending',
				'last_error_message' => '',
				'synced_at' => current_time( 'mysql' ),
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			$existing,
			$data
		);
		unset( $payload['id'] );

		if ( $existing_id > 0 ) {
			$result = $wpdb->update( $table, $payload, array( 'id' => $existing_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $result ) {
				throw new RuntimeException( __( 'No se pudo guardar el estado del pedido en la base de datos local.', 'dolisync' ) );
			}
			return;
		}

		if ( false === $wpdb->insert( $table, $payload ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw new RuntimeException( __( 'No se pudo crear el mapeo del pedido en la base de datos local.', 'dolisync' ) );
		}
	}
}
