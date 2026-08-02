<?php
/**
 * Sincronización de imágenes de producto mediante la API de documentos de Dolibarr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Product_Image_Sync {
	private const META_DOCUMENT_KEY = '_dolisync_dolibarr_document_key';
	private const META_DOCUMENT_HASH = '_dolisync_dolibarr_document_hash';
	private const META_DOCUMENT_SIGNATURE = '_dolisync_dolibarr_document_signature';
	private const META_UPLOADS = '_dolisync_dolibarr_image_uploads';
	private const MAX_IMAGE_BYTES = 15728640;

	private $api_client;

	public function __construct( $api_client ) {
		$this->api_client = $api_client;
	}

	public function sync_dolibarr_to_woocommerce( $dolibarr_product_id, $wc_product_id, $dolibarr_ref = '' ) {
		if ( (int) $dolibarr_product_id <= 0 || (int) $wc_product_id <= 0 ) {
			return false;
		}
		$dolibarr_ref = $this->resolve_dolibarr_ref( $dolibarr_product_id, $dolibarr_ref );
		$documents = $this->get_dolibarr_images( $dolibarr_product_id, $dolibarr_ref );
		if ( empty( $documents ) ) {
			return false;
		}

		$attachment_ids = array();
		foreach ( $documents as $document ) {
			$attachment_id = $this->import_dolibarr_document( $dolibarr_product_id, $wc_product_id, $document );
			if ( $attachment_id > 0 ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		if ( empty( $attachment_ids ) ) {
			return false;
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product ) {
			return false;
		}

		$changed = (int) $product->get_image_id() !== (int) $attachment_ids[0];
		$product->set_image_id( (int) $attachment_ids[0] );
		if ( ! $product->is_type( 'variation' ) ) {
			$existing_gallery = array_map( 'intval', (array) $product->get_gallery_image_ids() );
			$new_gallery = array_values( array_unique( array_merge( $existing_gallery, array_slice( $attachment_ids, 1 ) ) ) );
			$changed = $changed || $existing_gallery !== $new_gallery;
			$product->set_gallery_image_ids( $new_gallery );
		}
		if ( $changed ) {
			$product->save();
		}
		return $changed;
	}

	public function sync_woocommerce_to_dolibarr( $wc_product_id, $dolibarr_product_id, $dolibarr_ref ) {
		$product = wc_get_product( $wc_product_id );
		if ( ! $product || (int) $dolibarr_product_id <= 0 ) {
			return false;
		}

		$attachment_ids = array_filter( array_unique( array_merge( array( (int) $product->get_image_id() ), $product->is_type( 'variation' ) ? array() : (array) $product->get_gallery_image_ids() ) ) );
		if ( empty( $attachment_ids ) ) {
			return false;
		}
		$dolibarr_ref = $this->resolve_dolibarr_ref( $dolibarr_product_id, $dolibarr_ref );
		if ( '' === $dolibarr_ref ) {
			throw new Exception( __( 'No se pudo resolver la referencia del producto para subir sus imágenes a Dolibarr.', 'dolisync' ) );
		}
		$changed = false;
		foreach ( $attachment_ids as $attachment_id ) {
			$changed = $this->upload_woocommerce_attachment( (int) $attachment_id, (int) $dolibarr_product_id, (string) $dolibarr_ref ) || $changed;
		}
		return $changed;
	}

	private function resolve_dolibarr_ref( $dolibarr_product_id, $fallback_ref ) {
		$response = $this->api_client->get( '/products/' . (int) $dolibarr_product_id );
		if ( ! empty( $response['success'] ) ) {
			$data = $this->normalize_array( $response['data'] ?? array() );
			$ref = trim( (string) ( $data['ref'] ?? '' ) );
			if ( '' !== $ref ) {
				return $ref;
			}
		}
		return trim( (string) $fallback_ref );
	}

	private function get_dolibarr_images( $dolibarr_product_id, $dolibarr_ref ) {
		$images = array();
		$page = 0;
		do {
			$response = $this->api_client->get( '/documents', array(
				'modulepart' => 'product',
				'id' => (int) $dolibarr_product_id,
				'sortfield' => 'name',
				'sortorder' => 'asc',
				'limit' => 100,
				'page' => $page,
				'content_type' => 'image/jpeg,image/png,image/gif,image/webp',
				'pagination_data' => 1,
			) );
			if ( empty( $response['success'] ) ) {
				if ( 404 === (int) ( $response['http_code'] ?? 0 ) && 0 === $page ) {
					return array();
				}
				throw new Exception( (string) ( $response['message'] ?? __( 'No se pudieron listar las imágenes del producto en Dolibarr.', 'dolisync' ) ) );
			}

			$body = $this->normalize_array( $response['data'] ?? array() );
			$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
			$pagination = isset( $body['pagination'] ) && is_array( $body['pagination'] ) ? $body['pagination'] : array();
			foreach ( $data as $document ) {
				$document = $this->normalize_array( $document );
				$name = (string) ( $document['name'] ?? $document['filename'] ?? '' );
				$relative_name = (string) ( $document['relativename'] ?? $document['relative_name'] ?? $document['path'] ?? '' );
				$mime = strtolower( (string) ( $document['content-type'] ?? $document['content_type'] ?? $document['type'] ?? '' ) );
				if ( '' === $relative_name && '' !== $name ) {
					$relative_name = (string) ( $document['level1name'] ?? $document['ref'] ?? '' ) . '/' . $name;
				}
				if ( ! $this->is_supported_image( $name, $mime ) || $this->is_generated_thumbnail( $relative_name ) ) {
					continue;
				}
				$document['resolved_name'] = '' !== $name ? $name : basename( $relative_name );
				$relative_name = ltrim( str_replace( '\\', '/', $relative_name ), '/' );
				if ( false === strpos( $relative_name, '/' ) && '' !== $dolibarr_ref ) {
					$relative_name = trim( $dolibarr_ref, '/' ) . '/' . $relative_name;
				}
				$document['resolved_relative_name'] = $relative_name;
				$images[] = $document;
			}
			$page++;
			$has_more = isset( $pagination['page_count'] ) ? $page < (int) $pagination['page_count'] : count( $data ) >= 100;
		} while ( $has_more );

		return $images;
	}

	private function import_dolibarr_document( $dolibarr_product_id, $wc_product_id, $document ) {
		$relative_name = (string) $document['resolved_relative_name'];
		$document_key = (int) $dolibarr_product_id . ':' . $relative_name;
		$signature = hash( 'sha256', wp_json_encode( array(
			$document['size'] ?? $document['filesize'] ?? '',
			$document['date'] ?? $document['datem'] ?? $document['date_modification'] ?? '',
			$relative_name,
		) ) );
		$existing_id = $this->find_attachment_by_document_key( $document_key );
		if ( $existing_id > 0 && hash_equals( (string) get_post_meta( $existing_id, self::META_DOCUMENT_SIGNATURE, true ), $signature ) ) {
			return $existing_id;
		}
		$response = $this->api_client->get( '/documents/download', array( 'modulepart' => 'product', 'original_file' => $relative_name ) );
		if ( empty( $response['success'] ) ) {
			throw new Exception( (string) ( $response['message'] ?? __( 'No se pudo descargar una imagen de Dolibarr.', 'dolisync' ) ) );
		}

		$data = $this->normalize_array( $response['data'] ?? array() );
		$content = (string) ( $data['content'] ?? '' );
		$binary = 'base64' === strtolower( (string) ( $data['encoding'] ?? 'base64' ) ) ? base64_decode( $content, true ) : $content;
		if ( false === $binary || '' === $binary || strlen( $binary ) > self::MAX_IMAGE_BYTES ) {
			throw new Exception( __( 'La imagen descargada de Dolibarr está vacía o supera los 15 MB.', 'dolisync' ) );
		}

		$hash = hash( 'sha256', $binary );
		if ( $existing_id > 0 && hash_equals( (string) get_post_meta( $existing_id, self::META_DOCUMENT_HASH, true ), $hash ) ) {
			update_post_meta( $existing_id, self::META_DOCUMENT_SIGNATURE, $signature );
			$this->mark_as_uploaded_to_dolibarr( $existing_id, $dolibarr_product_id, $hash );
			return $existing_id;
		}

		$filename = sanitize_file_name( (string) ( $data['filename'] ?? $document['resolved_name'] ) );
		if ( '' === $filename ) {
			$filename = 'dolisync-product-' . (int) $dolibarr_product_id . '.jpg';
		}

		if ( $existing_id > 0 && $this->replace_attachment_file( $existing_id, $binary ) ) {
			update_post_meta( $existing_id, self::META_DOCUMENT_HASH, $hash );
			update_post_meta( $existing_id, self::META_DOCUMENT_SIGNATURE, $signature );
			$this->mark_as_uploaded_to_dolibarr( $existing_id, $dolibarr_product_id, $hash );
			return $existing_id;
		}

		$upload = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			throw new Exception( (string) $upload['error'] );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$mime = wp_check_filetype( $upload['file'] );
		$attachment_id = wp_insert_attachment( array(
			'post_mime_type' => (string) ( $mime['type'] ?? 'image/jpeg' ),
			'post_title' => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_status' => 'inherit',
			'post_parent' => (int) $wc_product_id,
		), $upload['file'], (int) $wc_product_id, true );
		if ( is_wp_error( $attachment_id ) ) {
			throw new Exception( $attachment_id->get_error_message() );
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, self::META_DOCUMENT_KEY, $document_key );
		update_post_meta( $attachment_id, self::META_DOCUMENT_HASH, $hash );
		update_post_meta( $attachment_id, self::META_DOCUMENT_SIGNATURE, $signature );
		$this->mark_as_uploaded_to_dolibarr( $attachment_id, $dolibarr_product_id, $hash );

		return (int) $attachment_id;
	}

	private function upload_woocommerce_attachment( $attachment_id, $dolibarr_product_id, $dolibarr_ref ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) || ! wp_attachment_is_image( $attachment_id ) || filesize( $file ) > self::MAX_IMAGE_BYTES ) {
			return false;
		}

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return false;
		}
		$hash = hash( 'sha256', $content );
		$uploads = get_post_meta( $attachment_id, self::META_UPLOADS, true );
		$uploads = is_array( $uploads ) ? $uploads : array();
		if ( isset( $uploads[ $dolibarr_product_id ] ) && hash_equals( (string) $uploads[ $dolibarr_product_id ], $hash ) ) {
			return false;
		}

		$filename = 'dolisync-wc-' . $attachment_id . '-' . sanitize_file_name( basename( $file ) );
		$response = $this->api_client->post( '/documents/upload', array(
			'filename' => $filename,
			'modulepart' => 'product',
			'ref' => $dolibarr_ref,
			'filecontent' => base64_encode( $content ),
			'fileencoding' => 'base64',
			'overwriteifexists' => 1,
			'createdirifnotexists' => 1,
		) );
		if ( empty( $response['success'] ) ) {
			throw new Exception( (string) ( $response['message'] ?? __( 'No se pudo subir una imagen del producto a Dolibarr.', 'dolisync' ) ) );
		}
		$uploads[ $dolibarr_product_id ] = $hash;
		update_post_meta( $attachment_id, self::META_UPLOADS, $uploads );
		return true;
	}

	private function mark_as_uploaded_to_dolibarr( $attachment_id, $dolibarr_product_id, $hash ) {
		$uploads = get_post_meta( $attachment_id, self::META_UPLOADS, true );
		$uploads = is_array( $uploads ) ? $uploads : array();
		$uploads[ (int) $dolibarr_product_id ] = (string) $hash;
		update_post_meta( $attachment_id, self::META_UPLOADS, $uploads );
	}

	private function replace_attachment_file( $attachment_id, $binary ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_writable( $file ) || false === file_put_contents( $file, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );
		return true;
	}

	private function find_attachment_by_document_key( $document_key ) {
		$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => self::META_DOCUMENT_KEY, 'meta_value' => $document_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	private function is_supported_image( $name, $mime ) {
		return 0 === strpos( $mime, 'image/' ) || (bool) preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', (string) $name );
	}

	private function is_generated_thumbnail( $path ) {
		return false !== strpos( (string) $path, '/thumbs/' ) || (bool) preg_match( '/_(?:small|mini)\.[a-z0-9]+$/i', (string) $path );
	}

	private function normalize_array( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		return is_array( $data ) ? $data : array();
	}
}
