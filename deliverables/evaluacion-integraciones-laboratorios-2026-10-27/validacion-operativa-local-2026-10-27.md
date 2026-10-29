# Validación operativa local

Fecha de ejecución: 2026-10-27. Checkout: rama `main`, commit `7ff6760505dbca0455398d1a241a103dab994bd2`. Se ejecutó dentro del contenedor local `app` del compose del proyecto. No se iniciaron servicios, jobs, workers, migraciones, llamadas a GDA ni consultas a storage externo.

## A. Configuración local observada

- `APP_ENV`: `local`.
- `APP_URL`: `http://localhost:8080`.
- PHP/Laravel: PHP 8.3.31 y Laravel 11.46.1 dentro del contenedor `app`.
- Conexión: MySQL; host Docker `mysql`, puerto interno `3306`; el servicio está dentro del compose local y no apunta a producción/staging.
- `app.timezone`: `America/Mexico_City`.
- Zona horaria PHP: `America/Mexico_City`.
- MySQL: `@@session.time_zone=SYSTEM`, `@@global.time_zone=SYSTEM`, `@@global.system_time_zone=UTC`.
- Flags efectivos: `structured_extraction=false`, `vision_extraction=false`, `structured_publication=false`, `recovery.gda_first=true`.
- Las cuatro claves existieron; ninguna fue `missing`.
- `FILESYSTEM_DISK=local`. No se consultó storage.

La base local se identificó por la red/servicio Docker `mysql` y por `APP_ENV=local`; esto no prueba que los datos sean exclusivamente de producción. La fecha del backup no está documentada en los archivos revisados.

## B. Esquema y timestamps

El preflight devolvió `schema_status=ok` para compras, notificaciones, estados de resultado, versiones, reportes y observaciones. Las columnas inspeccionadas (`created_at`, `results_received_at`, `pdf_available_at`, `reported_at`, `specimen_collected_at` y equivalentes) existen como MySQL `timestamp`, nullable cuando corresponde.

Esto describe el esquema local desplegado; no demuestra la zona horaria original con la que fueron generados los datos del backup. Para filtrar se usaron límites calculados en `America/Mexico_City` y convertidos a UTC porque la sesión local observada termina resolviendo a UTC.

## C. Periodo y actividad histórica del respaldo

No se encontró fecha documentada del backup. Se utilizó la fecha de ejecución como corte:

- Límites de aplicación: `2026-09-27T11:39:57-06:00` a `2026-10-27T11:39:57-06:00`.
- Límites usados en MySQL: `2026-09-27T17:39:57+00:00` a `2026-10-27T17:39:57+00:00`.
- Filtro de compras: `laboratory_purchases.created_at`.
- Filtro de notificaciones: `laboratory_notifications.created_at`.
- Filtro de versiones/reportes: fecha `created_at` de la versión o reporte.

Las fechas de primera/última actividad y `pdf_available_at` se muestran tal como fueron devueltas por la sesión MySQL local, que resolvió a UTC; los límites de aplicación se muestran aparte en `America/Mexico_City`.

### Compras con referencia GDA

| Marca | Compras con `gda_order_id` | Con `gda_acuse` | Con `gda_consecutivo` | Primera actividad | Última actividad |
|---|---:|---:|---:|---|---|
| OLAB | 51 | 42 | 51 | 2026-09-28 14:42:29 | 2026-10-19 12:28:22 |
| Swisslab | 65 | 54 | 65 | 2026-09-28 16:15:48 | 2026-10-19 12:41:20 |
| Jenner | 4 | 2 | 4 | 2026-09-30 22:40:37 | 2026-10-19 12:21:51 |
| Liacsa | 4 | 0 | 4 | 2026-10-07 12:42:54 | 2026-10-19 12:36:24 |
| Azteca | 4 | 4 | 4 | 2026-09-29 11:35:18 | 2026-10-02 19:18:05 |

### Notificaciones de resultados

| Marca | Notificaciones | Compras con notificación | Con `results_received_at` | Primera actividad | Última actividad |
|---|---:|---:|---:|---|---|
| OLAB | 103 | 48 | 103 | 2026-09-28 16:00:56 | 2026-10-17 16:00:24 |
| Swisslab | 201 | 58 | 201 | 2026-09-27 17:44:22 | 2026-10-18 17:37:23 |
| Jenner | 9 | 2 | 9 | 2026-10-01 18:00:32 | 2026-10-07 20:01:29 |
| Liacsa | 0 | 0 | 0 | — | — |
| Azteca | 12 | 5 | 12 | 2026-10-03 10:00:16 | 2026-10-05 20:00:42 |

La consulta de notificaciones incluyó `notification_type = Notificaion-Resultados` o `lineanegocio = Notificaion-Resultados`.

### Versiones de PDF

| Marca | Fuente | Clasificación | Versiones | Primera `pdf_available_at` | Última `pdf_available_at` |
|---|---|---|---:|---|---|
| OLAB | gda | pending_interpretation | 83 | 2026-09-28 16:00:56 | 2026-10-17 16:00:24 |
| OLAB | gda | unknown | 156 | 2026-06-24 18:22:22 | 2026-10-17 16:00:24 |
| Swisslab | gda | complete | 358 | 2026-05-14 20:00:11 | 2026-10-18 17:37:23 |
| Swisslab | gda | pending_interpretation | 18 | 2026-10-06 18:02:20 | 2026-10-17 12:00:24 |
| Swisslab | gda | unknown | 228 | 2026-04-25 18:00:08 | 2026-10-18 15:44:32 |
| Jenner | gda | pending_interpretation | 9 | 2026-10-07 10:00:25 | 2026-10-07 20:01:29 |
| Jenner | gda | unknown | 12 | 2026-10-01 18:00:32 | 2026-10-01 22:00:06 |
| Azteca | gda | pending_interpretation | 1 | 2026-10-05 20:00:42 | 2026-10-05 20:00:42 |
| Azteca | gda | unknown | 23 | 2026-10-03 10:00:16 | 2026-10-05 20:00:42 |

El filtro de versiones usó `laboratory_result_versions.created_at`; las fechas mostradas son `pdf_available_at` y pueden ser anteriores al periodo por recuperación, importación o versionado posterior. No se consultó si el objeto existe en storage.

### Reportes estructurados y observaciones

No hubo filas en `laboratory_result_reports` dentro del periodo, por lo que el agregado por `extraction_method`, `extraction_status` y `structured_status` fue vacío. La comprobación enlazada a `laboratory_result_observations` también devolvió cero filas.

Esto demuestra ausencia de reportes estructurados registrados en este respaldo/local para el corte consultado; no demuestra que la extracción esté deshabilitada en producción ni que no existan reportes fuera del periodo.

## D. Separación de ambientes

- **Configuración local observada:** los valores de runtime y los conteos anteriores.
- **Actividad histórica contenida en el respaldo:** los registros agregados en la base local, sin asumir que representan estado actual.
- **Producción actual:** no verificada. No se realizaron conexiones a producción ni staging.
