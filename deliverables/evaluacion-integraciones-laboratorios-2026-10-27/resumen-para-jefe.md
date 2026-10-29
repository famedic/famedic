# Resumen para compartir

## Confirmado

FAMEDIC tiene cinco marcas declaradas —OLAB, Swisslab, Jenner, Liacsa y Azteca— y el conector técnico localizado es GDA, con configuración separada por marca/convenio. Esto confirma implementación en código, no que las cinco estén activas hoy en producción.

Los resultados llegan por dos mecanismos: notificación webhook GDA y consulta posterior a GDA. El dato de resultado localizado es principalmente un PDF en Base64. FAMEDIC lo valida, almacena en storage, conserva una referencia en la compra y registra versiones con hash, fuente y clasificación.

FAMEDIC sí tiene una capa estructurada posterior: extrae del PDF analito, valor, unidad, referencia y estado calculado. La bandera fuera de rango (`abnormal_flag`) es calculada por FAMEDIC; no se verificó una bandera clínica nativa en el contrato GDA. Por ello no debe describirse como si esos campos vinieran estructurados desde el proveedor.

Existe una captura archivada de respuesta OLAB con `GDA_menssage` exitoso y `infogda_resultado_b64`. Está anonimizada en el anexo, pero el repositorio no certifica si provino de producción o QA. No se encontró XML/WSDL/OpenAPI oficial del proveedor.

## Pendiente

Operaciones/proveedor deben confirmar marcas y convenios activos, contrato vigente de resultados, semántica de cada identificador y fecha, banderas originales, cobertura de analitos, y entregar muestras reales anonimizadas por marca. También debe medirse cuándo un PDF es parcial y cuándo la extracción requiere revisión manual.

En síntesis: para el proyecto de IA el insumo principal disponible actualmente es el PDF y los datos internos de trazabilidad; su calidad y completitud aún deben validarse. Los analitos estructurados son una extracción de FAMEDIC, no un contrato nativo demostrado de GDA.
