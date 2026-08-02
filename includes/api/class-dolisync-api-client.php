<?php
/**
 * Cliente API de DoliSync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_API_Client {
	private $api_url = '';
	private $api_key = '';
	private $cf_access_headers = array();
	private $logger = null;

	const MAX_RETRIES = 3;
	const RETRY_DELAY = 1;
	const MAX_RETRY_DELAY = 30;
	// La validación fiscal con VeriFactu puede tardar sensiblemente más que una
	// llamada REST convencional. WordPress esperará hasta dos minutos antes de
	// considerar que Dolibarr no ha respondido.
	const REQUEST_TIMEOUT = 120;
	const MAX_LOG_VALUE_LENGTH = 12000;
	const MAX_RESPONSE_BYTES = 26214400;

	public function __construct() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';

		$api_url = (string) Dolisync_Config::get( 'dolibarr_url', '' );
		$api_key = (string) Dolisync_Config::get( 'dolibarr_api_key', '' );
		$cf_access_headers = Dolisync_Config::get_cf_access_headers();

		$this->api_url = rtrim( $api_url, '/' );
		$this->api_key = $this->normalize_api_key( $api_key );
		$this->cf_access_headers = is_array( $cf_access_headers ) ? $cf_access_headers : array();
		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-logger.php';
		$this->logger = new Dolisync_Logger();
	}

	/**
	 * Normaliza la API key: acepta valor encriptado o plano.
	 */
	private function normalize_api_key( $api_key ) {
		$api_key = trim( (string) $api_key );

		if ( '' === $api_key ) {
			return '';
		}

		require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-encryption.php';
		$decrypted = Dolisync_Encryption::decrypt( $api_key );

		if ( false !== $decrypted && '' !== trim( (string) $decrypted ) ) {
			return trim( (string) $decrypted );
		}

		// Fallback: si ya viene en plano, usarla tal cual.
		return $api_key;
	}

	public function get( $endpoint, $params = array() ) {
		return $this->request( $endpoint, 'GET', null, $params );
	}

	public function post( $endpoint, $data = array(), $params = array() ) {
		return $this->request( $endpoint, 'POST', $data, $params );
	}

	public function put( $endpoint, $data = array(), $params = array() ) {
		return $this->request( $endpoint, 'PUT', $data, $params );
	}

	public function delete( $endpoint, $params = array() ) {
		return $this->request( $endpoint, 'DELETE', null, $params );
	}

	public function test_connection() {
		$result = $this->get( '/status' );

		if ( empty( $result['success'] ) ) {
			return $result;
		}

		if ( ! $this->looks_like_dolibarr_status_response( $result['data'] ?? null ) ) {
			$result['code'] = 'unexpected_status';
			$result['message'] = __( 'La conexión responde, pero la respuesta no parece provenir de Dolibarr.', 'dolisync' );
		}

		return $result;
	}

	private function request( $endpoint, $method = 'GET', $data = null, $params = array() ) {
		$method = strtoupper( (string) $method );
		$endpoint = $this->normalize_endpoint( $endpoint );
		$config_error = $this->validate_configuration();
		if ( '' !== $config_error ) {
			return array( 'success' => false, 'code' => 'configuration_error', 'message' => $config_error, 'http_code' => null, 'data' => null, 'time_ms' => 0 );
		}
		$url = $this->build_url( $endpoint, $params );
		$headers = $this->get_headers();
        $safe_headers = $headers;

		if ( isset( $safe_headers['Authorization'] ) ) {
			$safe_headers['Authorization'] = 'Bearer ********';
		}
		if ( isset( $safe_headers['DOLAPIKEY'] ) ) {
			$safe_headers['DOLAPIKEY'] = '********';
		}

		if ( isset( $safe_headers['CF-Access-Client-Secret'] ) ) {
			$safe_headers['CF-Access-Client-Secret'] = '********';
		}
		$body = null !== $data
            ? wp_json_encode(
                $data,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_PARTIAL_OUTPUT_ON_ERROR
            )
            : null;

		$request_payload = array(
			'url'     => $this->build_url( $endpoint, $this->sanitize_for_log( $params ) ),
			'method'  => $method,
			'headers' => $safe_headers,
			'query'   => $this->sanitize_for_log( $params ),
			'body'    => null !== $data ? $this->sanitize_for_log( $data ) : null,
		);

		// Determinar origen/ejecutor para registros
		$origin = 'system';
		$executor = 'system';
		$cron_interval = '';

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$origin = 'cron';
			$executor = 'cron';
			if ( file_exists( DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php' ) ) {
				require_once DOLISYNC_PLUGIN_DIR . 'includes/core/class-dolisync-config.php';
				$current_hook = function_exists( 'current_filter' ) ? (string) current_filter() : '';
				if ( in_array( $current_hook, array( 'dolisync_stock_autosync', 'dolisync_stock_autosync_batch' ), true ) ) {
					$cron_interval = Dolisync_Config::get_stock_sync_interval();
					$executor = 'cron:stock';
				} elseif ( 'dolisync_product_autosync' === $current_hook ) {
					$cron_interval = Dolisync_Config::get_product_sync_interval();
					$executor = 'cron:products';
				} else {
					$cron_interval = Dolisync_Config::get_cron_interval();
				}
			}
		} else {
			if ( function_exists( 'wp_get_current_user' ) ) {
				$u = wp_get_current_user();
				if ( ! empty( $u->user_login ) ) {
					$origin = 'user';
					$executor = $u->user_login;
				} elseif ( ! empty( $u->ID ) ) {
					$origin = 'user';
					$executor = 'user:' . (int) $u->ID;
				}
			}
		}

		$last_error = '';
		$last_http_code = null;
		$last_response_body = null;
		$time_ms = 0;

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$start = microtime( true );
			$response = wp_remote_request(
				$url,
				array(
					'method'     => $method,
					'headers'    => $headers,
					'body'       => $body,
					'timeout'    => self::REQUEST_TIMEOUT,
					'sslverify'  => true,
					'user-agent' => 'DoliSync/1.0',
					'limit_response_size' => self::MAX_RESPONSE_BYTES,
				)
			);
			$time_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				if ( $this->can_retry_method( $method ) && $attempt < self::MAX_RETRIES ) {
					sleep( self::RETRY_DELAY * ( 2 ** ( $attempt - 1 ) ) );
					continue;
				}

				// Registrar error incluyendo contexto de ejecución
				$this->logger->log( 'ERROR', $endpoint, $method, $request_payload, null, null, $time_ms, $last_error, $origin, $executor, $cron_interval );
				return array(
					'success'   => false,
					'code'      => 'connection_error',
					'message'   => $last_error,
					'http_code' => null,
					'data'      => null,
					'time_ms'   => $time_ms,
				);
			}

			$last_http_code = (int) wp_remote_retrieve_response_code( $response );
			$last_response_body = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $last_response_body, true );
			$json_error = json_last_error();
			if ( JSON_ERROR_NONE !== $json_error ) {
				$decoded = $last_response_body;
			}
			if ( $last_http_code < 400 && '' !== trim( $last_response_body ) && JSON_ERROR_NONE !== $json_error ) {
				$message = __( 'Dolibarr ha devuelto una respuesta no válida. Revisa si un proxy, firewall o página de acceso está interceptando la API.', 'dolisync' );
				$this->logger->log( 'ERROR', $endpoint, $method, $request_payload, $this->sanitize_for_log( $decoded ), $last_http_code, $time_ms, $message, $origin, $executor, $cron_interval );
				return array( 'success' => false, 'code' => 'invalid_response', 'message' => $message, 'http_code' => $last_http_code, 'data' => null, 'time_ms' => $time_ms );
			}

			if ( $last_http_code >= 400 ) {
				if ( 404 === $last_http_code && 'GET' === $method && 0 === strpos( $endpoint, '/invoices/ref_ext/' ) ) {
					$message = __( 'No existe una factura con esta referencia externa; se puede crear una nueva.', 'dolisync' );
					$this->logger->log( 'INFO', $endpoint, $method, $request_payload, $this->sanitize_for_log( $decoded ), $last_http_code, $time_ms, $message, $origin, $executor, $cron_interval );
					return array( 'success' => true, 'code' => 'empty_result', 'message' => $message, 'http_code' => $last_http_code, 'data' => array(), 'time_ms' => $time_ms );
				}
				if ( 404 === $last_http_code && 'GET' === $method && '/documents' === $endpoint ) {
					$message = __( 'El producto no tiene documentos asociados.', 'dolisync' );
					$this->logger->log( 'INFO', $endpoint, $method, $request_payload, $this->sanitize_for_log( $decoded ), $last_http_code, $time_ms, $message, $origin, $executor, $cron_interval );
					return array(
						'success' => true,
						'code' => 'empty_result',
						'message' => $message,
						'http_code' => $last_http_code,
						'data' => array(),
						'time_ms' => $time_ms,
					);
				}
				$api_message = $this->extract_api_error_message( $decoded, $last_http_code );
				$error_code = 'api_error';
				$user_message = $api_message;

				if ( 401 === $last_http_code ) {
					$error_code = 'auth_error';
					$user_message = __( 'No autorizado por Dolibarr (401). Revisa la API Key configurada en DoliSync.', 'dolisync' );
				} elseif ( 403 === $last_http_code ) {
					$error_code = 'permission_error';
					$user_message = $this->build_permission_error_message( $endpoint );
				} elseif ( 429 === $last_http_code ) {
					$error_code = 'rate_limit_error';
					$user_message = __( 'Dolibarr ha limitado temporalmente las solicitudes (429).', 'dolisync' );
				} elseif ( $last_http_code >= 500 && $last_http_code <= 599 ) {
					$error_code = 'server_error';
					$user_message = sprintf(
						__( 'Dolibarr ha devuelto un error temporal del servidor (%d).', 'dolisync' ),
						$last_http_code
					);
				}

				$log_message = $user_message;
				if ( $api_message !== $user_message ) {
					$log_message = $user_message . ' | API: ' . $api_message;
				}

				if ( $this->can_retry_http_response( $method, $last_http_code ) && $attempt < self::MAX_RETRIES ) {
					$retry_delay = $this->get_retry_delay( $response, $attempt );
					$retry_message = sprintf(
						__( '%1$s Reintento %2$d de %3$d en %4$d segundos.', 'dolisync' ),
						$log_message,
						$attempt + 1,
						self::MAX_RETRIES,
						$retry_delay
					);
					$this->logger->log( 'WARNING', $endpoint, $method, $request_payload, $this->sanitize_for_log( $decoded ), $last_http_code, $time_ms, $retry_message, $origin, $executor, $cron_interval );
					sleep( $retry_delay );
					continue;
				}

				if ( ! $this->can_retry_http_response( $method, $last_http_code ) && $this->is_retryable_http_code( $last_http_code ) ) {
					$log_message .= ' ' . __( 'La operación no se ha reintentado automáticamente para evitar duplicados; comprueba el resultado en Dolibarr antes de repetirla.', 'dolisync' );
				}
				$this->logger->log( 'ERROR', $endpoint, $method, $request_payload, $this->sanitize_for_log( $decoded ), $last_http_code, $time_ms, $log_message, $origin, $executor, $cron_interval );
				return array(
					'success'   => false,
					'code'      => $error_code,
					'message'   => $user_message,
					'api_message' => $api_message,
					'http_code' => $last_http_code,
					'data'      => null,
					'time_ms'   => $time_ms,
				);
			}

			if ( '/status' === $endpoint && ! $this->looks_like_dolibarr_status_response( $decoded ) ) {
				$message = __( 'La conexión responde, pero la respuesta no parece provenir de Dolibarr.', 'dolisync' );
				$this->logger->log( 'WARNING', $endpoint, $method, $request_payload, $this->sanitize_for_log( $decoded ), $last_http_code, $time_ms, $message, $origin, $executor, $cron_interval );
				return array(
					'success'   => true,
					'code'      => 'unexpected_status',
					'message'   => $message,
					'http_code' => $last_http_code,
					'data'      => $decoded,
					'time_ms'   => $time_ms,
				);
			}

			$this->logger->log( 'INFO', $endpoint, $method, $request_payload, $this->sanitize_for_log( $decoded ), $last_http_code, $time_ms, null, $origin, $executor, $cron_interval );
			return array(
				'success'   => true,
				'code'      => 'ok',
				'message'   => __( 'Success', 'dolisync' ),
				'http_code' => $last_http_code,
				'data'      => $decoded,
				'time_ms'   => $time_ms,
			);
		}

		$this->logger->log_error( $endpoint, $method, $request_payload, null, $last_http_code, $time_ms, $last_error );
		return array(
			'success'   => false,
			'code'      => 'connection_error',
			'message'   => $last_error,
			'http_code' => $last_http_code,
			'data'      => null,
			'time_ms'   => $time_ms,
		);
	}

	private function normalize_endpoint( $endpoint ) {
		$endpoint = trim( (string) $endpoint );
		return '/' === substr( $endpoint, 0, 1 ) ? $endpoint : '/' . $endpoint;
	}

	private function build_url( $endpoint, $params = array() ) {
		$url = $this->api_url . '/api/index.php' . $endpoint;
		return ! empty( $params ) ? add_query_arg( $params, $url ) : $url;
	}

	private function get_headers() {
		$headers = array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $this->api_key,
			'DOLAPIKEY'     => $this->api_key,
		);

		if ( ! empty( $this->cf_access_headers['CF-Access-Client-Id'] ) ) {
			$headers['CF-Access-Client-Id'] = (string) $this->cf_access_headers['CF-Access-Client-Id'];
		}

		if ( ! empty( $this->cf_access_headers['CF-Access-Client-Secret'] ) ) {
			$headers['CF-Access-Client-Secret'] = (string) $this->cf_access_headers['CF-Access-Client-Secret'];
		}

		return $headers;
	}

	private function looks_like_dolibarr_status_response( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( isset( $data['success'] ) && is_array( $data['success'] ) ) {
			$success = $data['success'];
			return isset( $success['code'] ) && isset( $success['dolibarr_version'] );
		}

		return isset( $data['code'] ) && isset( $data['dolibarr_version'] );
	}

	private function extract_api_error_message( $decoded, $http_code ) {
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
				$error = $decoded['error'];
				$message = trim( (string) ( $error['message'] ?? '' ) );
				$details = array();
				foreach ( $error as $key => $value ) {
					if ( is_int( $key ) && is_scalar( $value ) && '' !== trim( (string) $value ) ) {
						$details[] = trim( (string) $value );
					}
				}
				if ( '' !== $message ) {
					return empty( $details ) ? $message : $message . ' (' . implode( ', ', $details ) . ')';
				}
			}
			foreach ( array( 'error', 'message', 'error_description', 'msg' ) as $key ) {
				if ( isset( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) && '' !== trim( (string) $decoded[ $key ] ) ) {
					return trim( (string) $decoded[ $key ] );
				}
			}
		}

		if ( is_string( $decoded ) && '' !== trim( $decoded ) ) {
			return trim( $decoded );
		}

		return sprintf( 'Error HTTP %d', (int) $http_code );
	}

	private function is_retryable_http_code( $http_code ) {
		return 429 === (int) $http_code || ( (int) $http_code >= 500 && (int) $http_code <= 599 );
	}

	/** Solo se reintentan automáticamente operaciones idempotentes. */
	private function can_retry_method( $method ) {
		return in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD', 'PUT', 'DELETE' ), true );
	}

	private function can_retry_http_response( $method, $http_code ) {
		// Un 429 rechaza explícitamente la solicitud; los 5xx son ambiguos para POST.
		return 429 === (int) $http_code || ( $this->can_retry_method( $method ) && $this->is_retryable_http_code( $http_code ) );
	}

	private function validate_configuration() {
		$parts = wp_parse_url( $this->api_url );
		if ( '' === $this->api_url || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return __( 'La URL de Dolibarr no está configurada o no es válida.', 'dolisync' );
		}
		if ( '' === $this->api_key ) {
			return __( 'La API Key de Dolibarr no está configurada.', 'dolisync' );
		}
		return '';
	}

	private function sanitize_for_log( $value, $key = '' ) {
		$sensitive_keys = array( 'authorization', 'dolapikey', 'api_key', 'apikey', 'token', 'password', 'secret', 'filecontent' );
		$key_lc = strtolower( (string) $key );
		if ( 'content' === $key_lc && is_string( $value ) && strlen( $value ) > 1024 ) {
			return '[contenido de documento omitido]';
		}
		foreach ( $sensitive_keys as $sensitive_key ) {
			if ( false !== strpos( $key_lc, $sensitive_key ) ) {
				return 'filecontent' === $sensitive_key ? '[contenido binario omitido]' : '********';
			}
		}
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $child_key => $child_value ) {
				$clean[ $child_key ] = $this->sanitize_for_log( $child_value, $child_key );
			}
			return $clean;
		}
		if ( is_object( $value ) ) {
			return $this->sanitize_for_log( json_decode( wp_json_encode( $value ), true ) );
		}
		if ( is_string( $value ) && strlen( $value ) > self::MAX_LOG_VALUE_LENGTH ) {
			return substr( $value, 0, self::MAX_LOG_VALUE_LENGTH ) . sprintf( '… [%d bytes omitidos]', strlen( $value ) - self::MAX_LOG_VALUE_LENGTH );
		}
		return $value;
	}

	private function get_retry_delay( $response, $attempt ) {
		$retry_after = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		$delay = self::RETRY_DELAY * ( 2 ** max( 0, (int) $attempt - 1 ) );

		if ( ctype_digit( $retry_after ) ) {
			$delay = (int) $retry_after;
		} elseif ( '' !== $retry_after ) {
			$retry_timestamp = strtotime( $retry_after );
			if ( false !== $retry_timestamp ) {
				$delay = max( 1, $retry_timestamp - time() );
			}
		}

		return max( 1, min( self::MAX_RETRY_DELAY, $delay ) );
	}

	private function build_permission_error_message( $endpoint ) {
		$endpoint = strtolower( (string) $this->normalize_endpoint( $endpoint ) );
		$resource_label = __( 'el recurso solicitado', 'dolisync' );
		$permission_hint = __( 'revisa que la API Key tenga permisos suficientes en Dolibarr', 'dolisync' );

		if ( false !== strpos( $endpoint, '/thirdparties' ) || false !== strpos( $endpoint, '/societe' ) || false !== strpos( $endpoint, '/contacts' ) ) {
			$resource_label = __( 'terceros/societe', 'dolisync' );
		} elseif ( false !== strpos( $endpoint, '/products' ) ) {
			$resource_label = __( 'productos', 'dolisync' );
		} elseif ( false !== strpos( $endpoint, '/categories' ) ) {
			$resource_label = __( 'categorías', 'dolisync' );
		} elseif ( false !== strpos( $endpoint, '/orders' ) || false !== strpos( $endpoint, '/commande' ) ) {
			$resource_label = __( 'pedidos', 'dolisync' );
		} elseif ( false !== strpos( $endpoint, '/invoices' ) || false !== strpos( $endpoint, '/facture' ) ) {
			$resource_label = __( 'facturas', 'dolisync' );
		}

		return sprintf(
			__( 'Sin permisos en Dolibarr (403) para %1$s. %2$s.', 'dolisync' ),
			$resource_label,
			$permission_hint
		);
	}
}
