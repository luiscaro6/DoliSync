<?php
/** Migraciones versionadas e idempotentes de DoliSync. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Migrations {
	public const DB_VERSION = '2.0.0';
	private const OPTION = 'dolisync_db_version';

	/** Ejecuta las migraciones pendientes una sola vez por versión. */
	public static function maybe_migrate() {
		$current = (string) get_option( self::OPTION, '0' );
		if ( version_compare( $current, self::DB_VERSION, '>=' ) ) {
			return true;
		}

		$lock = 'dolisync_schema_migration_lock';
		if ( get_transient( $lock ) ) {
			return false;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
			$status = Dolisync_Schema::repair_schema();
			if ( empty( $status['healthy'] ) ) {
				throw new RuntimeException( __( 'El esquema continúa incompleto después de la migración.', 'dolisync' ) );
			}
			update_option( self::OPTION, self::DB_VERSION, false );
			delete_option( 'dolisync_migration_error' );
			return true;
		} catch ( Throwable $error ) {
			update_option( 'dolisync_migration_error', array( 'message' => sanitize_text_field( $error->getMessage() ), 'timestamp' => current_time( 'mysql' ) ), false );
			return false;
		} finally {
			delete_transient( $lock );
		}
	}

	public static function get_status() {
		return array(
			'installed' => (string) get_option( self::OPTION, '0' ),
			'target'    => self::DB_VERSION,
			'pending'   => version_compare( (string) get_option( self::OPTION, '0' ), self::DB_VERSION, '<' ),
			'error'     => get_option( 'dolisync_migration_error', array() ),
		);
	}
}
