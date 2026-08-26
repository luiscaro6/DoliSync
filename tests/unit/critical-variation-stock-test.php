<?php
/**
 * Pruebas de regresión ejecutables sin cargar WordPress.
 *
 * Ejecutar con: php tests/unit/critical-variation-stock-test.php
 */

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/sync/products/class-dolisync-stock-sync.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/class-dolisync-api-client.php';
require_once dirname( __DIR__, 2 ) . '/includes/sync/products/class-dolisync-product-sync-reverse.php';

function dolisync_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . '\nEsperado: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
		);
	}
}

$stock_method = ( new ReflectionClass( 'Dolisync_Stock_Sync' ) )->getMethod( 'extract_stock' );
dolisync_test_assert_same( null, $stock_method->invoke( null, array( 'stock_reel' => 9 ), 7 ), 'No debe convertir en cero una respuesta sin detalle por almacén.' );
dolisync_test_assert_same( null, $stock_method->invoke( null, array( 'stock_reel' => 9, 'stock_warehouse' => array() ), 7 ), 'Un total positivo sin desglose por almacén es una respuesta incompleta.' );
dolisync_test_assert_same( 0.0, $stock_method->invoke( null, array( 'stock_reel' => 0, 'stock_warehouse' => array() ), 7 ), 'Un desglose vacío con total cero representa stock cero.' );
dolisync_test_assert_same( 5.0, $stock_method->invoke( null, array( 'stock_warehouse' => array( 7 => array( 'id' => 91, 'real' => '5' ) ) ), 7 ), 'Debe leer el almacén desde la clave del mapa de Dolibarr.' );
dolisync_test_assert_same( 6.0, $stock_method->invoke( null, array( 'stock_warehouse' => array( array( 'id' => 7, 'warehouse_id' => 9, 'real' => '6' ) ) ), 9 ), 'No debe confundir el ID de product_stock con el ID de almacén.' );
dolisync_test_assert_same( 0.0, $stock_method->invoke( null, array( 'stock_warehouse' => array( 8 => array( 'real' => '4' ) ) ), 7 ), 'Un almacén ausente en un desglose válido tiene stock cero.' );

$reverse_reflection = new ReflectionClass( 'Dolisync_Product_Sync_Reverse' );
$reverse_sync = $reverse_reflection->newInstanceWithoutConstructor();
$reverse_stock_method = $reverse_reflection->getMethod( 'get_dolibarr_warehouse_stock' );
$missing_stock_error = false;
try {
	$reverse_stock_method->invoke( $reverse_sync, array( 'stock_reel' => 9 ), 7 );
} catch ( RuntimeException $exception ) {
	$missing_stock_error = true;
}
dolisync_test_assert_same( true, $missing_stock_error, 'La exportación no debe calcular movimientos desde un desglose de almacenes ausente.' );
dolisync_test_assert_same(
	6.0,
	$reverse_stock_method->invoke( $reverse_sync, array( 'stock_warehouse' => array( array( 'id' => 7, 'warehouse_id' => 9, 'real' => '6' ) ) ), 9 ),
	'La exportación debe usar el ID de almacén explícito y no el ID de product_stock.'
);

$variation_method = $reverse_reflection->getMethod( 'select_created_dolibarr_variation' );
$features = array( 3 => 11, 4 => 12 );
$combinations = array(
	array(
		'id' => 41,
		'fk_product_child' => 900,
		'attributes' => array(
			array( 'fk_prod_attr' => 3, 'fk_prod_attr_val' => 11 ),
			array( 'fk_prod_attr' => 4, 'fk_prod_attr_val' => 12 ),
		),
	),
);
dolisync_test_assert_same(
	array( 'combination_id' => 41, 'child_id' => 900 ),
	$variation_method->invoke( $reverse_sync, $combinations, 900, $features ),
	'Debe interpretar la respuesta de creación de Dolibarr como ID de producto hijo.'
);
dolisync_test_assert_same(
	array( 'combination_id' => 41, 'child_id' => 900 ),
	$variation_method->invoke( $reverse_sync, $combinations, 41, $features ),
	'Debe mantener compatibilidad si una extensión devuelve el ID de combinación.'
);
dolisync_test_assert_same(
	array(),
	$variation_method->invoke( $reverse_sync, $combinations, 901, array( 3 => 99 ) ),
	'No debe vincular una combinación cuyos atributos no coinciden.'
);

class Dolisync_Test_WC_Variation_With_Inherited_SKU {
	public function get_sku( $context = 'view' ) {
		return 'edit' === $context ? '' : '80826';
	}
}

