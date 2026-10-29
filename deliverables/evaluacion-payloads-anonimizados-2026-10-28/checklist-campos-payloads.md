# Checklist corregido

- [x] OLAB y Azteca actuales conservadas como `gabinete`.
- [x] Búsqueda adicional de laboratorio clínico para OLAB y Azteca realizada sin filtro del periodo anterior.
- [x] olab: muestra adicional de laboratorio clínico encontrada.
- [x] azteca: muestra adicional de laboratorio clínico encontrada.
- [x] Identificadores anidados revisados y anonimizados con sustituciones consistentes.
- [x] `header.registro` revisado sin imprimir valor; tipo/formato y decisión fecha vs. identificador documentados en el informe.
- [x] PDF/Base64 y credenciales documentados en `manifiesto-anonimizacion.csv` sin incluir contenidos.
- [x] `infogda_preanaliticos` aclarado como estructura de preguntas/preanalítica, no resultados clínicos.
- [x] `GDA_menssage` tratado como campo persistido cuya equivalencia exacta con HTTP no puede garantizarse.
- [ ] Respuesta original de consulta GDA: pendiente de evidencia de procedencia; `laboratory_purchases.gda_response` no basta porque el flujo revisado guarda allí el webhook.

## Confirmaciones operativas del responsable — 2026-10-28

- [x] El responsable confirmó que OLAB, Swisslab, Jenner, Liacsa y Azteca están activas en producción.
- [x] El responsable confirmó que la única modalidad actual de entrega de resultados por GDA es PDF codificado en Base64, decodificado por FAMEDIC.
- [x] Se registró que actualmente no se reciben resultados clínicos estructurados por otra modalidad.
- [x] Se separaron estas confirmaciones de la evidencia del código y del respaldo local.
- [x] Se aclaró que el webhook aporta metadatos y referencias; analitos, valores, unidades, rangos y banderas requieren extracción del PDF.
- [x] Se aclaró que `abnormal_flag` es calculado por FAMEDIC, no una bandera nativa estructurada de GDA.
- [x] Se conservaron como pendientes la documentación oficial no localizada y la fecha del backup; no invalidan las confirmaciones operativas.

## Validación local existente

- [x] Flags locales registrados: `structured_extraction=false`, `vision_extraction=false`, `structured_publication=false`, `recovery.gda_first=true`.
- [x] Conteos locales incorporados al informe final: 2,377 compras, 5,927 notificaciones, 517 estados, 1,325 versiones, 0 reportes, 0 observaciones y 3,493 eventos.
- [x] Los flags y conteos se presentan como evidencia del entorno/respaldo local y no se extrapolan automáticamente a producción.
