<?php
/** Contexto de correlación de una operación DoliSync. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Operation_Context {
	private static $id = '';

	public static function start( $prefix = 'run', $provided = '' ) {
		$provided = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $provided );
		self::$id = '' !== $provided ? substr( $provided, 0, 64 ) : sanitize_key( $prefix ) . '-' . wp_generate_uuid4();
		return self::$id;
	}

	public static function get() {
		return self::$id;
	}

	public static function ensure( $prefix = 'run' ) {
		return '' !== self::$id ? self::$id : self::start( $prefix );
	}
}
