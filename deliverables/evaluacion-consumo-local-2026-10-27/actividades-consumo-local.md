# Actividades de consumo local

## Realizado

- Leído el prompt y verificado que no existe `AGENTS.md` en el proyecto.
- Localizados archivos candidatos de base de datos: solo `database/test_db.sqlite` fue encontrado entre SQLite/DB/dump/backup locales revisados.
- Consultada la base mediante PDO SQLite en lectura: se enumeraron tablas y se ejecutaron conteos agregados.
- Verificadas en cero las tablas relevantes de compras, notificaciones, resultados, versiones, reportes, observaciones, estados y eventos.
- Revisada la presencia agregada de archivos en `storage/app/results`: 11 archivos, 5 con prefijo `gda-`, 2,127,487 bytes.
- No se abrió contenido de PDFs, no se extrajo información clínica, no se llamaron APIs externas y no se ejecutaron procesos con escritura.
- Generados informe, CSV, pendientes e índice de muestras.

## Pendiente

- No se pudo confirmar consumo real porque la base local está vacía y los PDFs no tienen relación verificable con ella.
- No se generó muestra anonimizada de respuesta real.
