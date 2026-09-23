<?php
/** Pruebas de normalización de la caché incremental de productos. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }

class Dolisync_Catalog_Cache_Test_DB {
	public $prefix = 'wp_';
	public function prepare( $query, ...$values ) { return $query; }
	public function get_row() { return null; }
}
$GLOBALS['wpdb'] = new Dolisync_Catalog_Cache_Test_DB();

class Dolisync_Config {
	public static $warehouse_id = 0;
	public static function get_warehouse_id() { return self::$warehouse_id; }
}

require_once dirname( __DIR__, 2 ) . '/includes/cache/class-dolisync-product-catalog-cache.php';

function catalog_cache_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nEsperado: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

$reflection = new ReflectionClass( 'Dolisync_Product_Catalog_Cache' );
$normalize_list = $reflection->getMethod( 'normalize_list' );
$attributes = $reflection->getMethod( 'combination_attributes' );
$stock = $reflection->getMethod( 'dolibarr_stock' );
$build_variations = $reflection->getMethod( 'build_cached_variations' );
$replace_variation = $reflection->getMethod( 'replace_cached_variation' );

$wrapped = $normalize_list->invoke( null, array( 'data' => array( array( 'id' => 7 ), array( 'id' => 8 ) ), 'pagination' => array( 'page_count' => 1 ) ) );
catalog_cache_assert_same( array( 7, 8 ), array_column( $wrapped, 'id' ), 'Debe extraer una lista envuelta en data.' );

$mapped = $normalize_list->invoke( null, array( 41 => array( 'fk_product_child' => 900 ) ) );
catalog_cache_assert_same( 41, $mapped[0]['id'], 'Debe conservar como ID la clave de un mapa remoto.' );

$values = $attributes->invoke(
	null,
	array(
		'attributes' => array(
			'M',
			array( 'value' => 'Azul' ),
			array( 'attribute_value' => array( 'label' => 'Algodón' ) ),
		),
	)
);
catalog_cache_assert_same( array( 'M', 'Azul', 'Algodón' ), $values, 'Debe normalizar atributos escalares y estructurados sin generar avisos.' );

Dolisync_Config::$warehouse_id = 0;
catalog_cache_assert_same( 7.0, $stock->invoke( null, array( 'stock_warehouse' => array( array( 'real' => 3 ), array( 'stock_reel' => 4 ) ) ) ), 'Sin almacén configurado debe sumar el desglose disponible.' );
Dolisync_Config::$warehouse_id = 7;
catalog_cache_assert_same( 5.0, $stock->invoke( null, array( 'stock_warehouse' => array( 7 => array( 'real' => 5 ), 8 => array( 'real' => 9 ) ) ) ), 'Con almacén configurado debe conservar solo su stock.' );

$cached_variations = $build_variations->invoke(
	null,
	500,
	array(
		'tax_rate' => 21,
		'price_base_type' => 'HT',
		'variations' => array( array( 'id' => 900, 'sku' => 'DOL-900', 'effective_sku' => 'DOL-900', 'sku_generated' => false, 'name' => 'Anterior', 'price' => '12.00', 'stock' => 3.0, 'attributes' => array( 'M' ) ) ),
	),
	array(
		array( 'id' => 41, 'fk_product_child' => 900, 'attributes' => array( array( 'value' => 'M' ) ) ),
		array( 'id' => 42, 'product_child_id' => 901, 'attributes' => array( array( 'value' => 'L' ) ) ),
	),
	array()
);
catalog_cache_assert_same( array( 900, 901 ), array_column( $cached_variations, 'id' ), 'Debe crear y cachear todas las variaciones desde las combinaciones antes de leer cada hijo.' );
catalog_cache_assert_same( 'DOL-900', $cached_variations[0]['sku'], 'Debe conservar los datos completos ya cacheados al reconstruir los marcadores.' );
catalog_cache_assert_same( array( 'L' ), $cached_variations[1]['attributes'], 'El marcador debe incluir los atributos presentes en la combinación.' );

$replaced = $replace_variation->invoke( null, $cached_variations, array( 'id' => 901, 'sku' => 'DOL-901' ) );
catalog_cache_assert_same( 2, count( $replaced ), 'Enriquecer una variación no debe duplicarla en la caché.' );
catalog_cache_assert_same( 'DOL-901', $replaced[1]['sku'], 'El detalle remoto debe reemplazar el marcador de la misma variación.' );

echo "Product catalog cache normalization tests passed.\n";
