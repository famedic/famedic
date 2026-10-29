# Muestras anonimizadas y procedencia

Etiqueta aplicada: **Payload persistido en respaldo local declarado de producción por el responsable; fecha del respaldo y origen externo no verificados independientemente**. Las muestras son payloads persistidos en respaldo local; no se garantiza equivalencia byte a byte con el HTTP recibido porque no se verificó origen externo ni la cadena de persistencia.

## Olab

- Contexto `gabinete`: `notificacion_resultados_olab.json`
- Contexto `laboratorio_clinico`: `notificacion_resultados_olab_laboratorio_clinico.json`
## Swisslab

- Contexto `no_clasificado`: `notificacion_resultados_swisslab.json`
## Jenner

- Contexto `laboratorio_clinico`: `notificacion_resultados_jenner.json`
## Azteca

- Contexto `gabinete`: `notificacion_resultados_azteca.json`
- Contexto `laboratorio_clinico`: `notificacion_resultados_azteca_laboratorio_clinico.json`
## Liacsa

- Contexto `no_clasificado`: `notificacion_resultados_liacsa.json`

## Anonimización

Se recorrieron objetos anidados y texto libre. Se sustituyeron de forma consistente identificadores transaccionales, incluidos `requisition.value`, `infogda_orden`, `infogda_etiqueta` e `infogda_kmuestrasucursal`, conservando string o entero cuando correspondía. Se retiraron contenidos de PDF/Base64, credenciales y URLs. `_metadata` es agregado por FAMEDIC y no forma parte del payload original.
