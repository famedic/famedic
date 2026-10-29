# Checklist del paquete final

## Muestras incluidas

- [x] OLAB: `muestras/notificacion_resultados_olab_laboratorio_clinico.json`.
- [x] Swisslab: `muestras/notificacion_resultados_swisslab.json`.
- [x] Jenner: `muestras/notificacion_resultados_jenner.json`.
- [x] Liacsa: `muestras/notificacion_resultados_liacsa.json`.
- [x] Azteca: `muestras/notificacion_resultados_azteca_laboratorio_clinico.json`.
- [x] El paquete contiene exactamente cinco JSON, uno por marca.

## Consistencia y procedencia

- [x] Identificadores anidados revisados y anonimizados con sustituciones consistentes.
- [x] `header.registro` revisado sin imprimir el valor; tipo/formato y decisión de anonimización están documentados.
- [x] PDF/Base64 y credenciales documentados en `manifiesto-anonimizacion.csv` sin incluir contenidos.
- [x] `infogda_preanaliticos` aclarado como estructura de preguntas/preanalítica, no resultados clínicos.
- [x] `GDA_menssage` tratado como campo persistido cuya equivalencia exacta con HTTP no puede garantizarse.
- [ ] Respuesta original de consulta GDA: pendiente de evidencia de procedencia; `laboratory_purchases.gda_response` no basta porque el flujo revisado guarda allí el webhook.

## Confirmaciones operativas — 2026-10-28

- [x] OLAB, Swisslab, Jenner, Liacsa y Azteca están activas en producción según confirmación operativa recibida.
- [x] La modalidad actual de resultados por GDA es PDF codificado en Base64, decodificado por FAMEDIC.
- [x] Actualmente no se reciben resultados clínicos estructurados por otra modalidad.
- [x] El webhook aporta metadatos y referencias; analitos, valores, unidades, rangos y banderas requieren extracción del PDF.
- [x] `abnormal_flag` es calculado por FAMEDIC, no una bandera nativa estructurada de GDA.
- [x] La documentación oficial no localizada y la fecha del backup permanecen pendientes.

## Validación local documentada

- [x] Se conservaron únicamente conteos del periodo documentado de 30 días; la fuente, periodo y filtros están descritos en el informe final.
- [x] Flags locales registrados: `structured_extraction=false`, `vision_extraction=false`, `structured_publication=false`, `recovery.gda_first=true`.
- [x] Los conteos y flags describen el entorno/respaldo local; no demuestran extracción estructurada operativa en producción.
