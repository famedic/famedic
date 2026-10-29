# Evaluación técnica de integraciones de laboratorios

Fecha: 2026-10-27. Checkout analizado: rama `main`, commit `7ff6760505dbca0455398d1a241a103dab994bd2`. No se encontró `AGENTS.md`. Revisión local de código, migraciones, configuración sin valores sensibles, documentación y pruebas. No se ejecutaron migraciones, jobs, llamadas externas ni cambios al sistema.

## Resumen ejecutivo

- **Confirmado por el responsable (2026-10-28):** `olab`, `swisslab`, `jenner`, `liacsa` y `azteca` están activas en producción. El código también las declara (`app/Enums/LaboratoryBrand.php:7-14`); ambas evidencias se mantienen separadas.
- **Confirmado:** el conector técnico de resultados localizado es GDA, con configuración por marca/convenio/token (`config/services.php:90-152`; valores omitidos). Las marcas no equivalen a cinco APIs independientes.
- **Confirmado:** webhook `POST /api/laboratory/webhook/notifications`, con payload FHIR-like y campos `header`, `id`, `requisition`, `status`, `code.coding`, `subject` (`routes/api.php:20-33`; `LaboratoryWebhookController.php:37-64`).
- **Confirmado por el responsable (2026-10-28):** la única modalidad actual de entrega de resultados por GDA es un PDF codificado en Base64 que FAMEDIC decodifica. El código implementa webhook y consulta posterior (`GetGDAResultsAction.php:18-31,186-205`), pero actualmente no se reciben resultados clínicos estructurados por otra modalidad. Esto describe el acuerdo actual de FAMEDIC, no las capacidades posibles de GDA fuera de ese acuerdo.
- **Confirmado:** PDF validado, almacenado y versionado; la ruta vigente queda en `laboratory_purchases.results` (`StoreGdaResultsPdfToStorageAction.php:21-69,85-102`; migraciones indicadas en la matriz).
- **Confirmado:** FAMEDIC extrae desde PDF analito, valor, unidad y referencia; calcula `abnormal_flag`, por lo que no debe tratarse como bandera original del laboratorio (`2026_09_19_100004_create_laboratory_result_observations_table.php:11-36`; servicio de extracción).
- **Confirmado como evidencia archivada:** existen payloads OLAB enviado/recibido en `docs/Payload-enviado-olab.txt` y `docs/Payload-respuesta-OLAB.txt`; la respuesta contiene `GDA_menssage` exitoso y `infogda_resultado_b64`. La procedencia operativa (producción/QA) no está documentada, por lo que no se presenta como prueba de producción.
- **Pendiente:** activación productiva, cobertura real por marca y documentación oficial del contrato.

## Inventario

| Elemento | Estado | Evidencia | Alcance |
|---|---|---|---|
| OLAB, Swisslab, Jenner, Liacsa, Azteca | activas en producción según confirmación del responsable; declaradas en aplicación | `app/Enums/LaboratoryBrand.php:7-14`; `config/services.php:123-152`; confirmación 2026-10-28 | La activación proviene de confirmación operativa, no del enum ni del respaldo. |
| GDA | confirmado como proveedor técnico localizado | `config/services.php:90-121`; `GetGDAResultsAction.php:282-338` | Atiende varias marcas mediante configuración. |
| Catálogo, órdenes, citas | confirmado en dominio local | `routes/laboratories.php`; migraciones de `laboratory_tests`, `laboratory_purchases`, `laboratory_appointments` | No demuestra que cada operación exista en la API del proveedor. |
| Resultados | confirmado: webhook + consulta posterior | rutas, acciones y jobs citados | Mecanismos coexistentes. |

### Separación marca / proveedor / integración / uso

| Marca comercial | Proveedor/sistema técnico localizado | Integración implementada | Activación/uso verificable en este checkout |
|---|---|---|---|
| OLAB | GDA | Configuración `brand_id`, `brand_agreement_id`, `token`; webhook y consulta de PDF compartidos | Activa en producción según confirmación del responsable; hay payload histórico archivado |
| Swisslab | GDA | Misma familia de configuración y flujo GDA | Activa en producción según confirmación del responsable |
| Jenner | GDA | Misma familia de configuración y flujo GDA | Activa en producción según confirmación del responsable |
| Liacsa | GDA | Misma familia de configuración y flujo GDA | Activa en producción según confirmación del responsable |
| Azteca | GDA | Misma familia de configuración y flujo GDA | Activa en producción según confirmación del responsable |

La configuración de `services.gda.brands` distingue credenciales/convenio por marca. La confirmación de activación productiva proviene del responsable; el enum y la configuración por sí solos no probarían utilización.

