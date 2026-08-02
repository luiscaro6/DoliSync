<?php
/**
 * Encriptación de DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Encryption {
	public static function is_available() {
		return extension_loaded( 'openssl' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	public static function encrypt( $plaintext ) {
		if ( ! self::is_available() ) {
			return false;
		}

		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			throw new Exception( 'No se puede encriptar un valor vacío.' );
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-gcm' );
		$iv = openssl_random_pseudo_bytes( $iv_length );
		if ( false === $iv ) {
			throw new Exception( 'No se pudo generar el vector de inicialización.' );
		}

		$tag = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', self::get_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			throw new Exception( 'No se pudo encriptar el valor.' );
		}

		return 'v2:' . base64_encode( $iv . $tag . $ciphertext );
	}

	public static function decrypt( $encrypted_data ) {
		if ( ! extension_loaded( 'openssl' ) || empty( $encrypted_data ) ) {
			return false;
		}

		if ( 0 === strpos( (string) $encrypted_data, 'v2:' ) ) {
			$decoded = base64_decode( substr( (string) $encrypted_data, 3 ), true );
			$iv_length = openssl_cipher_iv_length( 'aes-256-gcm' );
			$tag_length = 16;
			if ( false === $decoded || strlen( $decoded ) <= $iv_length + $tag_length ) {
				return false;
			}
			$iv = substr( $decoded, 0, $iv_length );
			$tag = substr( $decoded, $iv_length, $tag_length );
			$ciphertext = substr( $decoded, $iv_length + $tag_length );
			$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', self::get_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plaintext ? false : $plaintext;
		}

		// Compatibilidad de lectura con credenciales cifradas por versiones anteriores.
		$decoded = base64_decode( $encrypted_data, true );
		if ( false === $decoded ) {
			return false;
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( strlen( $decoded ) <= $iv_length ) {
			return false;
		}

		$iv = substr( $decoded, 0, $iv_length );
		$ciphertext = substr( $decoded, $iv_length );
		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::get_encryption_key(), OPENSSL_RAW_DATA, $iv );

		return false === $plaintext ? false : $plaintext;
	}

	private static function get_encryption_key() {
		$material = wp_salt( 'auth' ) . ABSPATH . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' );
		return hash( 'sha256', $material, true );
	}
}
