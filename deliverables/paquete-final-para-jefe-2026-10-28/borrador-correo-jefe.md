Asunto: Resultado de investigación local sobre integraciones de laboratorios

Hola,

Comparto el paquete final de la investigación local sobre las integraciones de laboratorios de FAMEDIC.

1. Confirmo que OLAB, Swisslab, Jenner, Liacsa y Azteca están activas en producción. GDA es la integración técnica común localizada; esto no implica que cada marca tenga una API independiente.

2. La documentación técnica disponible incluye el webhook, la consulta posterior, la configuración por marca/convenio, el almacenamiento/versionado de PDF y la extracción posterior. No se localizó el contrato oficial vigente de GDA, OpenAPI, WSDL/XSD ni un esquema oficial JSON/XML.

3. Se adjunta una muestra anonimizada por marca. Son adaptaciones de notificaciones persistidas, no respuestas HTTP originales ni contratos ejecutables. Se priorizaron muestras de laboratorio clínico cuando estuvieron disponibles.

4. Las notificaciones revisadas aportan metadatos, identificadores anonimizados, estudio/panel, referencias y datos de seguimiento. Los analitos, valores, unidades, rangos y banderas requieren extraerlos del PDF. `abnormal_flag` es calculado por FAMEDIC y no una bandera estructurada nativa recibida de GDA.

5. El flujo implementado contempla recepción por webhook, consulta posterior cuando corresponde, decodificación del PDF Base64, almacenamiento y versionado. La confirmación operativa actual indica que no se reciben resultados clínicos estructurados por otra modalidad. No se afirma que la extracción estructurada esté operativa en producción.

6. Quedan pendientes con GDA/Operaciones: contrato oficial vigente, semántica de campos y fechas, autenticación y endpoint productivo, procedencia/fecha del backup y una respuesta original de consulta con evidencia independiente del webhook. La confirmación describe la integración actual de FAMEDIC y no limita las capacidades que GDA pueda tener fuera del acuerdo vigente.

Saludos.