### Funcionalidades localizadas

Catálogo, marcas, estudios, sucursales, citas, carrito/órdenes y consultas de resultados tienen implementación local en `routes/laboratories.php`, `routes/web.php`, `routes/admin.php` y las tablas `laboratory_tests`, `laboratory_stores`, `laboratory_appointments`, `laboratory_purchases`. El webhook GDA cubre notificaciones de toma de muestra, resultados y estados. Esto demuestra capacidad implementada en FAMEDIC; no demuestra que cada operación sea soportada o usada por cada marca.

## Flujo de resultados

1. El webhook valida y pasa el payload a `FindReferencesAction`, `CreateNotificationAction` y `ProcessNotificationAction` (`LaboratoryWebhookController.php:83-91`).
2. Si llega `infogda_resultado_b64`, se decodifica, valida con firma `%PDF`, se almacena y se registra una versión (`HandleResultsNotificationAction.php:47-68`; `StoreGdaResultsPdfToStorageAction.php:30-69`).
3. Si no llega PDF o se requiere refresh, `SyncGdaResultPdfToStorageJob` consulta GDA y reintenta cuando todavía no hay resultados (`SyncGdaResultPdfToStorageJob.php:16-63`; `SyncGdaResultPdfToStorageAction.php:21-33,117-127`).
4. `laboratory_purchases.results` conserva la ruta vigente; `laboratory_result_versions` conserva ruta, SHA-256, fuente y clasificación (`2024_11_09_221516_create_laboratory_purchases_table.php:14-35`; `2026_09_16_120100_create_laboratory_result_versions_table.php:11-37`).
5. La extracción estructurada es opcional y posterior al PDF (`ExtractLaboratoryResultReportJob.php:12-35`); reportes/observaciones tienen estado, versión, errores y posibilidad de publicación.

## Campos disponibles para IA

Cuando la extracción es exitosa, el modelo local puede recibir analito bruto/canónico, valor numérico o textual, unidad, referencia textual o límites, estado calculado, confianza, página y regla. Las fechas de reporte y toma de muestra existen en el esquema, pero no se verificó que estén presentes ni que todas provengan del laboratorio (`2026_09_19_100003_create_laboratory_result_reports_table.php:11-30`).

La documentación interna localizada (`docs/laboratory-stores/gda-import.md`) es de directorio/importación, no un contrato oficial de resultados. No se encontró OpenAPI, WSDL, esquema XML o manual oficial.

## Evidencia de payloads persistidos y muestras anonimizadas

- Fuente: laboratory_notifications.payload. El código aplica GdaPayloadSanitizer::sanitize al webhook recibido: elimina claves Base64 pesadas, por lo que este campo no es el original íntegro. No se imprimieron originales.
- Tipo: las cuatro muestras nuevas son webhooks de resultados persistidos; no son respuestas HTTP de consulta GDA.
- Etiqueta: "Payload persistido en respaldo local declarado de producción por el responsable; fecha del respaldo y origen externo no verificados independientemente".
- Muestras anonimizadas: anexos/payloads-notificaciones/olab-notificacion-resultados.json, swisslab-notificacion-resultados.json, jenner-notificacion-resultados.json y azteca-notificacion-resultados.json.
- Contienen referencias anonimizadas de paciente/orden, fecha del payload y estudio/panel. No contienen analitos, valor, unidad, rango ni bandera en las rutas inspeccionadas. El Base64 del webhook está ausente por sanitización; no se descargó ni extrajo PDF.
- Liacsa: se observaron 14 notificaciones históricas fuera de la ventana de 30 días, entre 2026-06-24 y 2026-09-08. La actividad histórica no demuestra desactivación ni estado actual.
- No se localizó una respuesta original de consulta GDA persistida por separado. laboratory_purchases.gda_response se asigna desde el evento de webhook en el flujo inspeccionado; no se presenta como respuesta de consulta.
- Matriz por muestra: anexos/matriz-muestras-notificaciones.json. Índice y sustituciones: anexos/indice-muestras-notificaciones.json.
## Muestras

Hay dos clases de evidencia:

1. `app/Http/Controllers/Laboratory/LaboratoryWebhookController.php:160-253`, simuladores y fixtures: payloads `TEST-*`, token ficticio y PDF sintético. Son evidencia de pruebas, no respuestas reales.
2. `docs/Payload-enviado-olab.txt` y `docs/Payload-respuesta-OLAB.txt`: captura archivada con datos concretos de OLAB/GDA, respuesta `codeHttp: 200` y `infogda_resultado_b64` que inicia como PDF. El archivo no aporta metadata de ambiente, autorización ni cadena de custodia; por eso el anexo se etiqueta como **captura archivada, procedencia operativa no verificada**, no como muestra real de producción.

