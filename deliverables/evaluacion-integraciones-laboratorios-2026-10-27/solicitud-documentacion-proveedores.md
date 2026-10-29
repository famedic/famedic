# Solicitud concreta de documentación y muestras

Solicitar a GDA/laboratorios, por cada marca activa:

1. Contrato vigente de webhook y consulta de resultados: URLs por ambiente, método HTTP, headers, autenticación, códigos de error y límites.
2. Esquema JSON/XML o OpenAPI/WSDL/XSD, incluyendo `header`, `id`, `requisition`, `subject`, `code.coding`, `GDA_menssage`, `infogda_orden`, `infogda_muestras` e `infogda_resultado_b64`.
3. Diccionario de identificadores: paciente, orden, folio, estudio, muestra, convenio, marca y acuse; indicar qué identificador permite consultar el PDF.
4. Catálogo de fechas: orden, toma de muestra, procesamiento, liberación, notificación y hora/zona horaria.
5. Contrato de resultados clínicos estructurados, si existe: analito, valor, tipo, unidad, rango, bandera, crítico, comentarios, panel y estado de completitud.
6. Semántica de banderas y códigos; no aceptar abreviaturas sin diccionario.
7. Muestras autorizadas y anonimizadas: una notificación de toma, una de resultados sin PDF, una con PDF, un resultado parcial y uno con múltiples analitos; entregar JSON/XML sin tokens ni URLs firmadas y PDF separado sin datos identificables.

Solicitar al equipo interno:

- Confirmación de marca/convenio/endpoint activo en producción y fecha de última validación.
- Evidencia de activación y uso por marca: conteos, logs sanitizados o reporte de operación, sin credenciales ni PII.
- Mapeo entre `customer/user`, `laboratory_purchase`, `gda_order_id`, folio/consecutivo, notificación, versión PDF y reporte estructurado.
- Configuración efectiva de extracción, reglas de publicación, revisión manual y métricas de completitud.

Procedimiento seguro: obtener una muestra de sandbox o de un caso autorizado, exportarla desde el sistema fuente, eliminar PII/tokens/URLs firmadas/Base64, conservar un hash y metadatos de ambiente fuera del payload, y registrar quién autorizó la anonimización. No consultar producción ni crear órdenes para esta validación.
