# Pendientes de validación

1. Operaciones/Infraestructura debe confirmar marcas, convenios y endpoint activos en producción. El enum y la configuración solo prueban declaración.
2. Debe confirmarse la URL efectiva de consulta GDA, entorno y path, sin compartir tokens (`config/services.php:90-108`; `GetGDAResultsAction.php:326-338`).
3. Debe solicitarse a GDA el contrato vigente JSON/XML, autenticación, catálogo de marca/convenio, semántica de `id`, `requisition.value`, `infogda_orden`, `infogda_muestras` y `GDA_menssage.acuse`.
4. Debe solicitarse una respuesta real anonimizada por marca activa: resultados, sin resultados y múltiples analitos. No debe incluir tokens, URLs firmadas, base64 ni PDF identificable.
5. Deben confirmarse significados de fechas y si existen banderas clínicas originales. Hasta entonces `abnormal_flag` es cálculo FAMEDIC.
6. Deben medirse PDFs válidos/incompletos, extracción parcial, referencias no numéricas y revisión manual en producción.
7. Debe confirmarse `structured_extraction.enabled` y gates de publicación/consentimiento; el job no procesa con extracción desactivada (`ExtractLaboratoryResultReportJob.php:29-35`).
8. Debe definirse política para discrepancias entre PDF, extracción textual/visual y valores calculados.

No se pudo comprobar localmente activación productiva, volumen, procedencia real de payloads ni documentación oficial. No se contactó a terceros.
