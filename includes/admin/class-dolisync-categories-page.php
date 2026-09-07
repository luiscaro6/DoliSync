<?php
/**
 * Catálogo independiente de categorías WooCommerce ↔ Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Categories_Page {
	private const MAX_DOLIBARR_PAGES = 100;

	public static function init() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		Dolisync_Schema::ensure_product_category_mappings_table();
		add_action( 'wp_ajax_dolisync_categories_catalog', array( __CLASS__, 'ajax_catalog' ) );
		add_action( 'wp_ajax_dolisync_categories_simulation', array( __CLASS__, 'ajax_simulation' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'dolisync' ) );
		}
		$nonce = wp_create_nonce( DOLISYNC_NONCE_ACTION );
		?>
		<div class="wrap dolisync-container dolisync-categories-app">
			<div class="dolisync-products-hero"><div><span class="dolisync-products-eyebrow"><?php echo esc_html__( 'Catálogo conectado', 'dolisync' ); ?></span><h1><?php echo esc_html__( 'Categorías', 'dolisync' ); ?></h1><p><?php echo esc_html__( 'Compara jerarquías, revisa relaciones y simula cualquier cambio antes de aplicarlo.', 'dolisync' ); ?></p></div><button type="button" class="button dolisync-categories-reload"><span class="dashicons dashicons-update"></span> <?php echo esc_html__( 'Actualizar catálogo', 'dolisync' ); ?></button></div>
			<nav class="nav-tab-wrapper dolisync-customers-tabs" aria-label="<?php echo esc_attr__( 'Secciones de categorías', 'dolisync' ); ?>">
				<button type="button" class="nav-tab nav-tab-active" data-categories-tab="catalog"><?php echo esc_html__( 'Catálogo', 'dolisync' ); ?> <span id="dolisync-categories-count" class="dolisync-tab-count">0</span></button>
				<button type="button" class="nav-tab" data-categories-tab="relations"><?php echo esc_html__( 'Relaciones', 'dolisync' ); ?> <span id="dolisync-category-relations-count" class="dolisync-tab-count">0</span></button>
				<button type="button" class="nav-tab" data-categories-tab="simulation"><?php echo esc_html__( 'Simulación', 'dolisync' ); ?></button>
				<button type="button" class="nav-tab" data-categories-tab="migration"><?php echo esc_html__( 'Asistente de variantes', 'dolisync' ); ?></button>
			</nav>
			<div id="dolisync-categories-notice" aria-live="polite"></div>
			<section id="dolisync-categories-catalog-panel" class="dolisync-categories-panel"><div class="dolisync-products-toolbar"><label class="dolisync-products-search"><span class="dashicons dashicons-search"></span><input type="search" id="dolisync-categories-search" placeholder="<?php echo esc_attr__( 'Buscar por nombre, slug o ID…', 'dolisync' ); ?>"></label><label class="dolisync-products-filter"><span><?php echo esc_html__( 'Estado', 'dolisync' ); ?></span><select id="dolisync-categories-status"><option value="all"><?php echo esc_html__( 'Todas', 'dolisync' ); ?></option><option value="linked"><?php echo esc_html__( 'Relacionadas', 'dolisync' ); ?></option><option value="unlinked"><?php echo esc_html__( 'Sin relacionar', 'dolisync' ); ?></option></select></label><div id="dolisync-categories-summary" class="dolisync-products-summary"></div></div><div id="dolisync-categories-table" class="dolisync-products-table-wrap"></div></section>
			<section id="dolisync-categories-relations-panel" class="dolisync-categories-panel" hidden><div class="dolisync-conflicts-heading"><div><h2><?php echo esc_html__( 'Relaciones aprobadas', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Vínculos persistentes utilizados al asignar categorías a los productos.', 'dolisync' ); ?></p></div></div><div id="dolisync-category-relations-table" class="dolisync-products-table-wrap"></div></section>
			<section id="dolisync-categories-simulation-panel" class="dolisync-categories-panel" hidden><div class="dolisync-conflicts-heading"><div><h2><?php echo esc_html__( 'Simulación bidireccional', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Elige una dirección. Cada simulación y ejecución modifica exclusivamente el sistema destino.', 'dolisync' ); ?></p></div><div class="dolisync-page-actions-buttons"><button type="button" class="button button-primary dolisync-run-categories-simulation" data-direction="dolibarr_to_woocommerce"><?php echo esc_html__( 'Simular Doli → Woo', 'dolisync' ); ?></button><button type="button" class="button button-primary dolisync-run-categories-simulation" data-direction="woocommerce_to_dolibarr"><?php echo esc_html__( 'Simular Woo → Doli', 'dolisync' ); ?></button><button type="button" class="button" id="dolisync-apply-categories-simulation" data-nonce="<?php echo esc_attr( $nonce ); ?>" disabled><?php echo esc_html__( 'Aplicar simulación', 'dolisync' ); ?></button></div></div><div id="dolisync-categories-simulation-summary" class="dolisync-products-summary"></div><div id="dolisync-categories-simulation-table" class="dolisync-products-table-wrap"><div class="dolisync-products-zero"><span class="dashicons dashicons-search"></span><h2><?php echo esc_html__( 'Simulación pendiente', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Selecciona una dirección. No se modificará ningún dato.', 'dolisync' ); ?></p></div></div></section>
			<section id="dolisync-categories-migration-panel" class="dolisync-categories-panel" hidden><div class="dolisync-conflicts-heading"><div><h2><?php echo esc_html__( 'Categorías de variantes en TakePOS', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Añade a cada producto hijo las categorías asignadas a su producto padre en Dolibarr. Se validan las relaciones existentes y no se eliminan categorías adicionales asignadas manualmente al hijo.', 'dolisync' ); ?></p></div><div class="dolisync-page-actions-buttons"><button type="button" class="button button-primary" id="dolisync-migrate-variation-categories"><?php echo esc_html__( 'Reparar variantes existentes', 'dolisync' ); ?></button></div></div><div id="dolisync-variation-category-migration-result" class="dolisync-products-table-wrap"><div class="dolisync-products-zero"><span class="dashicons dashicons-category"></span><h2><?php echo esc_html__( 'Reparación disponible', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'El proceso trabaja por lotes y solo añade las categorías que falten a variantes previamente sincronizadas.', 'dolisync' ); ?></p></div></div></section>
		</div>
		<?php
	}

	public static function ajax_catalog() {
		self::guard_ajax();
		try {
			$rows = self::build_catalog();
			wp_send_json_success( array( 'rows' => $rows, 'summary' => array( 'total' => count( $rows ), 'linked' => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['linked'] ); } ) ), 'unlinked' => count( array_filter( $rows, static function ( $row ) { return empty( $row['linked'] ); } ) ) ) ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public static function ajax_simulation() {
		self::guard_ajax();
		try {
			$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
			if ( ! in_array( $direction, array( 'dolibarr_to_woocommerce', 'woocommerce_to_dolibarr' ), true ) ) { throw new InvalidArgumentException( __( 'Dirección no válida.', 'dolisync' ) ); }
			$items = array();
			foreach ( self::build_catalog() as $row ) {
				$woo = $row['woo']; $dolibarr = $row['dolibarr'];
				if ( ( 'dolibarr_to_woocommerce' === $direction && ! $dolibarr ) || ( 'woocommerce_to_dolibarr' === $direction && ! $woo ) ) { continue; }
				$slug = (string) ( $woo['slug'] ?? $dolibarr['slug'] ?? '' );
				if ( ! empty( $row['linked'] ) ) {
					$same_name = strtolower( trim( (string) $woo['name'] ) ) === strtolower( trim( (string) $dolibarr['name'] ) );
					$same_slug = '' === (string) $dolibarr['slug'] || (string) $woo['slug'] === (string) $dolibarr['slug'];
					if ( $same_name && $same_slug && empty( $row['hierarchy_changed'] ) ) { continue; }
					$action = 'update';
				} else {
					$action = $woo && $dolibarr ? 'link' : ( $dolibarr && '' === $slug ? 'review' : 'create' );
				}
				$source = 'dolibarr_to_woocommerce' === $direction ? $dolibarr : $woo;
				$items[] = array( 'action' => $action, 'direction' => $direction, 'name' => (string) ( $source['name'] ?? '' ), 'slug' => (string) ( $source['slug'] ?? '' ), 'parent_id' => (int) ( $source['parent_id'] ?? 0 ) );
			}
			$token = wp_generate_uuid4();
			set_transient( 'dolisync_category_simulation_' . get_current_user_id(), array( 'token' => hash( 'sha256', $token ), 'direction' => $direction ), 15 * MINUTE_IN_SECONDS );
			wp_send_json_success( array( 'token' => $token, 'direction' => $direction, 'items' => $items, 'summary' => array( 'total' => count( $items ), 'create' => count( array_filter( $items, static function ( $item ) { return 'create' === $item['action']; } ) ), 'update' => count( array_filter( $items, static function ( $item ) { return 'update' === $item['action']; } ) ), 'link' => count( array_filter( $items, static function ( $item ) { return 'link' === $item['action']; } ) ), 'review' => count( array_filter( $items, static function ( $item ) { return 'review' === $item['action']; } ) ) ) ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	private static function build_catalog() {
		global $wpdb;
		$woo = self::get_woo_categories(); $dolibarr = self::get_dolibarr_categories();
		$relations = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}dolisync_product_category_mappings ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = array(); $used_woo = array(); $used_dolibarr = array();
		foreach ( (array) $relations as $relation ) {
			$wc_id = (int) $relation['wc_category_id']; $dolibarr_id = (int) $relation['dolibarr_category_id'];
			if ( ! isset( $woo[ $wc_id ] ) && ! isset( $dolibarr[ $dolibarr_id ] ) ) { continue; }
			$rows[] = self::make_row( $woo[ $wc_id ] ?? null, $dolibarr[ $dolibarr_id ] ?? null, isset( $woo[ $wc_id ], $dolibarr[ $dolibarr_id ] ), $relation );
			$used_woo[ $wc_id ] = true; $used_dolibarr[ $dolibarr_id ] = true;
		}
		$dolibarr_by_slug = array();
		foreach ( $dolibarr as $id => $category ) { if ( ! isset( $used_dolibarr[ $id ] ) && '' !== $category['slug'] ) { $dolibarr_by_slug[ $category['slug'] ][] = $id; } }
		foreach ( $woo as $id => $category ) {
			if ( isset( $used_woo[ $id ] ) ) { continue; }
			$candidates = $dolibarr_by_slug[ $category['slug'] ] ?? array(); $dolibarr_id = 1 === count( $candidates ) ? (int) $candidates[0] : 0;
			$rows[] = self::make_row( $category, $dolibarr_id ? $dolibarr[ $dolibarr_id ] : null, false );
			if ( $dolibarr_id ) { $used_dolibarr[ $dolibarr_id ] = true; }
		}
		foreach ( $dolibarr as $id => $category ) { if ( ! isset( $used_dolibarr[ $id ] ) ) { $rows[] = self::make_row( null, $category, false ); } }
		usort( $rows, static function ( $left, $right ) { return strnatcasecmp( (string) $left['search'], (string) $right['search'] ); } );
		return $rows;
	}

	private static function make_row( $woo, $dolibarr, $linked, $relation = array() ) {
		$hierarchy_changed = ! empty( $linked ) && ( (int) ( $woo['parent_id'] ?? 0 ) !== (int) ( $relation['wc_parent_category_id'] ?? 0 ) || (int) ( $dolibarr['source_parent_id'] ?? $dolibarr['parent_id'] ?? 0 ) !== (int) ( $relation['dolibarr_parent_category_id'] ?? 0 ) );
		return array( 'key' => 'w' . (int) ( $woo['id'] ?? 0 ) . '-d' . (int) ( $dolibarr['id'] ?? 0 ), 'woo' => $woo, 'dolibarr' => $dolibarr, 'linked' => (bool) $linked, 'hierarchy_changed' => $hierarchy_changed, 'synced_at' => (string) ( $relation['synced_at'] ?? '' ), 'search' => strtolower( implode( ' ', array_filter( array( $woo['name'] ?? '', $woo['slug'] ?? '', $woo['id'] ?? '', $dolibarr['name'] ?? '', $dolibarr['slug'] ?? '', $dolibarr['id'] ?? '' ) ) ) ) );
	}

	private static function get_woo_categories() {
		$result = array(); $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) { return $result; }
		foreach ( $terms as $term ) { $result[ (int) $term->term_id ] = array( 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => sanitize_title( $term->slug ), 'parent_id' => (int) $term->parent ); }
		return $result;
	}

	private static function get_dolibarr_categories() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php'; $client = new Dolisync_API_Client(); $result = array();
		for ( $page = 0; $page < self::MAX_DOLIBARR_PAGES; $page++ ) {
			$response = $client->get( '/categories', array( 'type' => 'product', 'limit' => 100, 'page' => $page, 'sortfield' => 't.rowid', 'sortorder' => 'ASC' ) );
			if ( empty( $response['success'] ) ) { throw new RuntimeException( (string) ( $response['message'] ?? __( 'No se pudieron leer las categorías de Dolibarr.', 'dolisync' ) ) ); }
			$body = self::normalize_array( $response['data'] ?? array() ); $items = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body; if ( ! isset( $items[0] ) && ! empty( $items ) ) { $items = array( $items ); }
			foreach ( $items as $item ) { if ( ! is_array( $item ) ) { continue; } $id = (int) ( $item['id'] ?? $item['rowid'] ?? 0 ); if ( $id ) { $result[ $id ] = array( 'id' => $id, 'name' => (string) ( $item['label'] ?? $item['name'] ?? '' ), 'slug' => sanitize_title( $item['slug'] ?? '' ), 'parent_id' => (int) ( $item['fk_parent'] ?? $item['parent_id'] ?? 0 ) ); } }
			if ( count( $items ) < 100 ) { break; }
		}
		$root_id = 0;
		foreach ( $result as $id => $category ) {
			if ( 0 === (int) $category['parent_id'] && 'productos' === self::normalize_name( $category['name'] ) ) { $root_id = (int) $id; break; }
		}
		if ( $root_id > 0 ) {
			unset( $result[ $root_id ] );
			foreach ( $result as &$category ) {
				$category['source_parent_id'] = (int) $category['parent_id'];
				if ( $root_id === (int) $category['parent_id'] ) { $category['parent_id'] = 0; }
			}
			unset( $category );
		}
		return $result;
	}

	private static function normalize_array( $value ) { if ( is_object( $value ) ) { $value = json_decode( wp_json_encode( $value ), true ); } return is_array( $value ) ? $value : array(); }
	private static function normalize_name( $value ) { return strtolower( trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $value ) ) ) ); }
	private static function guard_ajax() { if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'dolisync' ) ), 403 ); } check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' ); }
}

Dolisync_Categories_Page::init();
