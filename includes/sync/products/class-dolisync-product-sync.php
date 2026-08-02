<?php
/**
 * Sincronización de productos desde Dolibarr a WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Product_Sync {
	private const DEFAULT_PAGE_SIZE = 25;
	private const MAX_PAGE_SIZE = 100;
	private const PAYLOAD_VERSION = 2;

	private $api_client = null;
	private $db_manager = null;
	private $stats = array(
		'created'  => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'errors'   => 0,
		'details'  => array(),
		'errors_list' => array(),
	);
	private $attribute_cache = array();
	private $attribute_value_cache = array();
	private $image_sync = null;

	public function __construct() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-action-logger.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/api/class-dolisync-api-client.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-db-manager.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-schema.php';
		require_once DOLISYNC_PLUGIN_DIR . 'includes/sync/products/class-dolisync-product-image-sync.php';

		$this->api_client = new Dolisync_API_Client();
		$this->db_manager = new Dolisync_DB_Manager();
		$this->image_sync = new Dolisync_Product_Image_Sync( $this->api_client );
		Dolisync_Schema::ensure_product_variation_relation_columns();
	}

	public function sync( $page = 0, $per_page = self::DEFAULT_PAGE_SIZE ) {
		$this->reset_stats();
		$page = max( 0, (int) $page );
		$per_page = max( 1, min( self::MAX_PAGE_SIZE, (int) $per_page ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			$message = __( 'WooCommerce no está activo.', 'dolisync' );
			Dolisync_Action_Logger::log_action( 'producto', 'sincronización', 'error', $message, get_current_user_id() );
			return array(
				'success' => false,
				'message' => $message,
				'stats'   => $this->stats,
			);
		}

		if ( 0 === $page ) {
			$category_mapping_stats = $this->sync_category_mappings_bidirectional();
			$this->stats['details'][] = array( 'category_sync' => $category_mapping_stats );
		}

		$product_page = $this->fetch_dolibarr_products_page( $page, $per_page );
		$products = $product_page['data'];
		if ( empty( $products ) ) {
			$message = __( 'No hay productos de Dolibarr para sincronizar.', 'dolisync' );
			Dolisync_Action_Logger::log_action( 'producto', 'sincronización', 'finalizado', $message, get_current_user_id() );
			return array(
				'success' => true,
				'message' => $message,
				'stats'   => $this->stats,
				'pagination' => $product_page['pagination'],
			);
		}

		foreach ( $products as $product ) {
			$this->process_product( $product );
		}

		$summary = sprintf(
			__( 'Resumen de sincronización de productos: %d creados, %d mapeados por SKU o nombre, %d actualizados, %d omitidos, %d errores', 'dolisync' ),
			$this->stats['created'],
			$this->stats['mapped'],
			$this->stats['updated'],
			$this->stats['skipped'],
			$this->stats['errors']
		);
		Dolisync_Action_Logger::log_action( 'producto', 'sincronización', ( $this->stats['errors'] > 0 ? 'error' : 'finalizado' ), $summary, get_current_user_id() );

		return array(
			'success' => true,
			'message' => $summary,
			'stats'   => $this->stats,
			'pagination' => $product_page['pagination'],
		);
	}

	public function sync_all( $per_page = self::DEFAULT_PAGE_SIZE ) {
		$page = 0;
		$aggregate = null;
		do {
			$result = $this->sync( $page, $per_page );
			if ( null === $aggregate ) {
				$aggregate = $result;
			} else {
				foreach ( array( 'created', 'mapped', 'updated', 'skipped', 'errors' ) as $key ) {
					$aggregate['stats'][ $key ] = (int) ( $aggregate['stats'][ $key ] ?? 0 ) + (int) ( $result['stats'][ $key ] ?? 0 );
				}
				$aggregate['stats']['details'] = array_merge( $aggregate['stats']['details'], $result['stats']['details'] );
				$aggregate['stats']['errors_list'] = array_merge( $aggregate['stats']['errors_list'], $result['stats']['errors_list'] );
			}
			$page++;
		} while ( ! empty( $result['pagination']['has_more'] ) );

		return $aggregate;
	}

	/**
	 * Sincroniza un único producto de Dolibarr, incluyendo sus variantes.
	 *
	 * @param int $dolibarr_product_id ID del producto en Dolibarr.
	 * @return array
	 */
	public function sync_product( $dolibarr_product_id ) {
		$this->reset_stats();
		$dolibarr_product_id = absint( $dolibarr_product_id );
		if ( ! $dolibarr_product_id ) {
			return array( 'success' => false, 'message' => __( 'ID de producto de Dolibarr no válido.', 'dolisync' ), 'stats' => $this->stats );
		}

		$response = $this->api_client->get( '/products/' . $dolibarr_product_id, array( 'includestockdata' => 1, 'includeparentid' => 1 ) );
		if ( empty( $response['success'] ) ) {
			return array( 'success' => false, 'message' => (string) ( $response['message'] ?? __( 'No se pudo obtener el producto de Dolibarr.', 'dolisync' ) ), 'stats' => $this->stats );
		}

		$product = $this->normalize_api_array( $response['data'] ?? array() );
		$product['categories'] = $this->fetch_dolibarr_categories_for_product( $dolibarr_product_id );
		$product['variants'] = $this->fetch_dolibarr_variants( $dolibarr_product_id );
		$this->process_product( $product );

		return array(
			'success' => 0 === (int) $this->stats['errors'],
			'message' => 0 === (int) $this->stats['errors'] ? __( 'Producto sincronizado de Dolibarr a WooCommerce.', 'dolisync' ) : __( 'No se pudo sincronizar el producto.', 'dolisync' ),
			'stats'   => $this->stats,
		);
	}

	public function sync_categories() {
		$this->reset_stats();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$message = __( 'WooCommerce no está activo.', 'dolisync' );
			Dolisync_Action_Logger::log_action( 'producto', 'sincronización_categorias', 'error', $message, get_current_user_id() );
			return array(
				'success' => false,
				'message' => $message,
				'stats'   => $this->stats,
			);
		}

		$mapping_stats = $this->sync_category_mappings_bidirectional();
		$this->stats['created'] = (int) ( $mapping_stats['created'] ?? 0 );
		$this->stats['updated'] = (int) ( $mapping_stats['updated'] ?? 0 );
		$this->stats['skipped'] = (int) ( $mapping_stats['skipped'] ?? 0 );
		$this->stats['errors'] = (int) ( $mapping_stats['errors'] ?? 0 );
		$this->stats['details'][] = $mapping_stats;

		$summary = sprintf(
			__( 'Resumen de sincronización de categorías: %d creadas, %d mapeadas, %d omitidas, %d errores', 'dolisync' ),
			$this->stats['created'],
			$this->stats['updated'],
			$this->stats['skipped'],
			$this->stats['errors']
		);
		Dolisync_Action_Logger::log_action( 'producto', 'sincronización_categorias', ( $this->stats['errors'] > 0 ? 'error' : 'finalizado' ), $summary, get_current_user_id() );

		return array(
			'success' => true,
			'message' => $summary,
			'stats'   => $this->stats,
		);
	}

	private function sync_category_mappings_bidirectional() {
		global $wpdb;

		$table = $wpdb->prefix . 'dolisync_product_category_mappings';
		$stats = array(
			'created' => 0,
			'mapped' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors' => 0,
		);

		$dolibarr_categories = $this->sort_categories_by_parent_depth( $this->fetch_dolibarr_product_categories() );
		$woocommerce_categories = $this->sort_categories_by_parent_depth( $this->fetch_woocommerce_product_categories() );
		$existing_rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$dolibarr_to_wc_map = array();
		$wc_to_dolibarr_map = array();
		foreach ( (array) $existing_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$dolibarr_id = (int) ( $row['dolibarr_category_id'] ?? 0 );
			$wc_id = (int) ( $row['wc_category_id'] ?? 0 );
			if ( $dolibarr_id > 0 && $wc_id > 0 ) {
				$dolibarr_to_wc_map[ $dolibarr_id ] = $wc_id;
				$wc_to_dolibarr_map[ $wc_id ] = $dolibarr_id;
			}
		}

		$dolibarr_root_id = $this->ensure_dolibarr_products_root_category();
		if ( $dolibarr_root_id <= 0 ) {
			$stats['errors']++;
			return $stats;
		}

		$dolibarr_by_name = array();
		foreach ( $dolibarr_categories as $category ) {
			$dolibarr_by_name[ $this->normalize_category_name( $category['name'] ?? '' ) ] = $category;
		}

		$used_woocommerce_ids = array();
		foreach ( $woocommerce_categories as $category ) {
			$wc_id = (int) ( $category['id'] ?? 0 );
			if ( $wc_id > 0 && isset( $wc_to_dolibarr_map[ $wc_id ] ) ) {
				$used_woocommerce_ids[ $wc_id ] = true;
			}
		}

		foreach ( $woocommerce_categories as $wc_category ) {
			$wc_category_id = (int) ( $wc_category['id'] ?? 0 );
			$wc_name = sanitize_text_field( (string) ( $wc_category['name'] ?? '' ) );
			$wc_parent_id = (int) ( $wc_category['parent_id'] ?? 0 );
			if ( $wc_category_id <= 0 || '' === $wc_name ) {
				$stats['skipped']++;
				continue;
			}

			$dolibarr_category_id = (int) ( $wc_to_dolibarr_map[ $wc_category_id ] ?? 0 );
			$mapped_parent_id = ( $wc_parent_id > 0 && isset( $wc_to_dolibarr_map[ $wc_parent_id ] ) ) ? (int) $wc_to_dolibarr_map[ $wc_parent_id ] : $dolibarr_root_id;
			if ( $dolibarr_category_id <= 0 ) {
				$dolibarr_category = $dolibarr_by_name[ $this->normalize_category_name( $wc_name ) ] ?? null;
				if ( empty( $dolibarr_category ) ) {
					$dolibarr_category_id = $this->create_dolibarr_product_category( $wc_name, $mapped_parent_id );
					if ( $dolibarr_category_id > 0 ) {
						$stats['created']++;
						$dolibarr_to_wc_map[ $dolibarr_category_id ] = $wc_category_id;
						$wc_to_dolibarr_map[ $wc_category_id ] = $dolibarr_category_id;
						$used_woocommerce_ids[ $wc_category_id ] = true;
						$dolibarr_by_name[ $this->normalize_category_name( $wc_name ) ] = array(
							'id' => $dolibarr_category_id,
							'name' => $wc_name,
							'parent_id' => $mapped_parent_id,
						);
						$this->sync_woocommerce_category_term( $wc_category_id, $wc_name, $mapped_parent_id );
					} else {
						$stats['errors']++;
						continue;
					}
				} else {
					$dolibarr_category_id = (int) ( $dolibarr_category['id'] ?? 0 );
					$dolibarr_name = sanitize_text_field( (string) ( $dolibarr_category['name'] ?? $wc_name ) );
					$dolibarr_to_wc_map[ $dolibarr_category_id ] = $wc_category_id;
					$wc_to_dolibarr_map[ $wc_category_id ] = $dolibarr_category_id;
					$used_woocommerce_ids[ $wc_category_id ] = true;
					$this->sync_woocommerce_category_term( $wc_category_id, $dolibarr_name, $mapped_parent_id );
				}
			}

			$mapping_state = $this->upsert_category_mapping_row( $dolibarr_category_id, $mapped_parent_id, $wc_category_id, $wc_parent_id, $wc_name );
			if ( 'inserted' === $mapping_state ) {
				$stats['mapped']++;
			} elseif ( 'updated' === $mapping_state ) {
				$stats['updated']++;
			} elseif ( 'unchanged' === $mapping_state ) {
				$stats['mapped']++;
			} else {
				$stats['errors']++;
				continue;
			}
		}

		foreach ( $dolibarr_categories as $dolibarr_category ) {
			$dolibarr_category_id = (int) ( $dolibarr_category['id'] ?? 0 );
			$dolibarr_name = sanitize_text_field( (string) ( $dolibarr_category['name'] ?? '' ) );
			$dolibarr_parent_id = (int) ( $dolibarr_category['parent_id'] ?? 0 );
			if ( $dolibarr_category_id <= 0 || '' === $dolibarr_name ) {
				$stats['skipped']++;
				continue;
			}

			$wc_category_id = (int) ( $dolibarr_to_wc_map[ $dolibarr_category_id ] ?? 0 );
			$mapped_parent_term_id = ( $dolibarr_parent_id > 0 && isset( $dolibarr_to_wc_map[ $dolibarr_parent_id ] ) ) ? (int) $dolibarr_to_wc_map[ $dolibarr_parent_id ] : 0;
			if ( $wc_category_id <= 0 ) {
				$wc_category = $this->find_available_woocommerce_category( $woocommerce_categories, $dolibarr_name, $mapped_parent_term_id, $used_woocommerce_ids );
				if ( empty( $wc_category ) ) {
					$wc_category_id = $this->create_woocommerce_category( $dolibarr_name, $mapped_parent_term_id );
					if ( $wc_category_id > 0 ) {
						$stats['created']++;
						$dolibarr_to_wc_map[ $dolibarr_category_id ] = $wc_category_id;
						$wc_to_dolibarr_map[ $wc_category_id ] = $dolibarr_category_id;
						$used_woocommerce_ids[ $wc_category_id ] = true;
						$this->sync_woocommerce_category_term( $wc_category_id, $dolibarr_name, $mapped_parent_term_id );
					} else {
						$stats['errors']++;
						continue;
					}
				} else {
					$wc_category_id = (int) ( $wc_category['id'] ?? 0 );
					$dolibarr_to_wc_map[ $dolibarr_category_id ] = $wc_category_id;
					$wc_to_dolibarr_map[ $wc_category_id ] = $dolibarr_category_id;
					$used_woocommerce_ids[ $wc_category_id ] = true;
					$this->sync_woocommerce_category_term( $wc_category_id, $dolibarr_name, $mapped_parent_term_id );
				}
			}

			$mapping_state = $this->upsert_category_mapping_row( $dolibarr_category_id, $dolibarr_parent_id > 0 ? $dolibarr_parent_id : $dolibarr_root_id, $wc_category_id, $mapped_parent_term_id, $dolibarr_name );
			if ( 'inserted' === $mapping_state ) {
				$stats['mapped']++;
			} elseif ( 'updated' === $mapping_state ) {
				$stats['updated']++;
			} elseif ( 'unchanged' === $mapping_state ) {
				$stats['mapped']++;
			} else {
				$stats['errors']++;
				continue;
			}
		}

		return $stats;
	}

	private function fetch_woocommerce_product_categories() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'        => (int) $term->term_id,
				'name'      => sanitize_text_field( (string) $term->name ),
				'parent_id' => (int) $term->parent,
			);
		}

		return $categories;
	}

	private function ensure_dolibarr_products_root_category() {
		$categories = $this->fetch_dolibarr_product_categories();
		foreach ( $categories as $category ) {
			if ( 'productos' === $this->normalize_category_name( $category['name'] ?? '' ) ) {
				return (int) ( $category['id'] ?? 0 );
			}
		}

		return $this->create_dolibarr_product_category( 'Productos' );
	}

	private function normalize_category_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		$name = strtolower( trim( $name ) );
		return preg_replace( '/\s+/', ' ', $name );
	}

	private function create_woocommerce_category( $name, $parent_id = 0 ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' === $name ) {
			return 0;
		}

		$parent_id = (int) $parent_id;

		$insert_args = array();
		if ( $parent_id > 0 ) {
			$insert_args['parent'] = $parent_id;
		}

		$created = wp_insert_term( $name, 'product_cat', $insert_args );
		if ( is_wp_error( $created ) || empty( $created['term_id'] ) ) {
			return 0;
		}

		return (int) $created['term_id'];
	}

	private function create_dolibarr_product_category( $name, $parent_id = 0 ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' === $name ) {
			return 0;
		}

		$payloads = array(
			array( 'label' => $name, 'type' => 0, 'fk_parent' => (int) $parent_id ),
			array( 'label' => $name, 'type' => 0 ),
			array( 'label' => $name ),
		);

		foreach ( $payloads as $payload ) {
			$response = $this->api_client->post( '/categories', $payload );
			if ( empty( $response['success'] ) ) {
				continue;
			}

			$id = $this->extract_dolibarr_category_id_from_response( $response['data'] ?? null );
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	private function fetch_dolibarr_product_categories() {
		$categories = array();
		$page = 0;
		$limit = 100;
		do {
			$response = $this->api_client->get( '/categories', array( 'type' => 'product', 'sortfield' => 't.rowid', 'sortorder' => 'ASC', 'limit' => $limit, 'page' => $page ) );
			if ( empty( $response['success'] ) ) {
				Dolisync_Action_Logger::log_action( 'producto', 'lectura_categorias_dolibarr', 'error', (string) ( $response['message'] ?? __( 'Error al obtener categorías de Dolibarr.', 'dolisync' ) ), get_current_user_id() );
				break;
			}
			$data = $this->normalize_api_array( $response['data'] ?? array() );
			if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				$data = $data['data'];
			}
			if ( ! isset( $data[0] ) && ! empty( $data ) ) {
				$data = array( $data );
			}
			foreach ( $data as $category ) {
				if ( ! is_array( $category ) ) {
					continue;
				}

				$categories[] = array(
					'id'        => (int) ( $category['id'] ?? $category['rowid'] ?? 0 ),
					'name'      => sanitize_text_field( (string) ( $category['label'] ?? $category['name'] ?? '' ) ),
					'parent_id' => (int) ( $category['fk_parent'] ?? $category['parent_id'] ?? 0 ),
					'type'      => sanitize_text_field( (string) ( $category['type'] ?? '' ) ),
				);
			}
			$page++;
		} while ( count( $data ) >= $limit );

		return $categories;
	}

	private function extract_dolibarr_category_id_from_response( $data ) {
		if ( is_numeric( $data ) ) {
			return (int) $data;
		}

		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}

		if ( is_array( $data ) ) {
			return (int) ( $data['id'] ?? $data['rowid'] ?? $data[0]['id'] ?? 0 );
		}

		return 0;
	}

	private function get_mapping_by_wc_category_id( $wc_category_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_category_mappings';
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE wc_category_id = %d LIMIT 1", (int) $wc_category_id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : array();
	}

	private function get_mapping_by_dolibarr_category_id( $dolibarr_category_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_category_mappings';
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE dolibarr_category_id = %d LIMIT 1", (int) $dolibarr_category_id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : array();
	}

	private function upsert_category_mapping_row( $dolibarr_category_id, $dolibarr_parent_category_id, $wc_category_id, $wc_parent_category_id, $category_name ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_category_mappings';

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE dolibarr_category_id = %d OR wc_category_id = %d LIMIT 1", (int) $dolibarr_category_id, (int) $wc_category_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$data = array(
			'dolibarr_category_id' => (int) $dolibarr_category_id,
			'dolibarr_parent_category_id' => (int) $dolibarr_parent_category_id,
			'wc_category_id' => (int) $wc_category_id,
			'wc_parent_category_id' => (int) $wc_parent_category_id,
			'category_name' => sanitize_text_field( (string) $category_name ),
			'synced_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $existing_id > 0 ) {
			$existing_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $existing_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( is_array( $existing_row ) ) {
				$normalized_existing = array(
					'dolibarr_category_id' => (int) ( $existing_row['dolibarr_category_id'] ?? 0 ),
					'dolibarr_parent_category_id' => (int) ( $existing_row['dolibarr_parent_category_id'] ?? 0 ),
					'wc_category_id' => (int) ( $existing_row['wc_category_id'] ?? 0 ),
					'wc_parent_category_id' => (int) ( $existing_row['wc_parent_category_id'] ?? 0 ),
					'category_name' => sanitize_text_field( (string) ( $existing_row['category_name'] ?? '' ) ),
				);

				$normalized_new = array(
					'dolibarr_category_id' => $data['dolibarr_category_id'],
					'dolibarr_parent_category_id' => $data['dolibarr_parent_category_id'],
					'wc_category_id' => $data['wc_category_id'],
					'wc_parent_category_id' => $data['wc_parent_category_id'],
					'category_name' => $data['category_name'],
				);

				if ( $normalized_existing === $normalized_new ) {
					return 'unchanged';
				}
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				$data,
				array( 'id' => $existing_id ),
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return 'updated';
		}

		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			$data,
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return 'inserted';
	}

	private function sort_categories_by_parent_depth( $categories ) {
		if ( ! is_array( $categories ) ) {
			return array();
		}

		$indexed = array();
		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$category_id = (int) ( $category['id'] ?? 0 );
			if ( $category_id > 0 ) {
				$indexed[ $category_id ] = $category;
			}
		}

		$depth_by_id = array();
		foreach ( array_keys( $indexed ) as $category_id ) {
			$depth_by_id[ $category_id ] = $this->get_category_depth( $indexed, $category_id );
		}

		uasort(
			$indexed,
			function ( $left, $right ) use ( $depth_by_id ) {
				$left_id = (int) ( $left['id'] ?? 0 );
				$right_id = (int) ( $right['id'] ?? 0 );
				$left_depth = (int) ( $depth_by_id[ $left_id ] ?? 0 );
				$right_depth = (int) ( $depth_by_id[ $right_id ] ?? 0 );

				if ( $left_depth === $right_depth ) {
					return strcmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );
				}

				return $left_depth <=> $right_depth;
			}
		);

		return array_values( $indexed );
	}

	private function get_category_depth( $categories, $category_id, $trail = array() ) {
		$category_id = (int) $category_id;
		if ( $category_id <= 0 || isset( $trail[ $category_id ] ) ) {
			return 0;
		}

		$category = $categories[ $category_id ] ?? null;
		if ( empty( $category ) ) {
			return 0;
		}

		$parent_id = (int) ( $category['parent_id'] ?? 0 );
		if ( $parent_id <= 0 || ! isset( $categories[ $parent_id ] ) ) {
			return 0;
		}

		$trail[ $category_id ] = true;
		return 1 + $this->get_category_depth( $categories, $parent_id, $trail );
	}

	private function find_available_woocommerce_category( $woocommerce_categories, $name, $parent_id, $used_woocommerce_ids ) {
		$normalized_name = $this->normalize_category_name( $name );
		$parent_id = (int) $parent_id;

		foreach ( (array) $woocommerce_categories as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$wc_id = (int) ( $category['id'] ?? 0 );
			if ( $wc_id <= 0 || isset( $used_woocommerce_ids[ $wc_id ] ) ) {
				continue;
			}

			if ( $normalized_name !== $this->normalize_category_name( $category['name'] ?? '' ) ) {
				continue;
			}

			if ( $parent_id !== (int) ( $category['parent_id'] ?? 0 ) ) {
				continue;
			}

			return $category;
		}

		foreach ( (array) $woocommerce_categories as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$wc_id = (int) ( $category['id'] ?? 0 );
			if ( $wc_id <= 0 || isset( $used_woocommerce_ids[ $wc_id ] ) ) {
				continue;
			}

			if ( $normalized_name === $this->normalize_category_name( $category['name'] ?? '' ) ) {
				return $category;
			}
		}

		return null;
	}

	private function sync_woocommerce_category_term( $term_id, $name, $parent_id = 0 ) {
		$term_id = (int) $term_id;
		$name = sanitize_text_field( (string) $name );
		$parent_id = (int) $parent_id;

		if ( $term_id <= 0 || '' === $name ) {
			return false;
		}

		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		if ( (string) $term->name !== $name || (int) $term->parent !== $parent_id ) {
			$result = wp_update_term(
				$term_id,
				'product_cat',
				array(
					'name'   => $name,
					'parent' => $parent_id,
				)
			);

			return ! is_wp_error( $result );
		}

		return true;
	}

	private function reset_stats() {
		$this->stats = array(
			'created'  => 0,
			'mapped'   => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'details'  => array(),
			'errors_list' => array(),
		);
	}

	private function fetch_dolibarr_products_page( $page, $per_page ) {
		$products = array();
		$has_more = false;
		$read_errors = array();

		// Dolibarr separa productos simples (1) y padres de variantes (2).
		foreach ( array( 1, 2 ) as $variant_filter ) {
			$response = $this->api_client->get( '/products', array(
				'sortfield' => 't.rowid',
				'sortorder' => 'ASC',
				'limit' => $per_page,
				'page' => $page,
				'mode' => 1,
				'variant_filter' => $variant_filter,
				'pagination_data' => 1,
				'includestockdata' => 1,
			) );

			if ( empty( $response['success'] ) ) {
				$error_message = (string) ( $response['message'] ?? __( 'Error al obtener productos de Dolibarr.', 'dolisync' ) );
				$read_errors[] = $error_message;
				Dolisync_Action_Logger::log_action( 'producto', 'lectura_dolibarr', 'error', $error_message, get_current_user_id() );
				continue;
			}

			$body = $this->normalize_api_array( $response['data'] ?? array() );
			$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
			$pagination = isset( $body['pagination'] ) && is_array( $body['pagination'] ) ? $body['pagination'] : array();
			$has_more = $has_more || ( isset( $pagination['page_count'] ) ? $page + 1 < (int) $pagination['page_count'] : count( $data ) >= $per_page );

			foreach ( $data as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}
				$product_id = (int) ( $product['id'] ?? $product['rowid'] ?? 0 );
				$product['categories'] = $this->fetch_dolibarr_categories_for_product( $product_id );
				if ( 2 === $variant_filter ) {
					$product['variants'] = $this->fetch_dolibarr_variants( $product_id );
				}
				$products[] = $product;
			}
		}

		if ( ! empty( $read_errors ) ) {
			throw new RuntimeException(
				sprintf(
					__( 'No se pudo leer el catálogo completo de Dolibarr: %s', 'dolisync' ),
					implode( ' | ', array_unique( $read_errors ) )
				)
			);
		}

		return array(
			'data' => $products,
			'pagination' => array( 'page' => $page, 'per_page' => $per_page, 'has_more' => $has_more, 'next_page' => $has_more ? $page + 1 : null ),
		);
	}

	private function fetch_dolibarr_categories_for_product( $dolibarr_product_id ) {
		$response = $this->api_client->get( '/products/' . $dolibarr_product_id . '/categories' );
		$data = ! empty( $response['success'] ) ? $this->normalize_api_array( $response['data'] ?? array() ) : array();
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}
		return $data;
	}

	private function process_product( $dolibarr_product ) {
		$dolibarr_id = absint( $dolibarr_product['id'] ?? $dolibarr_product['rowid'] ?? 0 );
		require_once DOLISYNC_PLUGIN_DIR . 'includes/database/class-dolisync-ignored-items.php';
		if ( $dolibarr_id > 0 && Dolisync_Ignored_Items::is_ignored( 'product', 0, $dolibarr_id ) ) {
			$this->stats['skipped']++;
			return;
		}
		try {
			$dolibarr_product_id = (int) ( $dolibarr_product['id'] ?? $dolibarr_product['rowid'] ?? 0 );
			if ( ! $dolibarr_product_id ) {
				$this->stats['skipped']++;
				return;
			}

			$payload = $this->normalize_product_payload( $dolibarr_product );
			$existing_relation = $this->get_relation_by_dolibarr_product_id( $dolibarr_product_id );
			$wc_product = null;
			$is_variable_product = ! empty( $payload['variations'] );
			$wc_product_id = 0;

			if ( $existing_relation ) {
				$wc_product_id = (int) ( $existing_relation['wc_product_id'] ?? 0 );
				$wc_product = wc_get_product( $wc_product_id );
			}

			$mapped_by_sku = false;
			$mapped_by_name = false;
			if ( ! $wc_product ) {
				$wc_product = $this->find_unmapped_woocommerce_product_by_sku( $payload['sku'] );
				if ( $wc_product ) {
					$wc_product_id = (int) $wc_product->get_id();
					$mapped_by_sku = true;
				}
			}
			if ( ! $wc_product ) {
				$wc_product = $this->find_unmapped_woocommerce_product_by_name( $payload['name'] );
				if ( $wc_product ) {
					$wc_product_id = (int) $wc_product->get_id();
					$mapped_by_name = true;
				}
			}

			if ( ! $wc_product ) {
				$wc_product = $this->create_wc_product_instance( $is_variable_product, $wc_product_id );
			} elseif ( $is_variable_product && method_exists( $wc_product, 'is_type' ) && ! $wc_product->is_type( 'variable' ) ) {
				$wc_product = $this->create_wc_product_instance( true, $wc_product->get_id() );
			} elseif ( ! $is_variable_product && method_exists( $wc_product, 'is_type' ) && $wc_product->is_type( 'variable' ) ) {
				$this->sync_variations_to_woo( $dolibarr_product_id, (int) $wc_product->get_id(), array() );
				$wc_product = $this->create_wc_product_instance( false, $wc_product->get_id() );
			}

			$payload_hash = $this->payload_hash( $payload );
			if ( $existing_relation && $wc_product_id > 0 && hash_equals( (string) get_post_meta( $wc_product_id, '_dolisync_last_import_hash', true ), $payload_hash ) ) {
				$image_changed = $this->image_sync->sync_dolibarr_to_woocommerce( $dolibarr_product_id, $wc_product_id, $payload['sku'] );
				$action = $image_changed ? 'updated' : 'skipped';
				$this->stats[ $action ]++;
				$this->stats['details'][] = array( 'action' => $action, 'dolibarr_product_id' => $dolibarr_product_id, 'wc_product_id' => $wc_product_id );
				return;
			}

			$wc_product->set_name( $payload['name'] );
			$wc_product->set_status( 'publish' );
			if ( method_exists( $wc_product, 'set_catalog_visibility' ) ) {
				$wc_product->set_catalog_visibility( '1' === (string) $payload['active'] ? 'visible' : 'hidden' );
			}
			$wc_product->set_description( $payload['description'] );
			$wc_product->set_short_description( $payload['short_description'] );
			$wc_product->set_sku( $payload['sku'] );

			if ( $is_variable_product ) {
				$wc_product->set_manage_stock( false );
				$wc_product->set_stock_status( 'instock' );
				$wc_product->set_attributes( $this->build_wc_variable_attributes( $payload['variations'] ) );
			} else {
				$wc_product->set_regular_price( (string) $payload['price'] );
				$wc_product->set_price( (string) $payload['price'] );
				$wc_product->set_manage_stock( true );
				$wc_product->set_stock_quantity( is_numeric( $payload['stock_qty'] ) ? (float) $payload['stock_qty'] : null );
				$wc_product->set_stock_status( ( is_numeric( $payload['stock_qty'] ) && (float) $payload['stock_qty'] > 0 ) ? 'instock' : 'outofstock' );
			}
			$wc_product->save();

			$wc_product_id = (int) $wc_product->get_id();
			$this->sync_categories_to_woo( $dolibarr_product_id, $wc_product_id, $payload['categories'] );
			$this->image_sync->sync_dolibarr_to_woocommerce( $dolibarr_product_id, $wc_product_id, $payload['sku'] );
			$this->upsert_product_relation( $dolibarr_product_id, $wc_product_id, $payload, $existing_relation );
			if ( $is_variable_product ) {
				$this->sync_variations_to_woo( $dolibarr_product_id, $wc_product_id, $payload['variations'] );
			} else {
				$this->sync_variations_to_woo( $dolibarr_product_id, $wc_product_id, array() );
			}
			update_post_meta( $wc_product_id, '_dolisync_last_import_hash', $payload_hash );

			$action = $existing_relation ? 'updated' : ( $mapped_by_sku || $mapped_by_name ? 'mapped' : 'created' );
			$this->stats[ $action ]++;
			$this->stats['details'][] = array(
				'action' => $action,
				'dolibarr_product_id' => $dolibarr_product_id,
				'wc_product_id' => $wc_product_id,
			);

			Dolisync_Action_Logger::log_action(
				'producto',
				$existing_relation ? 'actualización' : ( $mapped_by_sku ? 'mapeo_por_sku' : ( $mapped_by_name ? 'mapeo_por_nombre' : 'creación' ) ),
				'finalizado',
				sprintf(
					__( 'Producto Dolibarr %d sincronizado con WooCommerce %d (%s).', 'dolisync' ),
					$dolibarr_product_id,
					$wc_product_id,
					$payload['sku']
				),
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			$this->stats['errors']++;
			$this->stats['errors_list'][] = array(
				'dolibarr_product_id' => (int) ( $dolibarr_product['id'] ?? $dolibarr_product['rowid'] ?? 0 ),
				'error' => $e->getMessage(),
			);
			Dolisync_Action_Logger::log_action( 'producto', 'sincronización', 'error', 'Error procesando producto: ' . $e->getMessage(), get_current_user_id() );
		}
	}

	private function normalize_product_payload( $product ) {
		$categories = array();
		if ( ! empty( $product['categories'] ) && is_array( $product['categories'] ) ) {
			foreach ( $product['categories'] as $category ) {
				if ( is_array( $category ) ) {
					$categories[] = array(
						'id'   => isset( $category['id'] ) ? (int) $category['id'] : 0,
						'name' => sanitize_text_field( (string) ( $category['name'] ?? $category['label'] ?? '' ) ),
						'parent_id' => isset( $category['parent_id'] ) ? (int) $category['parent_id'] : ( isset( $category['fk_parent'] ) ? (int) $category['fk_parent'] : 0 ),
					);
				} elseif ( is_string( $category ) ) {
					$categories[] = array(
						'id'   => 0,
						'name' => sanitize_text_field( $category ),
						'parent_id' => 0,
					);
				}
			}
		}

		$price_base_type = strtoupper( sanitize_text_field( (string) ( $product['price_base_type'] ?? 'HT' ) ) );
		$product_price = 'TTC' === $price_base_type
			? ( $product['price_ttc'] ?? $product['price'] ?? '' )
			: ( $product['price'] ?? $product['price_ht'] ?? $product['price_ttc'] ?? '' );

		return array(
			'sku' => sanitize_text_field( (string) ( $product['ref'] ?? $product['sku'] ?? '' ) ),
			'name' => sanitize_text_field( (string) ( $product['label'] ?? $product['name'] ?? '' ) ),
			'description' => wp_kses_post( (string) ( $product['description'] ?? '' ) ),
			'short_description' => wp_kses_post( (string) ( $product['note'] ?? $product['description_short'] ?? '' ) ),
			'price' => (string) $product_price,
			'price_base_type' => $price_base_type,
			'currency' => sanitize_text_field( (string) ( $product['currency'] ?? get_woocommerce_currency() ) ),
			'stock_qty' => $product['stock_reel'] ?? $product['stock'] ?? null,
			'active' => (string) ( $product['status'] ?? $product['active'] ?? 1 ),
			'categories' => $categories,
			'variations' => $this->normalize_variations( $product ),
			'image_url' => esc_url_raw( (string) ( $product['photo_url'] ?? '' ) ),
		);
	}

	private function normalize_api_array( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		return is_array( $data ) ? $data : array();
	}

	private function payload_hash( $payload ) {
		$normalize = function ( $value ) use ( &$normalize ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			foreach ( $value as $key => $child ) {
				$value[ $key ] = $normalize( $child );
			}
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value );
			} else {
				usort( $value, static function ( $a, $b ) { return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) ); } );
			}
			return $value;
		};
		return hash( 'sha256', self::PAYLOAD_VERSION . '|' . wp_json_encode( $normalize( $payload ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private function fetch_dolibarr_variants( $dolibarr_product_id ) {
		$response = $this->api_client->get( '/products/' . $dolibarr_product_id . '/variants', array( 'includestock' => 1 ) );
		if ( empty( $response['success'] ) ) {
			return array();
		}

		$variants = array();
		foreach ( $this->normalize_api_array( $response['data'] ?? array() ) as $combination ) {
			if ( ! is_array( $combination ) ) {
				continue;
			}
			$child_id = (int) ( $combination['fk_product_child'] ?? 0 );
			$child = array();
			if ( $child_id > 0 ) {
				$child_response = $this->api_client->get( '/products/' . $child_id, array( 'includestockdata' => 1, 'includeparentid' => 1 ) );
				if ( ! empty( $child_response['success'] ) ) {
					$child = $this->normalize_api_array( $child_response['data'] ?? array() );
				}
			}

			$attributes = array();
			foreach ( (array) ( $combination['attributes'] ?? array() ) as $pair ) {
				$pair = $this->normalize_api_array( $pair );
				$attribute_id = (int) ( $pair['fk_prod_attr'] ?? $pair['fk_product_attribute'] ?? 0 );
				$value_id = (int) ( $pair['fk_prod_attr_val'] ?? $pair['fk_product_attribute_value'] ?? 0 );
				$attribute_name = $this->resolve_dolibarr_attribute_label( $attribute_id );
				$attribute_value = $this->resolve_dolibarr_attribute_value( $value_id );
				if ( '' !== $attribute_name && '' !== $attribute_value ) {
					$attributes[ sanitize_title( $attribute_name ) ] = $attribute_value;
				}
			}

			$variants[] = array_merge( $child, array(
				'id' => $child_id,
				'combination_id' => (int) ( $combination['id'] ?? $combination['rowid'] ?? 0 ),
				'attributes' => $attributes,
			) );
		}
		return $variants;
	}

	private function resolve_dolibarr_attribute_label( $attribute_id ) {
		if ( $attribute_id <= 0 ) {
			return '';
		}
		if ( ! isset( $this->attribute_cache[ $attribute_id ] ) ) {
			$response = $this->api_client->get( '/products/attributes/' . $attribute_id );
			$data = ! empty( $response['success'] ) ? $this->normalize_api_array( $response['data'] ?? array() ) : array();
			$this->attribute_cache[ $attribute_id ] = sanitize_text_field( (string) ( $data['label'] ?? $data['ref'] ?? '' ) );
		}
		return $this->attribute_cache[ $attribute_id ];
	}

	private function resolve_dolibarr_attribute_value( $value_id ) {
		if ( $value_id <= 0 ) {
			return '';
		}
		if ( ! isset( $this->attribute_value_cache[ $value_id ] ) ) {
			$response = $this->api_client->get( '/products/attributes/values/' . $value_id );
			$data = ! empty( $response['success'] ) ? $this->normalize_api_array( $response['data'] ?? array() ) : array();
			$this->attribute_value_cache[ $value_id ] = sanitize_text_field( (string) ( $data['value'] ?? $data['ref'] ?? '' ) );
		}
		return $this->attribute_value_cache[ $value_id ];
	}

	private function find_unmapped_woocommerce_product_by_name( $name ) {
		global $wpdb;
		$name = trim( sanitize_text_field( (string) $name ) );
		if ( '' === $name ) {
			return null;
		}
		$product_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}dolisync_product_relations r ON r.wc_product_id = p.ID WHERE p.post_type = 'product' AND p.post_title = %s AND r.id IS NULL ORDER BY p.ID ASC LIMIT 1",
			$name
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $product_id ? wc_get_product( (int) $product_id ) : null;
	}

	private function find_unmapped_woocommerce_product_by_sku( $sku ) {
		global $wpdb;
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return null;
		}

		$product_id = (int) wc_get_product_id_by_sku( $sku );
		if ( $product_id <= 0 ) {
			return null;
		}

		$table = $wpdb->prefix . 'dolisync_product_relations';
		$is_mapped = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE wc_product_id = %d LIMIT 1", $product_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $is_mapped ? null : wc_get_product( $product_id );
	}

	private function create_wc_product_instance( $is_variable_product, $product_id = 0 ) {
		$product_id = (int) $product_id;

		if ( $product_id > 0 && function_exists( 'wc_get_product_object' ) ) {
			return wc_get_product_object( $is_variable_product ? 'variable' : 'simple', $product_id );
		}

		if ( $is_variable_product ) {
			return $product_id > 0 ? new WC_Product_Variable( $product_id ) : new WC_Product_Variable();
		}

		return $product_id > 0 ? new WC_Product_Simple( $product_id ) : new WC_Product_Simple();
	}

	private function normalize_variations( $product ) {
		$variation_sources = array( 'variants', 'variations', 'combinations', 'attributes' );

		foreach ( $variation_sources as $source ) {
			if ( empty( $product[ $source ] ) || ! is_array( $product[ $source ] ) ) {
				continue;
			}

			$normalized = array();
			foreach ( $product[ $source ] as $index => $variation ) {
				if ( ! is_array( $variation ) ) {
					continue;
				}

				$attributes = array();
				if ( ! empty( $variation['attributes'] ) && is_array( $variation['attributes'] ) ) {
					foreach ( $variation['attributes'] as $attribute_name => $attribute_value ) {
						$attributes[ sanitize_title( (string) $attribute_name ) ] = sanitize_text_field( (string) $attribute_value );
					}
				}

				if ( empty( $attributes ) ) {
					foreach ( array( 'color', 'size', 'material', 'variant', 'label' ) as $fallback_key ) {
						if ( isset( $variation[ $fallback_key ] ) && '' !== trim( (string) $variation[ $fallback_key ] ) ) {
							$attributes[ sanitize_title( $fallback_key ) ] = sanitize_text_field( (string) $variation[ $fallback_key ] );
						}
					}
				}

				$variation_price_base = strtoupper( sanitize_text_field( (string) ( $variation['price_base_type'] ?? 'HT' ) ) );
				$variation_price = 'TTC' === $variation_price_base
					? ( $variation['price_ttc'] ?? $variation['price'] ?? '' )
					: ( $variation['price'] ?? $variation['price_ht'] ?? $variation['price_ttc'] ?? '' );

				$normalized[] = array(
					'id' => (int) ( $variation['id'] ?? $variation['rowid'] ?? $index + 1 ),
					'combination_id' => (int) ( $variation['combination_id'] ?? 0 ),
					'sku' => sanitize_text_field( (string) ( $variation['sku'] ?? $variation['ref'] ?? '' ) ),
					'name' => sanitize_text_field( (string) ( $variation['name'] ?? $variation['label'] ?? '' ) ),
					'price' => (string) $variation_price,
					'stock_qty' => $variation['stock_reel'] ?? $variation['stock'] ?? null,
					'attributes' => $attributes,
				);
			}

			if ( ! empty( $normalized ) ) {
				return $normalized;
			}
		}

		return array();
	}

	private function build_wc_variable_attributes( $variations ) {
		$attribute_values = array();

		foreach ( $variations as $variation ) {
			if ( empty( $variation['attributes'] ) || ! is_array( $variation['attributes'] ) ) {
				continue;
			}

			foreach ( $variation['attributes'] as $attribute_key => $attribute_value ) {
				if ( '' === $attribute_value ) {
					continue;
				}

				if ( ! isset( $attribute_values[ $attribute_key ] ) ) {
					$attribute_values[ $attribute_key ] = array();
				}

				$attribute_values[ $attribute_key ][] = $attribute_value;
			}
		}

		$attributes = array();
		foreach ( $attribute_values as $attribute_key => $values ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( ucwords( str_replace( '_', ' ', $attribute_key ) ) );
			$attribute->set_options( array_values( array_unique( array_filter( $values ) ) ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}

		return $attributes;
	}

	private function sync_variations_to_woo( $dolibarr_product_id, $wc_product_id, $variations ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$kept_variation_ids = array();

		foreach ( $variations as $variation ) {
			$existing_wc_variation_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT wc_variation_id FROM {$table} WHERE dolibarr_product_id = %d AND dolibarr_variation_id = %d LIMIT 1",
				$dolibarr_product_id,
				(int) ( $variation['id'] ?? 0 )
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $existing_wc_variation_id <= 0 ) {
				$existing_wc_variation_id = $this->find_unmapped_wc_variation( $wc_product_id, $variation );
			}
			$variation_product = $existing_wc_variation_id > 0 ? wc_get_product( $existing_wc_variation_id ) : null;
			if ( ! $variation_product || ! $variation_product instanceof WC_Product_Variation ) {
				$variation_product = new WC_Product_Variation();
			}
			$variation_product->set_parent_id( $wc_product_id );
			$variation_product->set_status( 'publish' );
			$variation_product->set_sku( $variation['sku'] );
			if ( '' !== (string) $variation['price'] ) {
				$variation_product->set_regular_price( (string) $variation['price'] );
				$variation_product->set_price( (string) $variation['price'] );
			}
			if ( is_numeric( $variation['stock_qty'] ) ) {
				$variation_product->set_manage_stock( true );
				$variation_product->set_stock_quantity( (float) $variation['stock_qty'] );
				$variation_product->set_stock_status( (float) $variation['stock_qty'] > 0 ? 'instock' : 'outofstock' );
			}

			$variation_attributes = array();
			foreach ( (array) $variation['attributes'] as $attribute_key => $attribute_value ) {
				$variation_attributes[ 'attribute_' . sanitize_title( $attribute_key ) ] = $attribute_value;
			}
			$variation_product->set_attributes( $variation_attributes );
			$variation_id = $variation_product->save();
			$this->image_sync->sync_dolibarr_to_woocommerce( (int) ( $variation['id'] ?? 0 ), (int) $variation_id, (string) ( $variation['sku'] ?? '' ) );
			$kept_variation_ids[] = (int) $variation_id;

			$relation_data = array(
					'dolibarr_product_id' => $dolibarr_product_id,
					'wc_product_id' => $wc_product_id,
					'dolibarr_variation_id' => (int) ( $variation['id'] ?? 0 ),
					'dolibarr_combination_id' => (int) ( $variation['combination_id'] ?? 0 ),
					'wc_variation_id' => (int) $variation_id,
					'sku' => $variation['sku'],
					'price' => $variation['price'],
					'stock_qty' => $variation['stock_qty'],
					'attributes_json' => wp_json_encode( $variation['attributes'] ),
					'synced_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				);
			$relation_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE wc_variation_id = %d LIMIT 1", (int) $variation_id )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$formats = array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );
			if ( $relation_id > 0 ) {
				$wpdb->update( $table, $relation_data, array( 'id' => $relation_id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$relation_data['created_at'] = current_time( 'mysql' );
				$wpdb->insert( $table, $relation_data, array_merge( $formats, array( '%s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		$existing_variations = $wpdb->get_col(
			$wpdb->prepare( "SELECT wc_variation_id FROM {$table} WHERE wc_product_id = %d", $wc_product_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$parent_product = wc_get_product( $wc_product_id );
		if ( $parent_product && method_exists( $parent_product, 'get_children' ) ) {
			$existing_variations = array_unique( array_merge( $existing_variations, (array) $parent_product->get_children() ) );
		}

		foreach ( (array) $existing_variations as $existing_variation_id ) {
			if ( ! in_array( (int) $existing_variation_id, $kept_variation_ids, true ) ) {
				wp_delete_post( (int) $existing_variation_id, true );
				$wpdb->delete( $table, array( 'wc_variation_id' => (int) $existing_variation_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	private function find_unmapped_wc_variation( $wc_product_id, $variation ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_variation_relations';
		$parent = wc_get_product( $wc_product_id );
		if ( ! $parent || ! method_exists( $parent, 'get_children' ) ) {
			return 0;
		}
		$target_sku = trim( (string) ( $variation['sku'] ?? '' ) );
		$target_attributes = (array) ( $variation['attributes'] ?? array() );
		foreach ( (array) $parent->get_children() as $candidate_id ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wc_variation_id = %d LIMIT 1", $candidate_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			$candidate = wc_get_product( (int) $candidate_id );
			if ( ! $candidate ) {
				continue;
			}
			if ( '' !== $target_sku && $target_sku === (string) $candidate->get_sku() ) {
				return (int) $candidate_id;
			}
			$candidate_attributes = array();
			foreach ( (array) $candidate->get_attributes() as $key => $value ) {
				$candidate_attributes[ sanitize_title( preg_replace( '/^attribute_/', '', (string) $key ) ) ] = sanitize_text_field( (string) $value );
			}
			if ( ! empty( $target_attributes ) && $candidate_attributes === $target_attributes ) {
				return (int) $candidate_id;
			}
		}
		return 0;
	}

	private function sync_categories_to_woo( $dolibarr_product_id, $wc_product_id, $categories ) {
		global $wpdb;
		$term_ids = array();
		if ( is_array( $categories ) ) {
			foreach ( $categories as $category ) {
				$dolibarr_category_id = (int) ( $category['id'] ?? 0 );
				$name = sanitize_text_field( (string) ( $category['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}

				$term_id = $this->resolve_woocommerce_term_id_for_dolibarr_category( $dolibarr_category_id, $name, (int) ( $category['parent_id'] ?? 0 ) );

				if ( $term_id <= 0 ) {
					continue;
				}

				$term_ids[] = $term_id;
				$term = get_term( $term_id, 'product_cat' );
				$this->upsert_category_mapping_row(
					$dolibarr_category_id,
					(int) ( $category['parent_id'] ?? 0 ),
					$term_id,
					( $term && ! is_wp_error( $term ) ) ? (int) $term->parent : 0,
					$name
				);
			}
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $wc_product_id, $term_ids, 'product_cat' );
		} else {
			wp_set_object_terms( $wc_product_id, array(), 'product_cat' );
		}

		$wpdb->update(
			$wpdb->prefix . 'dolisync_product_relations',
			array(
				'categories_json' => wp_json_encode( is_array( $categories ) ? $categories : array() ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'dolibarr_product_id' => $dolibarr_product_id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function resolve_woocommerce_term_id_for_dolibarr_category( $dolibarr_category_id, $name, $parent_id = 0 ) {
		$dolibarr_category_id = (int) $dolibarr_category_id;
		$parent_id = (int) $parent_id;
		$name = sanitize_text_field( (string) $name );

		if ( $dolibarr_category_id > 0 ) {
			$mapping = $this->get_mapping_by_dolibarr_category_id( $dolibarr_category_id );
			$term_id = (int) ( $mapping['wc_category_id'] ?? 0 );
			if ( $term_id > 0 ) {
				$this->sync_woocommerce_category_term( $term_id, $name, $parent_id );
				return $term_id;
			}
		}

		if ( '' === $name ) {
			return 0;
		}

		$term = wp_insert_term(
			$name,
			'product_cat',
			$parent_id > 0 ? array( 'parent' => $parent_id ) : array()
		);

		if ( is_wp_error( $term ) || empty( $term['term_id'] ) ) {
			return 0;
		}

		return (int) $term['term_id'];
	}

	private function upsert_product_relation( $dolibarr_product_id, $wc_product_id, $payload, $existing_relation ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_relations';
		$categories_json = wp_json_encode( $payload['categories'] );
		$data = array(
			'dolibarr_product_id' => $dolibarr_product_id,
			'wc_product_id' => $wc_product_id,
			'sku' => $payload['sku'],
			'name' => $payload['name'],
			'description' => $payload['description'],
			'short_description' => $payload['short_description'],
			'price' => $payload['price'],
			'currency' => $payload['currency'],
			'stock_qty' => $payload['stock_qty'],
			'product_type' => empty( $payload['variations'] ) ? 'simple' : 'variable',
			'status' => ( '1' === (string) $payload['active'] ) ? 'publish' : 'hidden',
			'categories_json' => $categories_json,
			'image_url' => $payload['image_url'],
			'last_sync_status' => 'success',
			'last_error_message' => '',
			'synced_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		if ( empty( $existing_relation ) ) {
			$data['created_at'] = current_time( 'mysql' );
			$data['first_synced_at'] = current_time( 'mysql' );
			$result = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $result ) {
				throw new Exception( __( 'No se pudo guardar el mapeo del producto en la base de datos local.', 'dolisync' ) );
			}
			return;
		}

		$result = $wpdb->update(
			$table,
			$data,
			array( 'dolibarr_product_id' => $dolibarr_product_id ),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			throw new Exception( __( 'No se pudo actualizar el mapeo del producto en la base de datos local.', 'dolisync' ) );
		}
	}

	private function get_relation_by_dolibarr_product_id( $dolibarr_product_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dolisync_product_relations';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE dolibarr_product_id = %d", $dolibarr_product_id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
