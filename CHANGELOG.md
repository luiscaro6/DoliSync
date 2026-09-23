# Changelog

Todos los cambios relevantes de DoliSync se documentan en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el proyecto utiliza [versionado semántico](https://semver.org/lang/es/).

## [1.2.2] - 2026-09-23

### Corregido

- El panel de productos procesa un lote incremental autenticado como respaldo
  cuando WP-Cron o las peticiones loopback del servidor no ejecutan la cola.
- El botón de actualización reactiva inmediatamente una primera carga detenida
  y no encadena innecesariamente una segunda reconstrucción completa.
- Las lecturas de caché hacia Dolibarr tienen un tiempo máximo acotado y un solo
  intento, evitando bloquear la petición de respaldo durante varios minutos.
- El aviso de caché muestra la fase y el lote que se están procesando, además de
  indicar cuándo el respaldo del panel sustituye a un WP-Cron desactivado.

## [1.2.1] - 2026-09-23

### Corregido

- La caché de Dolibarr guarda todas las combinaciones de un producto variable
  antes de enriquecer sus productos hijo, por lo que ya no aparecen sin
  variaciones mientras se completa la actualización incremental.
- Un fallo aislado al consultar una combinación o variante queda registrado
  como aviso recuperable y no bloquea indefinidamente la primera carga; una
  generación ya atascada con la versión anterior se reanuda automáticamente.
- El aviso de primera carga se retira al finalizar y su seguimiento utiliza una
  consulta ligera de estado, sin reconstruir el catálogo completo cada cinco
  segundos ni detenerse prematuramente en catálogos grandes.

## [1.2.0] - 2026-09-23

### Añadido

- Caché persistente de los catálogos WooCommerce y Dolibarr para que el panel
  de productos y sus simulaciones no consulten la API durante cada carga.
- Reconstrucción incremental en segundo plano, con lotes pequeños, bloqueo,
  reintentos y conservación de la generación anterior hasta completar la nueva.
- Actualización inmediata de la caché local al editar productos o variaciones de
  WooCommerce y al sincronizar stock.

### Corregido

- La importación Dolibarr → WooCommerce admite listas de variaciones planas,
  indexadas por ID o envueltas en `data`, y desempaqueta también el producto hijo.
- Se impide crear variaciones WooCommerce sin atributos resolubles, evitando
  registros incompletos cuando Dolibarr devuelve una combinación inconsistente.
- Una respuesta de combinaciones vacía o incompleta ya no puede borrar las
  variaciones WooCommerce de un producto que se sabe variable.

## [1.1.0] - 2026-09-08

### Añadido

- Nuevo panel visual de inicio, situado encima de Clientes, con estado y endpoint
  de conexión, versión de Dolibarr y accesos directos a las áreas principales.
- Indicadores de productos, variantes, clientes, pedidos y cobertura de sus
  relaciones con Dolibarr.
- Resumen de la última sincronización de stock, próxima ejecución, almacén,
  incidencias operativas, latencia de API y actividad reciente.
- Actualización manual del panel con caché breve para evitar comprobaciones
  remotas innecesarias en cada visita.

## [1.0.1] - 2026-09-07

### Añadido

- Asistente paginado para propagar a las variantes ya sincronizadas las categorías
  asignadas a sus productos padre y hacerlas visibles en TakePOS.

### Corregido

- Las nuevas variantes de Dolibarr reciben explícitamente las categorías del
  producto padre, ya que Dolibarr no las hereda al crear la combinación.

## [1.0.0] - 2026-08-07

### Añadido

- Nuevo asistente de bienvenida y reconfiguración para guiar la puesta en marcha
  de la conexión con Dolibarr.
- Detección centralizada de conflictos de identidad de clientes y productos.
- Pantallas de conflictos para revisar coincidencias ambiguas, relaciones rotas y
  elegir manualmente qué sistema debe conservarse.
- Persistencia específica para conflictos de contactos y productos, integrada en
  las migraciones y en la desinstalación.
- Cola asíncrona de pedidos y facturación para desacoplar las llamadas a Dolibarr
  del proceso de checkout.
- Reintentos automáticos, recuperación de trabajos bloqueados y respaldo mediante
  WP-Cron cuando Action Scheduler no está disponible.
- Sincronización manual inmediata de pedidos con información de progreso en tiempo
  real.
- Envío independiente de facturas por correo e historial de emails por pedido con
  fecha y estado.
- Notificación al cliente cuando el PDF de la factura todavía no está disponible.
- Sistema de migraciones versionadas, idempotentes y con bloqueo para proteger las
  actualizaciones simultáneas del esquema.
- Pestaña de salud con errores recientes, latencia de la API, trabajos fallidos y
  posibles trabajos bloqueados.
- Informe técnico anonimizado para diagnóstico, sin credenciales ni datos de
  clientes, pedidos o cuerpos de registro.
- Identificadores de correlación compartidos entre llamadas API y acciones de una
  misma ejecución.
- Simulación bidireccional de productos y clientes antes de aplicar cambios.
- Vista previa detallada de altas, actualizaciones y campos que se modificarán.
- Procesamiento individual por fila o secuencial de todos los cambios pendientes,
  con progreso, resultado y gestión de errores.
- Sincronización individual de clientes desde Dolibarr.
- Comparación ampliada de productos: precio, stock, tipo y variaciones.
- Comparación ampliada de clientes: nombre, email, documento fiscal y país.

### Cambiado

- La sincronización de pedidos reutiliza el nuevo sistema centralizado de identidad
  para evitar asociaciones inseguras.
- Los cambios de estado de pedidos y facturas se sincronizan de forma más robusta.
- Los emails y las vistas de pedido muestran los datos actuales del cliente.
- La simulación anterior, basada únicamente en contadores, se sustituye por una
  interfaz detallada y accionable.
- Se actualizan las pantallas administrativas, los estilos y la documentación para
  reflejar los nuevos flujos operativos.
- El esquema de base de datos evoluciona de forma independiente a la versión
  pública del plugin.

### Corregido

- Se evitan vinculaciones automáticas cuando existen varias coincidencias posibles
  o una relación persistida ha dejado de ser válida.
- Se evita sobrescribir terceros de Dolibarr con datos históricos almacenados en
  pedidos antiguos.
- Se evitan facturas y correos duplicados durante los reintentos de sincronización.
- Se refuerzan la validación, descarga y conservación privada de los PDF fiscales.
- Se corrigen errores al recibir resultados nulos durante las comprobaciones del
  esquema.
- Se mejora el tratamiento de errores por fila durante la simulación y la
  sincronización de productos y clientes.

### Seguridad y mantenimiento

- Se limpian eventos programados y bloqueos al desactivar o desinstalar el plugin.
- Los conflictos y las nuevas estructuras de diagnóstico se incluyen en la política
  de limpieza de la desinstalación.
- Las operaciones de identidad adoptan un comportamiento conservador para reducir
  el riesgo de enlazar registros incorrectos.

## [1.0.0-rc1] - 2026-08-02

### Añadido

- Primera versión pública de DoliSync.
- Sincronización bidireccional de productos, categorías, atributos, variaciones,
  imágenes y clientes entre WooCommerce y Dolibarr.
- Sincronización de stock desde Dolibarr hacia WooCommerce y reconciliación de
  existencias durante las exportaciones manuales autorizadas.
- Creación y actualización de terceros de Dolibarr a partir de clientes y pedidos.
- Conversión de pedidos de WooCommerce en facturas de cliente de Dolibarr.
- Validación de facturas, marcado como pagadas y recuperación del PDF fiscal.
- Adjuntos de factura en los correos de WooCommerce.
- Relaciones persistentes entre los identificadores de ambos sistemas.
- Configuración de impuestos, almacén, frecuencia de sincronización y registros.
- Cifrado de credenciales, controles de permisos y saneamiento de registros.
- Compatibilidad con WooCommerce HPOS y soporte opcional para Cloudflare Access.

[1.2.2]: https://github.com/luiscaro6/DoliSync/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/luiscaro6/DoliSync/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/luiscaro6/DoliSync/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/luiscaro6/DoliSync/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/luiscaro6/DoliSync/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/luiscaro6/DoliSync/compare/v1.0.0-rc1...v1.0.0
[1.0.0-rc1]: https://github.com/luiscaro6/DoliSync/releases/tag/v1.0.0-rc1
