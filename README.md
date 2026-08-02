# DoliSync

**WooCommerce y Dolibarr conectados de principio a fin.**

DoliSync sincroniza productos, clientes y existencias, transforma los pedidos de
WooCommerce en facturas de cliente de Dolibarr y recupera el PDF fiscal para
adjuntarlo al correo del comprador.

![Versión](https://img.shields.io/badge/versi%C3%B3n-1.0.0-2563eb)
![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.1-777bb4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-%3E%3D%206.0-21759b?logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-%3E%3D%206.0-96588a&logo=woocommerce&logoColor=white)
![Licencia](https://img.shields.io/badge/licencia-GPL--3.0--or--later-16a34a)

> [!IMPORTANT]
> Verifica el flujo completo en preproducción antes de utilizarlo con pedidos
> reales. La numeración, validación, contabilización y remisión a VeriFactu
> también dependen de la configuración y los módulos activos en Dolibarr.

## Qué hace DoliSync

### Catálogo de productos

- Sincroniza productos de WooCommerce a Dolibarr y de Dolibarr a WooCommerce.
- Trabaja por páginas para manejar catálogos grandes.
- Mantiene relaciones persistentes entre los identificadores de ambos sistemas.
- Crea y actualiza productos simples, variables, categorías, atributos,
  variaciones, precios, descripciones e imágenes.
- Omite los registros que no han cambiado para reducir llamadas y escrituras.
- Marca fuera de venta en Dolibarr los productos no publicados u ocultos de
  WooCommerce.
- Mantiene publicados pero ocultos del catálogo de WooCommerce los productos
  que están fuera de venta en Dolibarr.

### Stock

- Dolibarr actúa como fuente de verdad para la sincronización rutinaria.
- Actualiza WooCommerce manualmente o mediante WP-Cron cada 5, 10, 30 o 60
  minutos.
- Solo modifica productos y variaciones previamente relacionados.
- Durante una exportación manual hacia Dolibarr, reconcilia por diferencia las
  variaciones que gestionan sus propias existencias.

El cron de stock siempre funciona en el sentido **Dolibarr → WooCommerce** y no
sobrescribe las existencias de Dolibarr.

### Clientes, pedidos y facturas

- Sincroniza el pedido cuando se crea en WooCommerce.
- Localiza o crea el tercero en Dolibarr con sus datos fiscales y postales.
- Valida DNI, NIE, CIF y pasaportes antes de asignarlos al tercero.
- Crea directamente una factura de cliente, sin un pedido comercial intermedio.
- Asigna los impuestos mediante un mapeo explícito de tasas.
- Valida la factura en Dolibarr y admite los tiempos de respuesta de VeriFactu.
- Marca la factura como pagada cuando el pedido pasa a `processing` o
  `completed`.
- Descarga el PDF fiscal, lo guarda de forma privada y lo adjunta al correo de
  WooCommerce.
- Programa nuevos intentos cuando el documento todavía no está disponible.

Cada pedido utiliza una referencia externa única. Reprocesar el mismo pedido
reutiliza su factura, mientras que dos compras distintas generan facturas
independientes aunque compartan cliente e importe.

### Seguridad y operación

- Cifrado de credenciales con AES-256-GCM y los secretos de WordPress.
- Nonces, comprobaciones de permisos, saneamiento de entradas y escape de
  salidas en las acciones administrativas.
- Consultas preparadas para el almacenamiento propio.
- Reintentos controlados ante respuestas `429` y errores temporales `5xx`.
- Tratamiento conservador de las operaciones de creación para evitar duplicados.
- Ocultación de credenciales, cabeceras sensibles y contenido binario en los
  registros.
- Compatibilidad declarada con WooCommerce HPOS.
- Soporte opcional para instalaciones protegidas con Cloudflare Access.

## Dirección de los datos

| Información | Dirección | Regla principal |
| --- | --- | --- |
| Productos y catálogo | WooCommerce ↔ Dolibarr | Creación y actualización paginadas |
| Imágenes | WooCommerce ↔ Dolibarr | Transferencia mediante la API de documentos |
| Stock rutinario | Dolibarr → WooCommerce | Dolibarr gobierna las existencias |
| Stock durante exportación manual | WooCommerce → Dolibarr | Ajuste diferencial cuando corresponde |
| Clientes y direcciones | WooCommerce → Dolibarr | Resolución o alta del tercero |
| Pedidos | WooCommerce → Dolibarr | Creación de factura de cliente |
| Estado pagado | WooCommerce → Dolibarr | Estados `processing` y `completed` |
| PDF fiscal | Dolibarr → WooCommerce | Descarga, almacenamiento y adjunto por correo |

## Flujo de una compra

1. WooCommerce crea el pedido y DoliSync reserva una identidad externa basada en
   su ID.
2. DoliSync localiza o crea el tercero en Dolibarr.
3. Traduce las líneas usando las relaciones de producto y el mapeo de impuestos.
4. Crea una factura con una referencia externa exclusiva del pedido.
5. Dolibarr valida la factura y ejecuta el proceso fiscal configurado.
6. Al pasar el pedido a `processing` o `completed`, DoliSync marca la factura
   como pagada.
7. El plugin recupera el PDF, lo almacena de forma privada y lo adjunta al correo
   del cliente.

## Requisitos

- WordPress 6.0 o superior.
- WooCommerce 6.0 o superior.
- PHP 8.1 o superior con OpenSSL habilitado.
- Dolibarr con la API REST activa y accesible desde WordPress.
- HTTPS válido en ambos sistemas para producción.
- Un almacén y una política de stock configurados en Dolibarr.
- Un modelo de factura PDF activo en Dolibarr.
- Correo saliente funcional en WordPress; se recomienda SMTP transaccional.

### Permisos de Dolibarr

Utiliza una clave perteneciente a un usuario técnico con los permisos mínimos
necesarios para:

- Consultar, crear y modificar terceros y direcciones.
- Consultar, crear y modificar productos, categorías y variantes.
- Consultar almacenes y existencias, y ajustar stock en los flujos autorizados.
- Consultar, crear, validar y marcar como pagadas facturas de clientes.
- Consultar, subir y descargar documentos de productos y facturas.

Evita usar la clave de un administrador global si un usuario de integración puede
cubrir estas operaciones.

## Instalación

1. Descarga la versión publicada de DoliSync.
2. Copia la carpeta `DoliSync` en `wp-content/plugins/` o instala su ZIP desde
   **Plugins → Añadir plugin → Subir plugin**.
3. Activa DoliSync.
4. Abre DoliSync en el panel de administración de WordPress.
5. Introduce la URL base de Dolibarr y la clave de API.
6. Comprueba la conexión.
7. Configura impuestos, almacén, frecuencia de stock y registros.
8. Sincroniza primero un conjunto pequeño y procesa una compra controlada antes
   de activar la automatización.

La URL debe apuntar a la instalación, sin añadir manualmente la ruta final de la
API:

```text
https://dolibarr.example.com
```

## Configuración esencial

### Conexión

- **URL de Dolibarr:** dirección HTTPS accesible desde el servidor de WordPress.
- **DOLAPIKEY:** clave del usuario técnico de integración.
- **Cloudflare Access:** identificador y secreto opcionales para instalaciones
  protegidas.
- **Comprobar conexión:** valida autenticación, acceso y respuesta de la API.

### Impuestos

Relaciona cada tasa de WooCommerce con su valor fiscal exacto en Dolibarr. Es
especialmente importante con VeriFactu: un valor calculado como `20.999…` no debe
sustituir a la tasa fiscal `21`.

Revisa el mapeo cuando cambies los impuestos en cualquiera de los sistemas.
Incluye descuentos, transporte y las distintas tasas que utilice la tienda en la
comprobación previa a producción.

### Automatización del stock

La frecuencia se selecciona desde DoliSync. Como WP-Cron depende del tráfico,
puedes invocarlo desde el programador del servidor para obtener una ejecución
predecible:

```cron
*/5 * * * * curl -fsS "https://tienda.example.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

Si defines `DISABLE_WP_CRON`, confirma antes que la tarea del sistema funciona.

### Registros

DoliSync separa la actividad funcional del tráfico de API. El nivel y la
retención son configurables. En producción, utiliza el menor detalle que permita
operar la integración y conserva los registros solo durante el tiempo necesario.

Nunca compartas registros sin revisar antes que no contengan datos personales,
documentos fiscales ni información sensible.

## Persistencia

El plugin crea tablas propias usando el prefijo configurado en WordPress:

| Tabla | Finalidad |
| --- | --- |
| `dolisync_config` | Configuración cifrada y preferencias |
| `dolisync_logs` | Tráfico de API saneado |
| `dolisync_actions` | Historial funcional y resúmenes |
| `dolisync_error_stats` | Estadísticas de errores operativos |
| `dolisync_contact_relations` | Relación entre clientes y terceros |
| `dolisync_product_relations` | Relación entre productos |
| `dolisync_product_variation_relations` | Relación entre variaciones |
| `dolisync_product_category_mappings` | Mapeo de categorías |
| `dolisync_product_category_relations` | Relaciones auxiliares de categorías |
| `dolisync_order_relations` | Relación entre pedidos y facturas |

Estas relaciones son la fuente principal de identidad. Las coincidencias por
referencia, SKU o nombre ayudan a enlazar registros existentes, pero no sustituyen
la relación persistente una vez creada.

## Diagnóstico

| Síntoma | Qué revisar |
| --- | --- |
| La API responde `401` o `403` | Clave, permisos y cabeceras de Cloudflare Access |
| Aparecen respuestas `429` | Frecuencia, tamaño de página y reintentos registrados |
| Dolibarr devuelve `5xx` | Registro de Dolibarr y detalle saneado en DoliSync |
| La factura queda provisional | Permisos, numeración, impuestos y VeriFactu |
| No llega el PDF | Modelo documental, cron, reintentos y correo de WordPress |
| El stock no cambia | Relación del producto, almacén y tarea programada |
| Un producto se omite | Puede estar relacionado y no presentar diferencias |
| Una imagen devuelve `404` | Nombre y referencia documental devueltos por Dolibarr |

Para investigar una incidencia, comienza por el historial de acciones y consulta
después la solicitud API relacionada. No mantengas el registro detallado activo
en una tienda con mucho tráfico.

## Consideraciones importantes

- Marcar una factura como pagada no crea un movimiento bancario ni concilia una
  cuenta en Dolibarr.
- La entrega del PDF depende también de la configuración de correo de WordPress.
- Las tareas automáticas no sincronizan el catálogo completo: el cron rutinario
  actualiza el stock de productos ya relacionados.
- Desinstalar el plugin puede eliminar sus tablas y configuración según la
  política elegida. Haz una copia de seguridad antes de eliminarlo.
- En una sincronización bidireccional debe estar claro qué sistema gobierna cada
  dato. Para el stock rutinario, ese sistema es Dolibarr.

## Comprobación antes de producción

- [ ] WordPress, WooCommerce, PHP y Dolibarr cumplen los requisitos.
- [ ] Todo el tráfico utiliza HTTPS con certificados válidos.
- [ ] La clave pertenece a un usuario técnico con permisos mínimos.
- [ ] La comprobación de conexión finaliza correctamente.
- [ ] Las tasas de impuestos están mapeadas de forma exacta.
- [ ] El almacén, el stock y los productos están relacionados correctamente.
- [ ] Una compra controlada crea y valida una factura independiente.
- [ ] VeriFactu acepta la factura y el PDF contiene el QR esperado.
- [ ] El cambio de estado marca la factura como pagada correctamente.
- [ ] El PDF se adjunta y llega al correo del cliente.
- [ ] WP-Cron o el cron del sistema ejecuta las tareas pendientes.
- [ ] El nivel y la retención de registros son adecuados.
- [ ] Existe una copia de seguridad y un procedimiento de reversión.

## Licencia

DoliSync se distribuye bajo la licencia
[GPL-3.0-or-later](https://www.gnu.org/licenses/gpl-3.0.html). Consulta [LICENSE](LICENSE)
para ver el texto completo.

## Incidencias

Al comunicar una incidencia, incluye las versiones de WordPress, WooCommerce, PHP
y Dolibarr, una descripción reproducible y los registros relevantes ya saneados.
No publiques claves de API, documentos fiscales ni datos personales.

[Abrir una incidencia](https://github.com/luiscaro6/DoliSync/issues)
