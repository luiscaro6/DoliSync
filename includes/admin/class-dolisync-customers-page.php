<?php
/**
 * Seguimiento de clientes WooCommerce enviados a Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Customers_Page {
	const PAGE_SIZE = 20;

	public static function init() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		Dolisync_Schema::ensure_ignored_items_table();
		Dolisync_Schema::ensure_contact_conflicts_table();
		add_action( 'wp_ajax_dolisync_customers_catalog', array( __CLASS__, 'ajax_catalog' ) );
		add_action( 'wp_ajax_dolisync_customer_action', array( __CLASS__, 'ajax_action' ) );
		add_action( 'wp_ajax_dolisync_contact_conflicts', array( __CLASS__, 'ajax_conflicts' ) );
		add_action( 'wp_ajax_dolisync_resolve_contact_conflict', array( __CLASS__, 'ajax_resolve_conflict' ) );
		add_action( 'wp_ajax_dolisync_customer_simulation', array( __CLASS__, 'ajax_simulation' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'dolisync' ) );
		}
		$nonce = wp_create_nonce( DOLISYNC_NONCE_ACTION );
		?>
		<div class="wrap dolisync-container dolisync-products-app dolisync-customers-app">
			<div class="dolisync-products-hero dolisync-customers-hero">
				<div>
					<span class="dolisync-products-eyebrow"><?php echo esc_html__( 'Directorio conectado', 'dolisync' ); ?></span>
					<h1><?php echo esc_html__( 'Clientes', 'dolisync' ); ?></h1>
					<p><?php echo esc_html__( 'Revisa todos los clientes de WooCommerce, sus datos esenciales y el estado de envío a Dolibarr.', 'dolisync' ); ?></p>
				</div>
				<button type="button" class="button dolisync-customers-reload"><span class="dashicons dashicons-update"></span> <?php echo esc_html__( 'Actualizar clientes', 'dolisync' ); ?></button>
			</div>
			<nav class="nav-tab-wrapper dolisync-customers-tabs" aria-label="<?php echo esc_attr__( 'Secciones de clientes', 'dolisync' ); ?>">
				<button type="button" class="nav-tab nav-tab-active" data-customers-tab="catalog"><?php echo esc_html__( 'Clientes', 'dolisync' ); ?></button>
				<button type="button" class="nav-tab" data-customers-tab="conflicts"><?php echo esc_html__( 'Conflictos', 'dolisync' ); ?> <span id="dolisync-conflicts-count" class="dolisync-tab-count">0</span></button>
				<button type="button" class="nav-tab" data-customers-tab="simulation-dolibarr" data-simulation-resource="contacts" data-simulation-direction="dolibarr_to_woocommerce"><?php echo esc_html__( 'Simulación Doli → Woo', 'dolisync' ); ?></button>
				<button type="button" class="nav-tab" data-customers-tab="simulation-woo" data-simulation-resource="contacts" data-simulation-direction="woocommerce_to_dolibarr"><?php echo esc_html__( 'Simulación Woo → Doli', 'dolisync' ); ?></button>
			</nav>
			<div id="dolisync-customers-catalog-panel" class="dolisync-customers-panel">
			<section class="dolisync-page-actions" aria-labelledby="dolisync-customers-sync-title">
				<div class="dolisync-page-actions-copy">
					<span class="dashicons dashicons-groups"></span>
					<div><h2 id="dolisync-customers-sync-title"><?php echo esc_html__( 'Sincronización de clientes', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Actualiza todos los contactos en el sentido que necesites.', 'dolisync' ); ?></p></div>
				</div>
				<div class="dolisync-page-actions-buttons">
					<button type="button" class="button button-primary" id="dolisync-sync-dolibarr-to-woo" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'Dolibarr → WooCommerce', 'dolisync' ); ?></button>
					<button type="button" class="button" id="dolisync-sync-woo-to-dolibarr" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html__( 'WooCommerce → Dolibarr', 'dolisync' ); ?></button>
				</div>
			</section>
			<div id="dolisync-sync-result" class="dolisync-page-action-result" aria-live="polite"></div>
			<div class="dolisync-products-toolbar">
				<label class="dolisync-products-search"><span class="dashicons dashicons-search"></span><input type="search" id="dolisync-customers-search" placeholder="<?php echo esc_attr__( 'Buscar por nombre, email, DNI o ID…', 'dolisync' ); ?>"></label>
				<label class="dolisync-products-filter"><span><?php echo esc_html__( 'Estado', 'dolisync' ); ?></span><select id="dolisync-customers-status-filter"><option value="all"><?php echo esc_html__( 'Todos', 'dolisync' ); ?></option><option value="synced"><?php echo esc_html__( 'Sincronizados', 'dolisync' ); ?></option><option value="pending"><?php echo esc_html__( 'Pendientes', 'dolisync' ); ?></option><option value="incomplete"><?php echo esc_html__( 'Datos incompletos', 'dolisync' ); ?></option><option value="ignored"><?php echo esc_html__( 'Omitidos', 'dolisync' ); ?></option></select></label>
				<div id="dolisync-customers-summary" class="dolisync-products-summary"></div>
			</div>
			<div id="dolisync-customers-notice" aria-live="polite"></div>
			<div id="dolisync-customers-table" class="dolisync-products-table-wrap"><div class="dolisync-products-loading"><span class="spinner is-active"></span><?php echo esc_html__( 'Leyendo clientes…', 'dolisync' ); ?></div></div>
			<div id="dolisync-customers-pagination" class="dolisync-products-pagination"></div>
			</div>
			<div id="dolisync-customers-conflicts-panel" class="dolisync-customers-panel" hidden>
				<div class="dolisync-conflicts-heading"><div><h2><?php echo esc_html__( 'Conflictos de identidad', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Compara ambos registros y elige cuál debe sobrescribir al otro antes de vincularlos.', 'dolisync' ); ?></p></div><button type="button" class="button dolisync-conflicts-reload"><span class="dashicons dashicons-update"></span><?php echo esc_html__( 'Actualizar', 'dolisync' ); ?></button></div>
				<div id="dolisync-conflicts-notice" aria-live="polite"></div>
				<div id="dolisync-conflicts-table" class="dolisync-products-table-wrap"><div class="dolisync-products-loading"><span class="spinner is-active"></span><?php echo esc_html__( 'Leyendo conflictos…', 'dolisync' ); ?></div></div>
			</div>
			<?php self::render_simulation_panel( 'simulation-dolibarr', __( 'Dolibarr → WooCommerce', 'dolisync' ), __( 'Revisa los clientes que se crearían o actualizarían en WooCommerce.', 'dolisync' ) ); ?>
			<?php self::render_simulation_panel( 'simulation-woo', __( 'WooCommerce → Dolibarr', 'dolisync' ), __( 'Revisa los terceros que se crearían o actualizarían en Dolibarr.', 'dolisync' ) ); ?>
		</div>
		<?php
	}

	private static function render_simulation_panel( $id, $title, $description ) {
		?>
		<div id="dolisync-customers-<?php echo esc_attr( $id ); ?>-panel" class="dolisync-customers-panel dolisync-simulation-panel" hidden>
			<div class="dolisync-conflicts-heading"><div><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $description ); ?></p></div><div class="dolisync-page-actions-buttons"><button type="button" class="button button-primary dolisync-run-simulation"><?php echo esc_html__( 'Simular', 'dolisync' ); ?></button><button type="button" class="button dolisync-apply-all-simulation" disabled><?php echo esc_html__( 'Enviar todos', 'dolisync' ); ?></button></div></div>
			<div class="dolisync-simulation-notice" aria-live="polite"></div><div class="dolisync-simulation-summary dolisync-products-summary"></div>
			<div class="dolisync-simulation-table dolisync-products-table-wrap"><div class="dolisync-products-zero"><span class="dashicons dashicons-search"></span><h2><?php echo esc_html__( 'Simulación pendiente', 'dolisync' ); ?></h2><p><?php echo esc_html__( 'Pulsa Simular para calcular los posibles cambios. No se modificará ningún dato.', 'dolisync' ); ?></p></div></div>
		</div><?php
	}

	public static function ajax_catalog() {
		self::guard();
		$rows = self::build_catalog();
		wp_send_json_success( array(
			'rows' => $rows,
			'page_size' => self::PAGE_SIZE,
			'summary' => array(
				'total' => count( $rows ),
				'synced' => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['dolibarr_id'] ) && 'ignored' !== $row['status']; } ) ),
				'pending' => count( array_filter( $rows, static function ( $row ) { return 'pending' === $row['status']; } ) ),
				'incomplete' => count( array_filter( $rows, static function ( $row ) { return 'incomplete' === $row['status']; } ) ),
				'ignored' => count( array_filter( $rows, static function ( $row ) { return 'ignored' === $row['status']; } ) ),
			),
		) );
	}

	public static function ajax_action() {
		self::guard();
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$dolibarr_id = isset( $_POST['dolibarr_id'] ) ? absint( wp_unslash( $_POST['dolibarr_id'] ) ) : 0;
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( ! $user_id && ! $dolibarr_id ) {
			wp_send_json_error( array( 'message' => __( 'Cliente no válido.', 'dolisync' ) ), 400 );
		}
		try {
			if ( in_array( $operation, array( 'ignore', 'restore' ), true ) ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
				if ( ! Dolisync_Ignored_Items::set( 'customer', $user_id, 0, 'ignore' === $operation ) ) {
					throw new RuntimeException( __( 'No se pudo cambiar el estado del cliente.', 'dolisync' ) );
				}
				wp_send_json_success( array( 'message' => 'ignore' === $operation ? __( 'Cliente omitido.', 'dolisync' ) : __( 'Cliente restaurado.', 'dolisync' ) ) );
			}
			if ( ! in_array( $operation, array( 'sync', 'dolibarr_to_woo' ), true ) ) {
				throw new InvalidArgumentException( __( 'Acción no válida.', 'dolisync' ) );
			}
			if ( 'dolibarr_to_woo' === $operation ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-sync.php';
				$result = ( new Dolisync_Contact_Sync() )->sync_contact( $dolibarr_id );
			} else {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-sync-reverse.php';
				$result = ( new Dolisync_Contact_Sync_Reverse() )->sync_customer( $user_id );
			}
			if ( empty( $result['success'] ) ) {
				wp_send_json_error( array( 'message' => $result['message'] ?? __( 'No se pudo sincronizar.', 'dolisync' ) ) );
			}
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public static function ajax_simulation() {
		self::guard();
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
		if ( ! in_array( $direction, array( 'dolibarr_to_woocommerce', 'woocommerce_to_dolibarr' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Dirección no válida.', 'dolisync' ) ), 400 );
		}
		try {
			$items = 'dolibarr_to_woocommerce' === $direction ? self::simulate_dolibarr_to_woo() : self::simulate_woo_to_dolibarr();
			wp_send_json_success( array( 'items' => $items, 'summary' => array( 'total' => count( $items ), 'create' => count( array_filter( $items, static function ( $item ) { return 'create' === $item['action']; } ) ), 'update' => count( array_filter( $items, static function ( $item ) { return 'update' === $item['action']; } ) ) ) ) );
		} catch ( Throwable $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 500 ); }
	}

	private static function simulate_woo_to_dolibarr() {
		global $wpdb;
		$relations = $wpdb->get_results( "SELECT wp_user_id, dolibarr_contact_id, dni, email, first_name, last_name FROM {$wpdb->prefix}dolisync_contact_relations", OBJECT_K ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$items = array();
		foreach ( self::build_catalog() as $row ) {
			if ( in_array( $row['status'], array( 'ignored', 'incomplete' ), true ) ) { continue; }
			$relation = $relations[ $row['id'] ] ?? null;
			$differences = array();
			if ( ! $relation ) { $differences[] = __( 'nuevo tercero', 'dolisync' ); }
			if ( $relation && 0 !== strcasecmp( trim( (string) $relation->email ), trim( (string) $row['email'] ) ) ) { $differences[] = __( 'email', 'dolisync' ); }
			if ( $relation && strtoupper( trim( (string) $relation->dni ) ) !== strtoupper( trim( (string) $row['dni'] ) ) ) { $differences[] = __( 'documento fiscal', 'dolisync' ); }
			if ( $relation && trim( (string) $relation->first_name . ' ' . (string) ( $relation->last_name ?? '' ) ) !== trim( (string) $row['name'] ) ) { $differences[] = __( 'nombre', 'dolisync' ); }
			if ( empty( $differences ) ) { continue; }
			$items[] = array( 'key' => 'w' . $row['id'], 'action' => $relation ? 'update' : 'create', 'label' => $row['name'], 'reference' => $row['email'], 'differences' => $differences, 'user_id' => (int) $row['id'], 'dolibarr_id' => (int) $row['dolibarr_id'] );
		}
		return $items;
	}

	private static function simulate_dolibarr_to_woo() {
		global $wpdb;
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		$response = ( new Dolisync_API_Client() )->get( '/thirdparties', array( 'sortfield' => 't.rowid', 'sortorder' => 'ASC', 'limit' => 1000 ) );
		if ( empty( $response['success'] ) ) { throw new RuntimeException( (string) ( $response['message'] ?? __( 'No se pudieron leer los terceros de Dolibarr.', 'dolisync' ) ) ); }
		$data = $response['data'] ?? array();
		if ( is_object( $data ) ) { $data = json_decode( wp_json_encode( $data ), true ); }
		if ( is_array( $data ) && ! isset( $data[0] ) ) { $data = array( $data ); }
		$relations = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}dolisync_contact_relations", OBJECT_K ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$by_dolibarr = array(); foreach ( (array) $relations as $relation ) { $by_dolibarr[ (int) $relation->dolibarr_contact_id ] = $relation; }
		$items = array();
		foreach ( (array) $data as $contact ) {
			if ( ! is_array( $contact ) ) { continue; }
			$id = (int) ( $contact['id'] ?? $contact['rowid'] ?? 0 ); if ( ! $id ) { continue; }
			$relation = $by_dolibarr[ $id ] ?? null; $user = $relation ? get_user_by( 'id', (int) $relation->wp_user_id ) : false;
			$name = trim( (string) ( $contact['firstname'] ?? '' ) . ' ' . (string) ( $contact['name'] ?? '' ) );
			$differences = array();
			if ( ! $relation || ! $user ) { $differences[] = __( 'nuevo cliente', 'dolisync' ); }
			if ( $user && 0 !== strcasecmp( trim( (string) $user->user_email ), trim( (string) ( $contact['email'] ?? '' ) ) ) ) { $differences[] = __( 'email', 'dolisync' ); }
			if ( $user && strtoupper( trim( (string) get_user_meta( $user->ID, 'dolisync_document_id', true ) ) ) !== strtoupper( trim( (string) ( $contact['idprof1'] ?? '' ) ) ) ) { $differences[] = __( 'documento fiscal', 'dolisync' ); }
			$local_name = $user ? trim( (string) get_user_meta( $user->ID, 'first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'last_name', true ) ) : '';
			if ( $user && 0 !== strcasecmp( $local_name, $name ) ) { $differences[] = __( 'nombre', 'dolisync' ); }
			$remote_country = strtoupper( trim( (string) ( $contact['country_code'] ?? $contact['country'] ?? $contact['country_id'] ?? '' ) ) );
			$local_country = $user ? strtoupper( trim( (string) get_user_meta( $user->ID, 'billing_country', true ) ) ) : '';
			if ( $user && '' !== $remote_country && $local_country !== $remote_country ) { $differences[] = __( 'país', 'dolisync' ); }
			if ( empty( $differences ) ) { continue; }
			$items[] = array( 'key' => 'd' . $id, 'action' => $user ? 'update' : 'create', 'label' => $name ?: __( 'Cliente sin nombre', 'dolisync' ), 'reference' => (string) ( $contact['email'] ?? '' ), 'differences' => $differences, 'user_id' => $user ? (int) $user->ID : 0, 'dolibarr_id' => $id );
		}
		return $items;
	}

	public static function ajax_conflicts() {
		self::guard();
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-conflicts.php';
		$rows = Dolisync_Contact_Conflicts::get_open();
		wp_send_json_success( array( 'rows' => $rows, 'count' => count( $rows ) ) );
	}

	public static function ajax_resolve_conflict() {
		self::guard();
		$conflict_id = isset( $_POST['conflict_id'] ) ? absint( wp_unslash( $_POST['conflict_id'] ) ) : 0;
		$winner = isset( $_POST['winner'] ) ? sanitize_key( wp_unslash( $_POST['winner'] ) ) : '';
		try {
			require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/contacts/class-dolisync-contact-conflicts.php';
			Dolisync_Contact_Conflicts::resolve( $conflict_id, $winner );
			wp_send_json_success( array( 'message' => 'dolibarr' === $winner ? __( 'Se conservaron los datos de Dolibarr y se vinculó el cliente.', 'dolisync' ) : __( 'Se conservaron los datos de WooCommerce y se vinculó el tercero.', 'dolisync' ) ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 409 );
		}
	}

	private static function build_catalog() {
		global $wpdb;
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/utils/class-dolisync-spanish-document-validator.php';
		$ignored = Dolisync_Ignored_Items::get_map( 'customer' );
		$relations = $wpdb->get_results( "SELECT wp_user_id, dolibarr_contact_id, synced_at, first_synced_at, source FROM {$wpdb->prefix}dolisync_contact_relations", OBJECT_K ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$order_links = self::get_order_thirdparty_links();
		$validator = new Dolisync_Spanish_Document_Validator();
		$users = get_users( array( 'role' => 'customer', 'orderby' => 'registered', 'order' => 'DESC', 'number' => -1 ) );
		$rows = array();
		foreach ( $users as $user ) {
			$id = (int) $user->ID;
			$first = trim( (string) get_user_meta( $id, 'first_name', true ) );
			$last = trim( (string) get_user_meta( $id, 'last_name', true ) );
			$name = trim( $first . ' ' . $last );
			$dni = trim( (string) get_user_meta( $id, 'dolisync_document_id', true ) );
			$email = trim( (string) $user->user_email );
			$phone = trim( (string) get_user_meta( $id, 'billing_phone', true ) );
			$country = trim( (string) get_user_meta( $id, 'billing_country', true ) );
			$address = trim( implode( ' ', array_filter( array( get_user_meta( $id, 'billing_address_1', true ), get_user_meta( $id, 'billing_postcode', true ), get_user_meta( $id, 'billing_city', true ) ) ) ) );
			$missing = array();
			if ( '' === $dni ) {
				$missing[] = __( 'DNI/NIE', 'dolisync' );
			} else {
				$validation = $validator->validate( $dni );
				if ( empty( $validation['valid'] ) ) { $missing[] = __( 'DNI/NIE válido', 'dolisync' ); }
			}
			if ( '' === $email || ! is_email( $email ) ) { $missing[] = __( 'email válido', 'dolisync' ); }
			if ( '' === $name ) { $missing[] = __( 'nombre y apellidos', 'dolisync' ); }
			$recommended = array();
			if ( '' === $address ) { $recommended[] = __( 'dirección', 'dolisync' ); }
			if ( '' === $phone ) { $recommended[] = __( 'teléfono', 'dolisync' ); }
			if ( '' === $country ) { $recommended[] = __( 'país', 'dolisync' ); }
			$relation = $relations[ $id ] ?? ( $order_links[ $id ] ?? null );
			$is_ignored = isset( $ignored[ Dolisync_Ignored_Items::key( $id, 0 ) ] );
			$status = $is_ignored ? 'ignored' : ( ! empty( $missing ) ? 'incomplete' : ( $relation ? 'synced' : 'pending' ) );
			$rows[] = array(
				'id' => $id, 'name' => $name ?: (string) $user->display_name, 'email' => $email, 'dni' => $dni,
				'phone' => $phone, 'country' => $country, 'address' => $address,
				'registered' => mysql2date( get_option( 'date_format' ), $user->user_registered ),
				'edit_url' => get_edit_user_link( $id ), 'orders' => function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $id ) : 0,
				'dolibarr_id' => $relation ? (int) $relation->dolibarr_contact_id : 0,
				'synced_at' => $relation ? (string) $relation->synced_at : '',
				'relation_source' => $relation ? (string) ( $relation->source ?? '' ) : '', 'status' => $status,
				'missing' => $missing, 'recommended' => $recommended, 'ignored_at' => $is_ignored ? $ignored[ Dolisync_Ignored_Items::key( $id, 0 ) ] : '',
				'search' => self::search_key( implode( ' ', array( $id, $name, $user->display_name, $email, $dni, $phone, $country ) ) ),
			);
		}
		return $rows;
	}

	/**
	 * Recupera vinculaciones históricas creadas al facturar pedidos. Algunas
	 * versiones anteriores guardaban el tercero solo en la relación del pedido.
	 */
	private static function get_order_thirdparty_links() {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_order_relations';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array();
		}
		$rows = $wpdb->get_results( "SELECT wc_order_id, dolibarr_thirdparty_id, synced_at, updated_at FROM {$table} WHERE dolibarr_thirdparty_id > 0 ORDER BY COALESCE(synced_at, updated_at) DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$links = array();
		foreach ( (array) $rows as $row ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['wc_order_id'] ) : false;
			$user_id = $order ? (int) $order->get_user_id() : 0;
			if ( $user_id <= 0 || isset( $links[ $user_id ] ) ) {
				continue;
			}
			$links[ $user_id ] = (object) array(
				'dolibarr_contact_id' => (int) $row['dolibarr_thirdparty_id'],
				'synced_at'           => (string) ( $row['synced_at'] ?: $row['updated_at'] ),
				'source'              => 'woocommerce_order_history',
			);
		}
		return $links;
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'dolisync' ) ), 403 ); }
		check_ajax_referer( DOLISYNC_NONCE_ACTION, 'nonce' );
	}

	private static function search_key( $value ) {
		$value = remove_accents( strtolower( (string) $value ) );
		return preg_replace( '/\s+/', ' ', trim( $value ) );
	}
}

Dolisync_Customers_Page::init();
