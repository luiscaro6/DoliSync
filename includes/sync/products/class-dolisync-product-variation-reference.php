<?php
/**
 * Regla compartida para las referencias de variaciones entre ambos sistemas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Product_Variation_Reference {
	public static function build( $parent_reference, $attributes, $variation_id = 0 ) {
		$parts = array();
		foreach ( (array) $attributes as $attribute_value ) {
			$part = self::normalize_part( $attribute_value );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}

		if ( empty( $parts ) ) {
			$parts = array( 'VAR', (string) (int) $variation_id );
		}

		return trim( (string) $parent_reference ) . '_' . implode( '_', $parts );
	}

	public static function is_generated( $reference, $parent_reference, $attributes, $variation_id = 0 ) {
		$reference = trim( (string) $reference );
		if ( '' === $reference ) {
			return false;
		}
		if ( self::is_legacy( $reference ) ) {
			return true;
		}
		return $reference === self::build( $parent_reference, $attributes, $variation_id );
	}

	public static function is_legacy( $reference ) {
		return 1 === preg_match( '/(?:^WC[-_]VAR|[-_]VAR)[-_]\d+$/i', trim( (string) $reference ) );
	}

	private static function normalize_part( $value ) {
		$value = trim( (string) $value );
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}
		$value = strtoupper( $value );
		return trim( (string) preg_replace( '/[^A-Z0-9]+/', '_', $value ), '_' );
	}
}
