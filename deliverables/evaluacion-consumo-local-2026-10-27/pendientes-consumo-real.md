# Pendientes de consumo real

1. Localizar la copia o dump de producción autorizado. La única base encontrada es `database/test_db.sqlite` y está vacía en tablas relevantes.
2. Confirmar la procedencia de los 11 archivos de `storage/app/results`, especialmente los 5 con prefijo `gda-`, sin abrirlos en este análisis. Se requiere relación con órdenes/versiones o un manifiesto de exportación.
3. Solicitar un respaldo consistente de las tablas `laboratory_notifications`, `laboratory_purchases`, `laboratory_result_versions`, `laboratory_result_reports` y `laboratory_result_observations`, con datos anonimizados o acceso controlado.
4. Confirmar qué marcas y convenios estaban activos en el periodo de los archivos. El código no sustituye evidencia de operación.
5. Si se entrega una copia válida, repetir únicamente consultas de lectura y reportar conteos agregados, campos presentes y muestras estructurales anonimizadas.

No se requiere llamar a GDA para esta fase; primero debe comprobarse la fuente local y su procedencia.
