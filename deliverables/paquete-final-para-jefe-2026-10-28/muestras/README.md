# Muestras anonimizadas incluidas en este paquete

Etiqueta aplicada: **Payload persistido en respaldo local declarado de producción por el responsable; fecha del respaldo y origen externo no verificados independientemente**.

El paquete contiene exactamente cinco JSON, uno por marca. Se priorizó laboratorio clínico cuando había una muestra clasificada así:

| Marca | Archivo | Contexto |
|---|---|---|
| OLAB | `notificacion_resultados_olab_laboratorio_clinico.json` | laboratorio clínico |
| Swisslab | `notificacion_resultados_swisslab.json` | no clasificado; muestra persistida disponible |
| Jenner | `notificacion_resultados_jenner.json` | laboratorio clínico |
| Liacsa | `notificacion_resultados_liacsa.json` | no clasificado; muestra persistida disponible |
| Azteca | `notificacion_resultados_azteca_laboratorio_clinico.json` | laboratorio clínico |

Todas son adaptaciones anonimizadas de notificaciones persistidas. No son respuestas HTTP originales, contratos ejecutables ni evidencia independiente de producción. Se recorrieron objetos anidados y texto libre; se sustituyeron identificadores, fechas y referencias sensibles y se retiraron contenidos de PDF/Base64, credenciales y URLs. `_metadata`, cuando aparece, es agregado y no forma parte del payload original.
