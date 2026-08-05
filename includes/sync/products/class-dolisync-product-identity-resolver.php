<?php
/** Resolución centralizada y conservadora de identidad de productos. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Product_Identity_Resolver {
	public static function resolve_woocommerce_product( array $dolibarr_product, $existing_relation = null ) {
		$dolibarr_id = absint( $dolibarr_product['id'] ?? $dolibarr_product['rowid'] ?? 0 );
		if ( $existing_relation ) {
			$wc_id = absint( $existing_relation['wc_product_id'] ?? 0 );
			$product = $wc_id ? wc_get_product( $wc_id ) : false;
			if ( $product && ! $product->is_type( 'variation' ) ) {
				return self::match( $wc_id, 'relation', $product );
			}
			return self::conflict( 'broken_relation', __( 'La relación apunta a un producto WooCommerce inexistente o a una variación.', 'dolisync' ), $wc_id, $dolibarr_id );
		}

		$sku = self::normalize_sku( $dolibarr_product['ref'] ?? $dolibarr_product['sku'] ?? '' );
		$name = self::normalize_name( $dolibarr_product['label'] ?? $dolibarr_product['name'] ?? '' );
		$sku_candidates = self::find_unmapped_woocommerce( $sku, '', 'sku' );
		$name_candidates = self::find_unmapped_woocommerce( '', $name, 'name' );
		return self::choose_candidate( $sku_candidates, $name_candidates, 'woocommerce', $dolibarr_id );
	}

	public static function resolve_dolibarr_product( Dolisync_API_Client $api, $wc_product, $existing_relation = null ) {
		$wc_id = is_object( $wc_product ) ? absint( $wc_product->get_id() ) : 0;
		if ( $existing_relation ) {
			$dolibarr_id = absint( $existing_relation['dolibarr_product_id'] ?? 0 );
			$response = $dolibarr_id ? $api->get( '/products/' . $dolibarr_id ) : array();
			if ( ! empty( $response['success'] ) ) {
				return self::match( $dolibarr_id, 'relation', self::array_value( $response['data'] ?? array() ) );
			}
			$http_code = (int) ( $response['http_code'] ?? 0 );
			if ( 404 !== $http_code && 410 !== $http_code ) {
				return array( 'status' => 'error', 'message' => (string) ( $response['message'] ?? __( 'No se pudo validar la relación existente en Dolibarr.', 'dolisync' ) ) );
			}
			return self::conflict( 'broken_relation', __( 'La relación apunta a un producto Dolibarr inexistente o inaccesible.', 'dolisync' ), $wc_id, $dolibarr_id );
		}

		$sku = self::normalize_sku( is_object( $wc_product ) ? $wc_product->get_sku() : '' );
		$name = trim( sanitize_text_field( is_object( $wc_product ) ? $wc_product->get_name() : '' ) );
		$sku_candidates = self::find_unmapped_dolibarr( $api, $sku, '', 'sku' );
		$name_candidates = self::find_unmapped_dolibarr( $api, '', $name, 'name' );
		return self::choose_candidate( $sku_candidates, $name_candidates, 'dolibarr', $wc_id );
	}

	private static function choose_candidate( array $sku, array $name, $target, $source_id ) {
		if ( count( $sku ) > 1 ) {
			return self::conflict( 'duplicate_sku', __( 'El SKU coincide con varios productos libres; no se puede vincular automáticamente.', 'dolisync' ), 'woocommerce' === $target ? 0 : $source_id, 'dolibarr' === $target ? 0 : $source_id );
		}
		if ( 1 === count( $sku ) ) {
			$id = (int) array_key_first( $sku );
			if ( 1 === count( $name ) && (int) array_key_first( $name ) !== $id ) {
				return self::conflict( 'identity_mismatch', __( 'El SKU y el nombre identifican productos diferentes.', 'dolisync' ), 'woocommerce' === $target ? $id : $source_id, 'dolibarr' === $target ? $id : $source_id );
			}
			return self::match( $id, 'sku', $sku[ $id ] );
		}
		if ( count( $name ) > 1 ) {
			return self::conflict( 'duplicate_name', __( 'El nombre coincide con varios productos libres; revisa el conflicto manualmente.', 'dolisync' ), 'woocommerce' === $target ? 0 : $source_id, 'dolibarr' === $target ? 0 : $source_id );
		}
		if ( 1 === count( $name ) ) {
			$id = (int) array_key_first( $name );
			return self::match( $id, 'name', $name[ $id ] );
		}
		return array( 'status' => 'not_found', 'id' => 0, 'matched_by' => '', 'data' => array() );
	}

	private static function find_unmapped_woocommerce( $sku, $name, $mode ) {
		global $wpdb;
		$ids = array();
		if ( 'sku' === $mode && '' !== $sku ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND UPPER(TRIM(meta_value))=%s", $sku ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( 'name' === $mode && '' !== $name ) {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$result = array();
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variation' ) || ( 'name' === $mode && self::normalize_name( $product->get_name() ) !== $name ) ) {
				continue;
			}
			$mapped = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dolisync_product_relations WHERE wc_product_id=%d LIMIT 1", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $mapped ) {
				$result[ $id ] = $product;
			}
		}
		return $result;
	}

	private static function find_unmapped_dolibarr( Dolisync_API_Client $api, $sku, $name, $mode ) {
		global $wpdb;
		$value = 'sku' === $mode ? $sku : $name;
		if ( '' === $value ) {
			return array();
		}
		$escaped = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
		$field = 'sku' === $mode ? 't.ref' : 't.label';
		$response = $api->get( '/products', array( 'sortfield' => 't.rowid', 'sortorder' => 'ASC', 'limit' => 100, 'mode' => 1, 'sqlfilters' => "({$field}:=:'{$escaped}')" ) );
		$data = ! empty( $response['success'] ) ? self::array_value( $response['data'] ?? array() ) : array();
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) { $data = $data['data']; }
		$result = array();
		foreach ( $data as $item ) {
			$item = self::array_value( $item );
			$id = absint( $item['id'] ?? $item['rowid'] ?? 0 );
			$mapped = $id ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dolisync_product_relations WHERE dolibarr_product_id=%d LIMIT 1", $id ) ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $id && ! $mapped ) { $result[ $id ] = $item; }
		}
		return $result;
	}

	public static function normalize_sku( $value ) { return strtoupper( trim( (string) $value ) ); }
	public static function normalize_name( $value ) { return strtolower( trim( preg_replace( '/\s+/u', ' ', remove_accents( sanitize_text_field( (string) $value ) ) ) ) ); }
	private static function array_value( $value ) { return is_object( $value ) ? json_decode( wp_json_encode( $value ), true ) : (array) $value; }
	private static function match( $id, $by, $data ) { return array( 'status' => 'matched', 'id' => (int) $id, 'matched_by' => $by, 'data' => $data ); }
	private static function conflict( $type, $message, $wc_id, $dolibarr_id ) { return array( 'status' => 'conflict', 'id' => 0, 'matched_by' => '', 'conflict_type' => $type, 'message' => $message, 'wc_product_id' => absint( $wc_id ), 'dolibarr_product_id' => absint( $dolibarr_id ) ); }
}
