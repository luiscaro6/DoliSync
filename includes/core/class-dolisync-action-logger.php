<?php
/**
 * Logger de acciones internas de DoliSync (creación, actualización, borrado de contactos, pedidos, etc).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Action_Logger {
	private static $table_columns = null;
	/**
	 * Registra una acción interna.
	 *
	 * @param string $tipo       Tipo de entidad: 'contacto', 'pedido', 'producto', etc.
	 * @param string $accion     Acción realizada: 'creación', 'actualización', 'borrado'.
	 * @param string $estado     Estado: 'finalizado', 'error'.
	 * @param string $descripcion Descripción legible de lo que ocurrió (ej: "El nombre ha cambiado", "Se importó desde Dolibarr").
	 * @param int    $usuario_id ID de usuario que ejecutó la acción (opcional).
	 * @return int|false ID del log insertado o false si falló.
	 */
	public static function log_action( $tipo, $accion, $estado, $descripcion, $usuario_id = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_actions';

		$insert_data = array(
			'tipo'        => (string) $tipo,
			'accion'      => (string) $accion,
			'estado'      => (string) $estado,
			'descripcion' => wp_html_excerpt( wp_strip_all_tags( (string) $descripcion ), 12000, '…' ),
			'timestamp'   => current_time( 'mysql' ),
			'usuario_id'  => null !== $usuario_id ? (int) $usuario_id : ( get_current_user_id() ?: null ),
		);
		if ( null === self::$table_columns ) {
			self::$table_columns = (array) $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$columns = self::$table_columns;
		if ( in_array( 'correlation_id', $columns, true ) ) {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-operation-context.php';
			$insert_data['correlation_id'] = Dolisync_Operation_Context::ensure( 'action' );
		}

		$inserted = $wpdb->insert( $table, $insert_data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Obtiene acciones con filtros opcionales.
	 *
	 * @param int   $limit  Límite de resultados (default: 100).
	 * @param int   $offset Offset para paginación (default: 0).
	 * @param array $filters Filtros: tipo, accion, estado, keyword (búsqueda en descripción).
	 * @return array Array de acciones.
	 */
	public static function get_actions( $limit = 100, $offset = 0, $filters = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_actions';

		$query = "SELECT * FROM {$table} WHERE 1=1";

		if ( ! empty( $filters['tipo'] ) ) {
			$query .= $wpdb->prepare( ' AND tipo = %s', $filters['tipo'] );
		}

		if ( ! empty( $filters['accion'] ) ) {
			$query .= $wpdb->prepare( ' AND accion = %s', $filters['accion'] );
		}

		if ( ! empty( $filters['estado'] ) ) {
			$query .= $wpdb->prepare( ' AND estado = %s', $filters['estado'] );
		}

		if ( ! empty( $filters['keyword'] ) ) {
			$query .= $wpdb->prepare( ' AND descripcion LIKE %s', '%' . $wpdb->esc_like( $filters['keyword'] ) . '%' );
		}

		$query .= ' ORDER BY timestamp DESC LIMIT %d OFFSET %d';
		$query = $wpdb->prepare( $query, $limit, $offset ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Limpia acciones antiguas (retención basada en config).
	 *
	 * @param int $days Días de retención (default: 7).
	 */
	public static function cleanup_old_actions( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_actions';

		$query = $wpdb->prepare(
			"DELETE FROM {$table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
