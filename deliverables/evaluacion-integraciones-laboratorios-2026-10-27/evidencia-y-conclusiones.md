# Evidencia y conclusiones de la segunda revisión

| Afirmación | Evidencia | Qué demuestra | Qué no demuestra | Pendiente |
|---|---|---|---|---|
| Hay cinco marcas declaradas | `app/Enums/LaboratoryBrand.php:7-14` | Presencia en el dominio | Activación o uso actual | Confirmar con operación |
| GDA es el proveedor técnico localizado | `config/services.php:90-154`, `GetGDAResultsAction.php` | Configuración común con parámetros por marca/convenio | Contrato vigente o disponibilidad por marca | Confirmar con GDA/infra |
| Existe webhook de resultados | `routes/api.php:20-33`, `LaboratoryWebhookController.php:37-105` | Entrada interna para notificaciones GDA | Que cada marca lo use hoy | Conteos operativos |
| La notificación puede incluir PDF Base64 | `HandleResultsNotificationAction.php:47-68`, captura archivada OLAB | FAMEDIC reconoce y procesa ese campo | Que Base64 sea clínicamente estructurado | Muestra autorizada por ambiente |
| Hay consulta posterior a GDA | `GetGDAResultsAction.php:18-31,70-107` | Flujo de recuperación bajo demanda | Que esté habilitado en el ambiente consultado | Flag/configuración operativa |
| El PDF se valida, almacena y versiona | `StoreGdaResultsPdfToStorageAction.php:30-102`, migraciones de versiones | Persistencia local y metadatos | Que el objeto siga presente o sea completo | Conteo de storage |
| La extracción principal usa texto | `LaboratoryResultTextExtractor.php:16-77`, `LaboratoryResultTextParser.php` | Parseo de texto del PDF | Calidad en producción | Métricas y muestras |
| Vision/OCR-IA existe como ruta experimental | `config/laboratory-results.php:61-87`, `LaboratoryResultExtractionService.php` | Código y flags disponibles | Activación en producción | Flag por ambiente |
| Se extraen analito, valor, unidad y referencia | `LaboratoryResultObservation` migration, `observationAttributes` | Campos internos estructurados | Que sean campos nativos GDA | Contrato del proveedor |
| `abnormal_flag` es calculado | `LaboratoryResultExtractionService.php:625-682` | Se deriva del estado de referencia | Que equivalga a bandera clínica del laboratorio | Diccionario de banderas GDA |
| Falta de rangos produce `unknown` | `LaboratoryResultReferenceEvaluator.php:87-147`, pruebas unitarias | Evita evaluar sin límites interpretables | Que todo documento quede correctamente cubierto | Casos reales |
| `<`, `<=`, `>` y `>=` tienen reglas | `LaboratoryReferenceNormalizer.php:20-57`, pruebas | Operadores soportados | Semántica clínica del rango | Validación con proveedor |
| La extracción parcial/fallida/revisión se registra | `LaboratoryResultExtractionService.php:205-257,725-762`, enums/eventos | Estados y eventos internos | Que estén ocurriendo en producción | Reporte agregado |
| El job puede quedar bloqueado por flags | `ExtractLaboratoryResultReportJob.php:29-35`, config `structured_extraction.enabled` | Condición de despacho/procesamiento | Valor efectivo en producción | Comprobación autorizada |
| La captura OLAB es una captura archivada | `docs/Payload-*.txt`, metadata del archivo; sin historial Git | Hay archivos concretos de solicitud/respuesta | Ambiente, autorización o producción; HTTP 200 por sí solo no basta | Acreditación de origen |
| El JSON anonimizado conserva estructura | `anexos/muestra-olab-respuesta-anonimizada.json` | Claves y tipos principales | Equivalencia byte a byte o contrato oficial | Muestra oficial anonimizada |

## Estado de evidencia

- **Implementado en código:** webhook, consulta GDA, almacenamiento/versionado, extracción de texto, ruta Vision experimental, estados y recursos internos.
- **Cubierto por pruebas:** parseo de rangos/valores cualitativos, elegibilidad, despacho/supresión, estados de extracción y publicación controlada, según archivos de `tests/` identificados en el informe.
- **Habilitado en un entorno:** no verificado; los defaults de configuración revisados son conservadores y no sustituyen la configuración desplegada.
- **Observado en operación:** únicamente se observó una captura archivada OLAB en el checkout; no se acreditó ambiente ni actividad productiva.

## Validación local ejecutada

La validación local quedó documentada en `validacion-operativa-local-2026-10-27.md`. La base configurada es local dentro de Docker, el esquema pasó el preflight y contiene actividad histórica agregada por marca. Los conteos no deben interpretarse como estado actual de producción.