El anexo `anexos/muestra-olab-respuesta-anonimizada.json` conserva la estructura y tipos relevantes, pero sustituye token, identificadores, folios, datos de paciente y Base64 por marcadores. No contiene analitos: el PDF recibido es un documento binario, no JSON clínico estructurado.

No se encontró XML, WSDL/XSD, OpenAPI del proveedor GDA ni contrato oficial de resultados. La documentación `docs/GDA/Notas.md` solo registra una URL histórica de cotizaciones y no debe usarse como contrato vigente.

## API externa, rutas internas y API V1

- **Externa GDA:** `GetGDAResultsAction` prepara una consulta con el payload de la notificación, sustituye el token por configuración de marca/convenio y consulta `results_consult_url` o el path legacy configurado. El código no permite afirmar el endpoint final sin leer configuración de entorno, que no se reproduce aquí.
- **Interna webhook:** `POST /api/laboratory/webhook/notifications`; `GET /api/laboratory/webhook/health`; `POST /api/laboratory/webhook/test` (`routes/api.php:20-33`). Es la entrada de notificaciones GDA, no la API V1 para terceros.
- **Interna web:** `/lab-results/{token}`, `/lab-results/{token}/pdf`, `/laboratory-purchases/{id}/results` y `/laboratory-purchases/{id}/download-pdf` entregan consulta/descarga al paciente o a la web.
- **API V1 para terceros/Akubica:** la documentación `docs/Akubica/inventario-endpoints-api-v1.md` y `docs/Akubica/akubica-sprint5-orders-results-invoices-delivery.md` describen endpoints `/api/v1/orders/results`, `/api/v1/orders/{id}/results` y descarga; su contrato expone metadata/disponibilidad y PDF, no campos clínicos nativos de GDA. La extracción estructurada es una capa posterior de FAMEDIC.

Autenticación: el webhook no muestra en su ruta una autenticación equivalente a Bearer; el payload contiene un token GDA que se inyecta para consultas. La API V1 usa el esquema documentado de Bearer/Sanctum. No se reproducen secretos.

## Conclusión

Existe una ruta técnica para IA, pero el insumo principal disponible es el PDF recibido/consultado mediante GDA y extraído por FAMEDIC. La implementación no demuestra que la extracción esté habilitada ni operando en producción. Antes de interpretar resultados deben confirmarse marcas/convenios activos, contrato de campos, muestras reales anonimizadas, fechas, banderas originales y métricas de extracción/revisión.

Conclusiones específicas: no se verificó que GDA entregue analitos, valores, unidades, rangos o banderas como campos JSON; esos campos aparecen en `laboratory_result_observations` después de extracción del PDF. `abnormal_flag` es calculado por FAMEDIC a partir de la referencia, con `abnormal_source=Computed`; no es una bandera nativa confirmada del proveedor. Paciente, compra, orden GDA y documento se relacionan mediante `laboratory_purchases`, `laboratory_notifications`, `gda_order_id`, `gda_external_id`, `gda_acuse`, `results` y `laboratory_result_versions`. La clasificación `complete`/`pending_interpretation`/`unknown` existe para el documento PDF, pero no es garantía de que todos los estudios o analitos del pedido estén presentes. Las fechas internas de recepción, almacenamiento, clasificación y extracción no deben presentarse como fechas clínicas de toma o liberación; estas últimas solo tienen columnas potenciales en el reporte y requieren evidencia del proveedor/PDF.

La extracción de texto está implementada en `LaboratoryResultTextExtractor.php:16-77` con `Smalot\\PdfParser`; la ruta principal parsea candidatos con `LaboratoryResultTextParser.php`. La ruta Vision usa render/crops y un extractor IA, pero depende de `vision_extraction.enabled` y de sus gates. El cálculo determinístico devuelve `unknown` cuando faltan límites, el valor no es numérico, la referencia no es interpretable o la unidad es incompatible; `qualitative` y `comment` son `not_applicable`. Los operadores `<`, `<=`, `>` y `>=` se reconocen en `LaboratoryReferenceNormalizer.php:20-57`. Estos comportamientos están cubiertos por pruebas unitarias y de feature; no se observaron métricas operativas en este checkout. La confirmación operativa actual indica que los analitos, valores, unidades, rangos y banderas se obtienen por extracción del PDF; `abnormal_flag` es calculado por FAMEDIC y no una bandera nativa estructurada de GDA.

La validación operativa local está separada en `validacion-operativa-local-2026-10-27.md`: confirma configuración local Docker, esquema compatible y actividad histórica agregada. No representa el estado actual de producción.