$own_sku_method = $reverse_reflection->getMethod( 'get_own_wc_variation_sku' );
dolisync_test_assert_same(
	'',
	$own_sku_method->invoke( $reverse_sync, new Dolisync_Test_WC_Variation_With_Inherited_SKU() ),
	'Una variación sin SKU propio no debe heredar 80826 del producto padre durante la exportación.'
);

class Dolisync_Test_Variation_API_Client {
	public $requests = array();
	public $ref_owner_id = 0;
	public $fail_all_barcode_updates = false;
	public $succeed_immediately = false;

	public function get( $endpoint, $params = array() ) {
		if ( $this->ref_owner_id > 0 ) {
			return array( 'success' => true, 'data' => array( array( 'id' => $this->ref_owner_id ) ) );
		}
		return array( 'success' => true, 'data' => array() );
	}

	public function put( $endpoint, $payload ) {
		$this->requests[] = array( 'endpoint' => $endpoint, 'payload' => $payload );
		if ( $this->succeed_immediately ) {
			return array( 'success' => true );
		}
		if ( $this->fail_all_barcode_updates || 1 === count( $this->requests ) ) {
			return array(
				'success' => false,
				'message' => 'Dolibarr ha devuelto un error temporal del servidor (500).',
				'api_message' => $this->fail_all_barcode_updates && isset( $payload['barcode'] )
					? 'Internal Server Error: Error updating product (ErrorBarCodeRequired)'
					: 'Internal Server Error: Error updating product (Error : The product barcode 040000000020 already exists on another product reference.)',
			);
		}
		return array( 'success' => true );
	}
}

$api_client = new Dolisync_Test_Variation_API_Client();
$api_client_property = $reverse_reflection->getProperty( 'api_client' );
$api_client_property->setValue( $reverse_sync, $api_client );
$variation_update_method = $reverse_reflection->getMethod( 'update_dolibarr_variation_product' );
$variation_update_method->invoke( $reverse_sync, 20, array( 'ref' => '80181', 'label' => 'Bailarina Baleo Marrón - 36' ) );
dolisync_test_assert_same( 2, count( $api_client->requests ), 'Debe reintentar una sola vez cuando Dolibarr detecta un barcode duplicado.' );
dolisync_test_assert_same( false, array_key_exists( 'barcode', $api_client->requests[0]['payload'] ), 'El primer PUT debe preservar el barcode existente.' );
dolisync_test_assert_same( 'auto', $api_client->requests[1]['payload']['barcode'] ?? null, 'El segundo PUT debe pedir a Dolibarr un nuevo barcode compatible con su configuración.' );
dolisync_test_assert_same( '/products/20', $api_client->requests[1]['endpoint'], 'La reparación debe aplicarse al producto hijo correcto.' );

$ref_conflict_client = new Dolisync_Test_Variation_API_Client();
$ref_conflict_client->ref_owner_id = 99;
$ref_conflict_client->succeed_immediately = true;
$api_client_property->setValue( $reverse_sync, $ref_conflict_client );
$variation_update_method->invoke( $reverse_sync, 20, array( 'ref' => '80181', 'label' => 'Bailarina Baleo Marrón - 36' ) );
dolisync_test_assert_same( false, array_key_exists( 'ref', $ref_conflict_client->requests[0]['payload'] ), 'No debe intentar asignar a la variante un SKU que ya pertenece a otro producto Dolibarr.' );

$persistent_barcode_client = new Dolisync_Test_Variation_API_Client();
$persistent_barcode_client->fail_all_barcode_updates = true;
$api_client_property->setValue( $reverse_sync, $persistent_barcode_client );
dolisync_test_assert_same(
	false,
	$variation_update_method->invoke( $reverse_sync, 20, array( 'label' => 'Bailarina Baleo Marrón - 36' ) ),
	'Un conflicto residual de barcode debe omitir los metadatos sin bloquear el stock ni la vinculación.'
);
dolisync_test_assert_same( true, Dolisync_API_Client::is_duplicate_barcode_error_message( 'The product barcode 040000000020 already exists on another product reference.' ), 'El cliente API debe clasificar el conflicto de barcode como error de validación.' );
dolisync_test_assert_same( true, Dolisync_API_Client::is_barcode_validation_error_message( 'ErrorBarCodeRequired' ), 'Un barcode obligatorio ausente también es una validación permanente y no debe reintentarse como 500 temporal.' );
dolisync_test_assert_same( false, Dolisync_API_Client::is_duplicate_barcode_error_message( 'Internal Server Error: database unavailable' ), 'Un 500 real debe seguir siendo reintentable.' );

echo "Critical variation and stock regression tests passed.\n";
