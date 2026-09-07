<?php
/**
 * Pruebas unitarias ligeras de los transformadores del panel de inicio.
 *
 * Ejecutar con: php tests/unit/dashboard-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

function add_action( $hook, $callback ) { $GLOBALS['dolisync_dashboard_test_actions'][ $hook ] = $callback; }
function __( $text ) { return $text; }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }

require_once dirname( __DIR__, 2 ) . '/includes/admin/class-dolisync-dashboard-page.php';

function dashboard_call_private( $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( Dolisync_Dashboard_Page::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
}

function dashboard_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "ERROR: %s\nEsperado: %s\nRecibido: %s\n", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
}

dashboard_assert_same( true, isset( $GLOBALS['dolisync_dashboard_test_actions']['wp_ajax_dolisync_dashboard_data'] ), 'Debe registrar el endpoint AJAX de los datos locales.' );
dashboard_assert_same( true, isset( $GLOBALS['dolisync_dashboard_test_actions']['wp_ajax_dolisync_dashboard_remote_status'] ), 'Debe registrar el endpoint AJAX de estado remoto.' );

dashboard_assert_same(
	'21.0.1',
	dashboard_call_private( 'extract_dolibarr_version', array( array( 'success' => array( 'code' => 200, 'dolibarr_version' => '21.0.1' ) ) ) ),
	'Debe extraer la versión de la respuesta /status habitual.'
);

dashboard_assert_same(
	'20.0.4',
	dashboard_call_private( 'extract_dolibarr_version', array( (object) array( 'dolibarr_version' => '20.0.4' ) ) ),
	'Debe admitir la versión en la raíz de una respuesta convertida desde objeto.'
);

dashboard_assert_same( 75, dashboard_call_private( 'coverage', array( 3, 4 ) ), 'Debe calcular el porcentaje de cobertura.' );
dashboard_assert_same( 100, dashboard_call_private( 'coverage', array( 9, 4 ) ), 'La cobertura nunca debe superar el 100 %.' );
dashboard_assert_same( 0, dashboard_call_private( 'coverage', array( 0, 0 ) ), 'Un catálogo vacío debe devolver cobertura cero.' );
dashboard_assert_same( 'Cada 10 minutos', dashboard_call_private( 'interval_label', array( 'm10' ) ), 'Debe mostrar una frecuencia legible.' );
dashboard_assert_same( 'Sincronización manual', dashboard_call_private( 'humanize_action', array( 'sincronización_manual' ) ), 'Debe presentar las acciones sin guiones bajos.' );

echo "OK: dashboard-test.php\n";
