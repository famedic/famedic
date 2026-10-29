# Informe final de investigación local

**Fecha:** 2026-10-28  
**Alcance:** consolidación de evidencia ya generada en el checkout y en el respaldo MySQL local. No se ejecutaron nuevas consultas, llamadas externas, jobs, migraciones ni cambios funcionales.

## Conclusión ejecutiva

La evidencia local confirma que FAMEDIC tiene implementada una integración técnica común con GDA y declara cinco marcas en el código: OLAB, Swisslab, Jenner, Liacsa y Azteca. El responsable de FAMEDIC confirmó operativamente el 28 de octubre de 2026 que las cinco están activas en producción. Esta confirmación se mantiene separada de la evidencia del código y del respaldo local.

Los ejemplos entregados por marca son adaptaciones anonimizadas de payloads de notificación persistidos. No son contratos ejecutables ni respuestas HTTP reproducidas. Las fechas, referencias e identificadores contienen marcadores y los contenidos de PDF/Base64 fueron excluidos.

La implementación contempla recepción por webhook, consulta posterior a GDA y almacenamiento/versionado de PDF. El responsable confirmó que la única modalidad actual de entrega de resultados por GDA es un PDF codificado en Base64 que FAMEDIC decodifica; actualmente no se reciben resultados clínicos estructurados mediante otra modalidad. El uso observado en el respaldo local corresponde a notificaciones persistidas; `laboratory_purchases.gda_response` no se considera una respuesta original de consulta porque el flujo revisado guarda allí datos del webhook.

## 1. Inventario de marcas y proveedor técnico

| Marca declarada | Proveedor técnico localizado | Estado que puede sostenerse | Evidencia |
|---|---|---|---|
| OLAB | GDA | Activa en producción según confirmación del responsable; declarada/configurada en código | `app/Enums/LaboratoryBrand.php:7-14`; `config/services.php:123-152`; confirmación 2026-10-28 |
| Swisslab | GDA | Activa en producción según confirmación del responsable; declarada/configurada en código | mismas rutas; confirmación 2026-10-28 |
| Jenner | GDA | Activa en producción según confirmación del responsable; declarada/configurada en código | mismas rutas; confirmación 2026-10-28 |
| Liacsa | GDA | Activa en producción según confirmación del responsable; declarada/configurada en código | mismas rutas; confirmación 2026-10-28 |
| Azteca | GDA | Activa en producción según confirmación del responsable; declarada/configurada en código | mismas rutas; confirmación 2026-10-28 |

GDA aparece como proveedor técnico común, con configuración por marca/convenio/token. Las cinco marcas no representan necesariamente cinco APIs independientes. La confirmación describe la integración actual de FAMEDIC y no afirma que GDA carezca de otras capacidades fuera del acuerdo vigente.

## 2. Documentación técnica disponible y no localizada

### Disponible en el código/documentación local

- Entrada de notificaciones: `POST /api/laboratory/webhook/notifications`.
- Consulta posterior de resultados: `GetGDAResultsAction.php` y acciones/jobs de sincronización.
- Configuración de GDA por marca/convenio: `config/services.php` sin reproducir secretos.
- Validación, almacenamiento y versionado de PDF: `StoreGdaResultsPdfToStorageAction.php`, `laboratory_purchases.results` y `laboratory_result_versions`.
- Extracción posterior de analitos, valores, unidades y referencias desde PDF, con cálculo de `abnormal_flag` por FAMEDIC.
- Documentación interna de importación GDA y documentación interna de API V1/Akubica.

### No localizada o no verificable

No se localizó un contrato oficial vigente de GDA, OpenAPI, WSDL/XSD, esquema JSON/XML ni documentación oficial que confirme la semántica de todos los campos, autenticación, catálogos, fechas, banderas o referencias PDF. Tampoco se verificó la URL efectiva ni el entorno productivo a partir de secretos o configuración externa.

## 3. Ejemplos anonimizados por marca

