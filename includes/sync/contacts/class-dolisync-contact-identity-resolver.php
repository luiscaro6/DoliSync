<?php
/**
 * Resolución centralizada de identidad entre usuarios WooCommerce y terceros Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Contact_Identity_Resolver {
	/**
	 * Resuelve un usuario WooCommerce. El documento fiscal tiene prioridad y el
	 * correo solo puede confirmar la identidad o completar un documento ausente.
	 */
	public static function resolve_wp_user( $document_id, $email ) {
		global $wpdb;

		$document_id = self::normalize_document( $document_id );
		$email = sanitize_email( (string) $email );
		$document_user_id = 0;

		if ( '' !== $document_id ) {
			$relations = $wpdb->prefix . 'dolisync_contact_relations';
			$document_user_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT wp_user_id FROM {$relations} WHERE dni = %s ORDER BY synced_at DESC LIMIT 1", $document_id )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $document_user_id <= 0 ) {
				$document_user_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND UPPER(REPLACE(REPLACE(meta_value, ' ', ''), '-', '')) = %s ORDER BY user_id ASC LIMIT 1",
						'dolisync_document_id',
						$document_id
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		$email_user = '' !== $email ? get_user_by( 'email', $email ) : false;
		$email_user_id = $email_user ? (int) $email_user->ID : 0;

		if ( $document_user_id > 0 ) {
			if ( $email_user_id > 0 && $email_user_id !== $document_user_id ) {
				return self::conflict( __( 'El documento fiscal pertenece a un usuario WooCommerce, pero el correo pertenece a otro.', 'dolisync' ), array( 'document_match_id' => $document_user_id, 'email_match_id' => $email_user_id ) );
			}
			return self::match( $document_user_id, 'document' );
		}

		if ( $email_user_id > 0 ) {
			$stored_document = self::normalize_document( get_user_meta( $email_user_id, 'dolisync_document_id', true ) );
			if ( '' === $stored_document ) {
				$stored_document = self::normalize_document(
					$wpdb->get_var( $wpdb->prepare( "SELECT dni FROM {$wpdb->prefix}dolisync_contact_relations WHERE wp_user_id = %d LIMIT 1", $email_user_id ) )
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( '' !== $stored_document && '' !== $document_id && ! hash_equals( $stored_document, $document_id ) ) {
				return self::conflict( __( 'El correo ya existe en WooCommerce con un documento fiscal diferente.', 'dolisync' ), array( 'email_match_id' => $email_user_id ) );
			}
			return self::match( $email_user_id, 'email' );
		}

		return array( 'status' => 'not_found', 'id' => 0, 'matched_by' => '' );
	}

	/**
	 * Resuelve un tercero Dolibarr aplicando las mismas reglas de identidad.
	 */
	public static function resolve_dolibarr_thirdparty( Dolisync_API_Client $api_client, $document_id, $email ) {
		$document_id = self::normalize_document( $document_id );
		$email = sanitize_email( (string) $email );
		$document_match = '' !== $document_id ? self::find_dolibarr_by_document( $api_client, $document_id ) : 0;
		$email_match = '' !== $email ? self::find_dolibarr_by_email( $api_client, $email ) : 0;

		if ( $document_match > 0 ) {
			if ( $email_match > 0 && $email_match !== $document_match ) {
				return self::conflict( __( 'El documento fiscal y el correo pertenecen a terceros Dolibarr diferentes.', 'dolisync' ), array( 'document_match_id' => $document_match, 'email_match_id' => $email_match ) );
			}
			return self::match( $document_match, 'document' );
		}

		if ( $email_match > 0 ) {
			$thirdparty = self::get_dolibarr_thirdparty( $api_client, $email_match );
			$remote_document = self::normalize_document( $thirdparty['idprof1'] ?? $thirdparty['siren'] ?? '' );
			if ( '' !== $remote_document && '' !== $document_id && ! hash_equals( $remote_document, $document_id ) ) {
				return self::conflict( __( 'El correo ya existe en Dolibarr con un documento fiscal diferente.', 'dolisync' ), array( 'email_match_id' => $email_match ) );
			}
			return self::match( $email_match, 'email' );
		}

		return array( 'status' => 'not_found', 'id' => 0, 'matched_by' => '' );
	}

	public static function normalize_document( $value ) {
		return strtoupper( preg_replace( '/[\s-]+/u', '', trim( (string) $value ) ) );
	}

	private static function find_dolibarr_by_document( Dolisync_API_Client $api_client, $document_id ) {
		$escaped = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $document_id );
		$response = $api_client->get( '/thirdparties', array( 'sortfield' => 't.rowid', 'sortorder' => 'ASC', 'limit' => 2, 'sqlfilters' => "(t.siren:=:'{$escaped}')" ) );
		return ! empty( $response['success'] ) ? self::extract_first_id( $response['data'] ?? null ) : 0;
	}

	private static function find_dolibarr_by_email( Dolisync_API_Client $api_client, $email ) {
		$response = $api_client->get( '/thirdparties/email/' . rawurlencode( $email ) );
		return ! empty( $response['success'] ) ? self::extract_first_id( $response['data'] ?? null ) : 0;
	}

	private static function get_dolibarr_thirdparty( Dolisync_API_Client $api_client, $thirdparty_id ) {
		$response = $api_client->get( '/thirdparties/' . (int) $thirdparty_id );
		if ( empty( $response['success'] ) ) {
			return array();
		}
		$data = $response['data'] ?? array();
		return is_object( $data ) ? json_decode( wp_json_encode( $data ), true ) : (array) $data;
	}

	private static function extract_first_id( $data ) {
		if ( is_numeric( $data ) ) {
			return (int) $data;
		}
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		if ( ! is_array( $data ) ) {
			return 0;
		}
		if ( isset( $data['data'] ) ) {
			return self::extract_first_id( $data['data'] );
		}
		if ( ! empty( $data['id'] ) || ! empty( $data['rowid'] ) ) {
			return (int) ( $data['id'] ?? $data['rowid'] );
		}
		foreach ( $data as $item ) {
			$id = self::extract_first_id( $item );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}

	private static function match( $id, $matched_by ) {
		return array( 'status' => 'matched', 'id' => (int) $id, 'matched_by' => (string) $matched_by );
	}

	private static function conflict( $message, array $details = array() ) {
		return array_merge( array( 'status' => 'conflict', 'id' => 0, 'matched_by' => '', 'message' => (string) $message ), $details );
	}
}
