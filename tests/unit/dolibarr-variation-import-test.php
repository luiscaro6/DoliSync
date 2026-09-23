<?php
/** Regresión del importador de variaciones Dolibarr → WooCommerce. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DOLISYNC_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

function __( $text ) { return $text; }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function sanitize_title( $text ) {
	$text = strtolower( trim( (string) $text ) );
	return trim( preg_replace( '/[^a-z0-9]+/', '-', $text ), '-' );
}
function wp_json_encode( $value ) { return json_encode( $value ); }

require_once dirname( __DIR__, 2 ) . '/includes/sync/products/class-dolisync-product-sync.php';

function variation_import_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nEsperado: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

class Dolisync_Test_Dolibarr_Variation_Client {
	public function get( $endpoint, $params = array() ) {
		switch ( $endpoint ) {
			case '/products/500/variants':
				// Algunas instalaciones/extensiones envuelven incluso esta lista en data.
				return array(
					'success' => true,
					'data' => array(
						'data' => array(
							array(
								'id' => 41,
								'product_child_id' => 900,
								'attributes' => array( array( 'fk_prod_attr' => 3, 'fk_prod_attr_val' => 11 ) ),
							),
						),
					),
				);
			case '/products/900':
				return array( 'success' => true, 'data' => array( 'data' => array( 'id' => 900, 'ref' => 'CAMISETA-M', 'label' => 'Camiseta M', 'price' => '12.50', 'stock_reel' => 7 ) ) );
			case '/products/attributes/3':
				return array( 'success' => true, 'data' => array( 'data' => array( 'label' => 'Talla' ) ) );
			case '/products/attributes/values/11':
				return array( 'success' => true, 'data' => array( 'data' => array( 'value' => 'M' ) ) );
		}
		return array( 'success' => false, 'message' => 'Endpoint inesperado: ' . $endpoint );
	}
}

$reflection = new ReflectionClass( 'Dolisync_Product_Sync' );
$sync = $reflection->newInstanceWithoutConstructor();
$api_property = $reflection->getProperty( 'api_client' );
$api_property->setValue( $sync, new Dolisync_Test_Dolibarr_Variation_Client() );

$fetch = $reflection->getMethod( 'fetch_dolibarr_variants' );
$variations = $fetch->invoke( $sync, 500 );
variation_import_assert_same( 1, count( $variations ), 'Debe desempaquetar la respuesta data sin confundirla con una combinación.' );
variation_import_assert_same( 900, $variations[0]['id'], 'Debe usar como ID remoto el producto hijo, no el ID de combinación.' );
variation_import_assert_same( 41, $variations[0]['combination_id'], 'Debe conservar también el ID de combinación.' );
variation_import_assert_same( array( 'talla' => 'M' ), $variations[0]['attributes'], 'Debe resolver los atributos antes de crear la variación WooCommerce.' );
variation_import_assert_same( 'CAMISETA-M', $variations[0]['ref'], 'Debe desempaquetar también el detalle del producto hijo.' );

$normalize_list = $reflection->getMethod( 'normalize_api_list' );
$mapped = $normalize_list->invoke( $sync, array( 41 => array( 'fk_product_child' => 900 ) ) );
variation_import_assert_same( 41, $mapped[0]['id'], 'Debe conservar el ID de combinación cuando Dolibarr devuelve un mapa indexado.' );

class Dolisync_Test_Dolibarr_Variation_Without_Attributes_Client extends Dolisync_Test_Dolibarr_Variation_Client {
	public function get( $endpoint, $params = array() ) {
		if ( '/products/500/variants' === $endpoint ) {
			return array( 'success' => true, 'data' => array( array( 'id' => 42, 'fk_product_child' => 901, 'attributes' => array() ) ) );
		}
		if ( '/products/901' === $endpoint ) {
			return array( 'success' => true, 'data' => array( 'id' => 901, 'ref' => 'CAMISETA-S' ) );
		}
		return parent::get( $endpoint, $params );
	}
}

$api_property->setValue( $sync, new Dolisync_Test_Dolibarr_Variation_Without_Attributes_Client() );
$rejected = false;
try {
	$fetch->invoke( $sync, 500 );
} catch ( RuntimeException $error ) {
	$rejected = false !== strpos( $error->getMessage(), 'atributos resolubles' );
}
variation_import_assert_same( true, $rejected, 'Debe detener la importación antes de crear una variación WooCommerce sin atributos.' );

$assert_safe = $reflection->getMethod( 'assert_variations_are_safe' );
$protected_from_empty_list = false;
try {
	$assert_safe->invoke( $sync, 500, array(), true );
} catch ( RuntimeException $error ) {
	$protected_from_empty_list = false !== strpos( $error->getMessage(), 'no borrar variaciones' );
}
variation_import_assert_same( true, $protected_from_empty_list, 'Una respuesta vacía no debe borrar las variaciones WooCommerce de un padre conocido.' );

echo "Dolibarr variation import regression tests passed.\n";
