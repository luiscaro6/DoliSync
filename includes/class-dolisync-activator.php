<?php
/**
 * Activador del plugin DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Activator {
	public static function activate() {
		if ( ! self::check_dependencies() ) {
			deactivate_plugins( DOLISYNC_PLUGIN_BASENAME );
			wp_die( esc_html__( 'DoliSync requiere PHP 8.1+, WordPress 6.0+ y WooCommerce 6.0+.', 'dolisync' ) );
		}

		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		try {
			$status = Dolisync_Schema::repair_schema();
			if ( empty( $status['healthy'] ) ) {
				throw new RuntimeException( sprintf( __( 'El esquema sigue incompleto después de la migración (%d problemas).', 'dolisync' ), (int) ( $status['issues'] ?? 0 ) ) );
			}
		} catch ( Throwable $error ) {
			deactivate_plugins( DOLISYNC_PLUGIN_BASENAME );
			wp_die(
				esc_html( sprintf( __( 'DoliSync no se ha activado porque la base de datos no pudo prepararse: %s', 'dolisync' ), $error->getMessage() ) ),
				esc_html__( 'Error activando DoliSync', 'dolisync' ),
				array( 'back_link' => true )
			);
		}

		// Solo se publica el estado de activación tras verificar el esquema completo.
		$previous_version = get_option( 'dolisync_version', null );
		$previous_activated = get_option( 'dolisync_activated', null );
		$activated_at = current_time( 'mysql' );
		update_option( 'dolisync_version', DOLISYNC_VERSION );
		update_option( 'dolisync_activated', $activated_at );
		if ( DOLISYNC_VERSION !== get_option( 'dolisync_version' ) || $activated_at !== get_option( 'dolisync_activated' ) ) {
			if ( null === $previous_version ) {
				delete_option( 'dolisync_version' );
			} else {
				update_option( 'dolisync_version', $previous_version );
			}
			if ( null === $previous_activated ) {
				delete_option( 'dolisync_activated' );
			} else {
				update_option( 'dolisync_activated', $previous_activated );
			}
			deactivate_plugins( DOLISYNC_PLUGIN_BASENAME );
			wp_die( esc_html__( 'El esquema se preparó, pero WordPress no pudo guardar el estado de activación. DoliSync permanece desactivado.', 'dolisync' ) );
		}
	}

	public static function check_dependencies() {
		global $wp_version;

		return version_compare( PHP_VERSION, '8.1', '>=' ) && version_compare( (string) $wp_version, '6.0', '>=' ) && class_exists( 'WooCommerce' );
	}
}