Todos están etiquetados como: “Payload persistido en respaldo local declarado de producción por el responsable; fecha del respaldo y origen externo no verificados independientemente”.

| Marca | Ejemplo disponible | Clasificación | Interpretación permitida |
|---|---|---|---|
| OLAB | `notificacion_resultados_olab.json` y `notificacion_resultados_olab_laboratorio_clinico.json` | Gabinete y laboratorio clínico | Payloads persistidos de notificación, no contratos ni respuestas de consulta |
| Swisslab | `notificacion_resultados_swisslab.json` | No clasificado | Payload persistido; el contexto no se infiere como laboratorio clínico |
| Jenner | `notificacion_resultados_jenner.json` | Laboratorio clínico | Payload persistido de notificación |
| Azteca | `notificacion_resultados_azteca.json` y `notificacion_resultados_azteca_laboratorio_clinico.json` | Gabinete y laboratorio clínico | Payloads persistidos de notificación |
| Liacsa | `notificacion_resultados_liacsa.json` | No clasificado | Payload persistido; la actividad histórica no prueba estado actual |

Las muestras son adaptaciones anonimizadas: conservan estructura y tipos donde fue posible, pero sustituyen identificadores, fechas, URLs, credenciales y contenido sensible. No deben utilizarse para ejecutar integraciones.

## 4. Matriz de campos solicitados

La matriz completa por muestra, estado y ruta exacta está en [`matriz-campos-payloads.csv`](matriz-campos-payloads.csv).

La lectura correcta es por muestra: `presente`, `vacío` y `ausente` describen esa muestra concreta y no todas las respuestas posibles de GDA. `infogda_preanaliticos` puede contener identificadores de preguntas y unidades vacías; su existencia no equivale a resultados clínicos estructurados.

Los analitos, valores, unidades, rangos y banderas estructuradas no se deben generalizar como ausentes en toda respuesta GDA. En el flujo local, pueden aparecer después de extraer el PDF. Además, `abnormal_flag` es un cálculo de FAMEDIC y no una bandera original confirmada del proveedor.

Para PDF/Base64 se debe consultar [`manifiesto-anonimizacion.csv`](manifiesto-anonimizacion.csv), que distingue clave ausente, campo vacío y contenido retirado. La ausencia de una clave en el JSON anonimizado no demuestra que estuviera ausente originalmente.

## 5. Flujo implementado

1. **Webhook:** FAMEDIC recibe notificaciones GDA, valida el payload y crea/procesa la notificación.
2. **Consulta posterior:** cuando el PDF no está disponible o se requiere sincronización, una acción/job consulta GDA mediante la configuración de marca/convenio.
3. **Almacenamiento:** el PDF se valida, almacena y versiona; la ruta vigente se relaciona con `laboratory_purchases.results` y las versiones conservan metadatos técnicos.
4. **Extracción:** posteriormente se extraen campos desde el PDF y se pueden calcular estados o banderas. La implementación de extracción no prueba por sí sola que esté activada localmente ni que se use en producción.

Debe distinguirse entre capacidad implementada, activación local, uso observado y confirmación operativa. El responsable confirma la activación actual; el respaldo solo muestra actividad histórica persistida y no sustituye la confirmación del responsable.

## 6. Evidencia histórica del respaldo y límites

La evidencia procede de un respaldo local declarado como copia reciente de producción por el responsable. La fecha del respaldo, el origen externo, la cadena de custodia y la equivalencia byte a byte con el HTTP recibido no fueron verificados independientemente.

Los payloads se presentan como persistidos. `GDA_menssage` aparece dentro de algunos payloads, pero no puede garantizarse que sea idéntico al recibido originalmente ni que no haya sido transformado. El código revisado muestra que el flujo asigna los datos del webhook a `laboratory_purchases.gda_response`; por eso ese campo no es evidencia suficiente de una respuesta original de consulta GDA.

Las fechas observadas en `header.registro` se documentan como fechas del payload/notificación. No se presentan como fechas clínicas de toma, liberación o reporte. El significado clínico requiere contrato o evidencia del proveedor.

