# Evaluación de consumo local de laboratorios y GDA

Fecha: 2026-10-27. Revisión de solo lectura. No se ejecutaron migraciones, jobs, comandos con escritura ni llamadas externas.

## Resumen ejecutivo

- **Confirmado:** existe `database/test_db.sqlite`, con fecha técnica de modificación 2026-10-12 y tamaño 2,179,072 bytes.
- **Confirmado:** la base contiene las tablas de laboratorios, resultados y notificaciones, pero todas las tablas revisadas tienen cero registros: compras, notificaciones, versiones, reportes, observaciones, estados y eventos.
- **Confirmado:** no se encontró otro `.sqlite`, `.db`, dump o backup de base de datos en las rutas locales revisadas (`storage`, `backups`, `database`, `tmp` y raíz de proyecto, excluyendo dependencias).
- **Indicio:** `storage/app/results` contiene 11 archivos, 5 con nombre que inicia `gda-`, con 2,127,487 bytes en total. No están vinculados a registros de la base local y no se leyó su contenido clínico.
- **Conclusión:** la copia local disponible **no demuestra consumo real persistido** de GDA. Los PDFs son evidencia de archivos existentes, pero su procedencia, relación con órdenes y carácter real no pueden confirmarse porque la base está vacía.

## Fuente local

La única base de datos encontrada fue `database/test_db.sqlite`. La inspección usó PDO SQLite en modo de lectura para enumerar tablas y ejecutar `COUNT(*)`. Las tablas relevantes existen, pero retornaron cero filas. Por nombre y ubicación se clasifica como base de pruebas/local; no hay evidencia de que sea una copia reciente de producción.

## Evidencia de recepción y procesamiento

No hay filas locales en `laboratory_notifications`, `laboratory_purchases`, `laboratory_result_statuses`, `laboratory_result_versions`, `laboratory_result_reports`, `laboratory_result_observations` ni `laboratory_result_events`. Por tanto, no se puede confirmar recepción, asociación a paciente/orden, consulta GDA, extracción o publicación en esta base.

## Archivos de resultados

`storage/app/results` contiene 11 archivos y 2,127,487 bytes; 5 nombres comienzan con `gda-`. Los archivos no están versionados por Git y no hay relación comprobable con la base vacía. Se clasifican como **indicio**, no como resultado real confirmado. No se abrieron ni se extrajo texto para evitar exponer información clínica.

## Marcas

No hay marcas con evidencia de datos en la base local. Las cinco marcas del código (`olab`, `swisslab`, `jenner`, `liacsa`, `azteca`) siguen siendo únicamente evidencia de implementación/configuración, no de consumo efectivo en esta copia.

## Disponibilidad para IA

En la base analizada: identificador de paciente, folio, fechas, estudio, analitos, valores, unidades, rangos, banderas y referencias de PDF: **sin datos efectivos; pendiente**. El esquema sí soporta esos campos, según la evaluación anterior, pero esta copia no contiene registros.

## Conclusión

No es posible probar consumo real desde la copia local actual. Se requiere localizar/importar una copia de producción autorizada o consultar un respaldo de base de datos adecuado, además de confirmar la procedencia de los 11 archivos PDF. No se realizó ninguna llamada externa.
