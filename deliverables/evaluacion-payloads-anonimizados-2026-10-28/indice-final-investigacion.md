# Índice final de investigación local

Este índice consolida los entregables existentes y el informe final. El análisis se realizó sobre evidencia local ya generada; no se ejecutaron nuevas consultas ni se modificó código.

## Confirmaciones operativas incorporadas

El responsable de FAMEDIC confirmó el 28 de octubre de 2026 que las cinco marcas están activas en producción y que la modalidad actual de resultados por GDA es PDF codificado en Base64, decodificado por FAMEDIC. Estas confirmaciones están separadas de la evidencia del código y del respaldo local y se desarrollan en el [informe final](informe-final-investigacion-local.md).

## Informe principal

- [Informe final de investigación local](informe-final-investigacion-local.md)

## Matriz y listas de control

- [Matriz completa de campos por muestra](matriz-campos-payloads.csv)
- [Checklist corregido](checklist-campos-payloads.md)
- [Pendientes de la solicitud original](pendientes-solicitud-original.md)

## Muestras anonimizadas

- [Índice y descripción de muestras](muestras/README.md)
- [OLAB gabinete](muestras/notificacion_resultados_olab.json)
- [OLAB laboratorio clínico](muestras/notificacion_resultados_olab_laboratorio_clinico.json)
- [Swisslab](muestras/notificacion_resultados_swisslab.json)
- [Jenner](muestras/notificacion_resultados_jenner.json)
- [Azteca gabinete](muestras/notificacion_resultados_azteca.json)
- [Azteca laboratorio clínico](muestras/notificacion_resultados_azteca_laboratorio_clinico.json)
- [Liacsa](muestras/notificacion_resultados_liacsa.json)

Todos los JSON son adaptaciones anonimizadas de payloads persistidos de notificación. No son contratos ejecutables ni respuestas originales de consulta GDA.

## Procedencia y anonimización

- [Manifiesto de anonimización](manifiesto-anonimizacion.csv)
- [Manifiesto de procedencia](manifiesto-proveniencia.md)
- [Informe técnico de payloads persistidos](informe-payloads-persistidos.md)

El manifiesto de anonimización es la fuente para interpretar la presencia original de PDF/Base64, credenciales y URLs; el JSON anonimizado no debe utilizarse para deducir su ausencia original.

## Alcance

Cubierto: inventario declarado, GDA como proveedor técnico localizado, documentación implementada, muestras anonimizadas, matriz de campos, flujo técnico y límites de la evidencia local.

Pendiente de terceros o validación interna: marcas activas actualmente, contrato oficial GDA, procedencia del respaldo, semántica de campos, respuesta original de consulta con evidencia de procedencia y validaciones futuras de calidad de extracción/IA.

La activación de las cinco marcas ya está cubierta por confirmación del responsable. Siguen pendientes el contrato oficial no localizado, la fecha y procedencia del backup, la semántica contractual de los campos y una respuesta original de consulta con procedencia comprobable. Los conteos y flags de la validación local existente están incorporados en el informe final y no se extrapolan automáticamente a producción.