## 7. Pendientes de confirmación

### Documentación y operación

- Confirmar con GDA/Operaciones el contrato oficial vigente, esquema de campos, autenticación y semántica de identificadores.
- Confirmar URL, entorno y flujo productivo de consulta posterior.
- Confirmar origen, fecha y cadena de custodia del respaldo local.
- Obtener, si se requiere, una respuesta original de consulta GDA con evidencia de procedencia independiente del webhook.
- Confirmar si fechas, banderas, referencias y `GDA_menssage` son del proveedor o transformaciones de FAMEDIC.
- Validar la procedencia y representatividad de las muestras clínicas adicionales de OLAB y Azteca.

### Fuera del alcance actual: futuras validaciones de calidad de IA

- Medir extracción completa/parcial, referencias no numéricas y revisión manual.
- Comparar PDF contra extracción textual/visual.
- Validar reglas de interpretación, límites, unidades, flags y métricas de calidad.
- Confirmar activación de extracción estructurada y gates de publicación.

Estos puntos no son evidencia de una falla actual ni forman parte de la activación de IA; son validaciones posteriores del proyecto.

## Cobertura de la solicitud original

La investigación cubre el inventario declarado, el proveedor técnico localizado, la documentación implementada, ejemplos anonimizados por marca, la matriz de campos, el flujo de webhook/consulta/almacenamiento y los límites de la evidencia local. La activación productiva de las cinco marcas y la modalidad actual de entrega por PDF/Base64 quedan cubiertas como confirmaciones operativas del responsable, no como inferencias del respaldo. Siguen dependiendo de terceros o de validación interna el contrato oficial GDA, la procedencia externa y fecha del respaldo, la semántica contractual de los campos y una respuesta original de consulta con procedencia comprobable.

## Confirmaciones operativas del responsable de FAMEDIC

Recibidas el 28 de octubre de 2026 y separadas de la evidencia técnica local:

- OLAB, Swisslab, Jenner, Liacsa y Azteca están activas en producción.
- La única modalidad actual de entrega de resultados por GDA es un PDF codificado en Base64, que FAMEDIC decodifica para obtener el documento.
- Actualmente no se reciben resultados clínicos estructurados por otra modalidad.

Estas confirmaciones describen la integración actual de FAMEDIC. No afirman que GDA carezca de otras capacidades fuera del acuerdo vigente.

El webhook aporta metadatos y referencias según las muestras revisadas. Los analitos, valores, unidades, rangos y banderas requieren extracción del PDF. `abnormal_flag` es calculado por FAMEDIC y no es una bandera nativa recibida como campo estructurado de GDA.

## Conteos y flags de la validación local existente

Se reproducen aquí sin nuevas consultas los resultados de `validacion-operativa-local-2026-10-27.md`:

| Elemento | Resultado local registrado | Lectura correcta |
|---|---:|---|
| `laboratory_purchases` | 2,377 | Registros del respaldo/local; no actividad actual por sí sola |
| `laboratory_notifications` | 5,927 | Notificaciones persistidas del respaldo/local |
| `laboratory_result_statuses` | 517 | Estados registrados localmente |
| `laboratory_result_versions` | 1,325 | Versiones registradas localmente |
| `laboratory_result_reports` | 0 | Sin reportes estructurados registrados en el corte local |
| `laboratory_result_observations` | 0 | Sin observaciones estructuradas registradas en el corte local |
| `laboratory_result_events` | 3,493 | Eventos registrados localmente |

Flags efectivos observados en el runtime local: `structured_extraction=false`, `vision_extraction=false`, `structured_publication=false` y `recovery.gda_first=true`. Estos flags describen el entorno local validado y no contradicen la confirmación de producción; no deben extrapolarse a producción.

La documentación oficial no localizada y la fecha del backup siguen pendientes. Esos pendientes limitan la trazabilidad documental e histórica, pero no invalidan las dos confirmaciones operativas del responsable.
