# Guía de validación operativa de solo lectura

## Alcance y registro obligatorio

Ejecutar únicamente en un entorno autorizado, con una cuenta de lectura. Registrar `environment/app_env`, fecha y hora de ejecución, zona horaria de la base de datos, periodo UTC usado y commit desplegado. El ejemplo usa el periodo explícito `2026-01-01 00:00:00` a `2026-10-27 23:59:59` en UTC; cambiarlo antes de ejecutar si se requiere otro corte.

Las consultas no muestran nombres, teléfonos, correos, direcciones, payloads, Base64, rutas de storage ni URLs. No ejecutar `php artisan queue:work`, jobs, comandos de refresh, consultas al proveedor ni descargas.

## 1. Entorno y flags, sin secretos

```bash
php artisan tinker --execute='echo json_encode([
  "app_env" => app()->environment(),
  "structured_extraction_enabled" => (bool) config("laboratory-results.structured_extraction.enabled"),
  "vision_extraction_enabled" => (bool) config("laboratory-results.vision_extraction.enabled"),
  "structured_publication_enabled" => (bool) config("laboratory-results.structured_publication.enabled"),
  "recovery_gda_first" => (bool) config("laboratory-results.recovery.gda_first"),
], JSON_THROW_ON_ERROR), PHP_EOL;'
```

Propósito: saber si las capas están habilitadas en el entorno ejecutado. No imprime URLs, tokens ni valores de `.env`. Un `false` local no demuestra que producción esté deshabilitada.

## 2. Compras con evidencia de orden GDA

```sql
SELECT
  p.brand,
  COUNT(*) AS purchases_with_gda_order,
  SUM(CASE WHEN NULLIF(p.gda_acuse, '') IS NOT NULL THEN 1 ELSE 0 END) AS purchases_with_acuse,
  SUM(CASE WHEN NULLIF(p.gda_code_http, '') = '200' THEN 1 ELSE 0 END) AS purchases_with_http_200,
  MIN(p.created_at) AS first_purchase_at,
  MAX(p.created_at) AS last_purchase_at
FROM laboratory_purchases p
WHERE p.created_at >= :period_start_utc
  AND p.created_at < :period_end_exclusive_utc
  AND NULLIF(p.gda_order_id, '') IS NOT NULL
GROUP BY p.brand
ORDER BY p.brand;
```

Propósito: contar órdenes con identificador GDA y señales internas de acuse/HTTP. Limitación: `gda_order_id` demuestra persistencia, no que la orden siga activa ni que el proveedor esté habilitado hoy.

## 3. Notificaciones de resultados

```sql
SELECT
  p.brand,
  COUNT(*) AS result_notifications,
  COUNT(DISTINCT n.laboratory_purchase_id) AS purchases_with_result_notification,
  SUM(CASE WHEN n.results_received_at IS NOT NULL THEN 1 ELSE 0 END) AS with_results_received_at,
  SUM(CASE WHEN n.results_pdf_base64 IS NOT NULL THEN 1 ELSE 0 END) AS with_pdf_base64_column,
  MIN(n.created_at) AS first_notification_at,
  MAX(n.created_at) AS last_notification_at
FROM laboratory_notifications n
JOIN laboratory_purchases p ON p.id = n.laboratory_purchase_id
WHERE n.created_at >= :period_start_utc
  AND n.created_at < :period_end_exclusive_utc
  AND (n.notification_type = 'Notificaion-Resultados'
       OR n.lineanegocio = 'Notificaion-Resultados')
GROUP BY p.brand
ORDER BY p.brand;
```

Propósito: cuantificar avisos de resultados y distinguir fecha de notificación de la columna de recepción. Limitación: que exista `results_pdf_base64` en la fila no prueba que sea PDF válido ni que contenga analitos.

## 4. Versiones de PDF registradas

```sql
SELECT
  p.brand,
  v.source,
  v.classification,
  COUNT(*) AS pdf_versions,
  COUNT(DISTINCT v.laboratory_result_status_id) AS result_statuses,
  MIN(v.pdf_available_at) AS first_pdf_available_at,
  MAX(v.pdf_available_at) AS last_pdf_available_at
FROM laboratory_result_versions v
JOIN laboratory_result_statuses rs ON rs.id = v.laboratory_result_status_id
JOIN laboratory_purchases p ON p.id = rs.laboratory_purchase_id
WHERE v.created_at >= :period_start_utc
  AND v.created_at < :period_end_exclusive_utc
GROUP BY p.brand, v.source, v.classification
ORDER BY p.brand, v.source, v.classification;
```

Propósito: contar documentos versionados y sus clasificaciones. Limitación: `storage_path` persistido no prueba que el objeto exista actualmente en storage.

## 5. Reportes estructurados y observaciones

```sql
SELECT
  p.brand,
  r.extraction_method,
  r.extraction_status,
  r.structured_status,
  COUNT(*) AS reports,
  SUM(r.observation_count) AS observations_recorded,
  MIN(r.created_at) AS first_report_at,
  MAX(r.created_at) AS last_report_at
FROM laboratory_result_reports r
JOIN laboratory_purchases p ON p.id = r.laboratory_purchase_id
WHERE r.created_at >= :period_start_utc
  AND r.created_at < :period_end_exclusive_utc
GROUP BY p.brand, r.extraction_method, r.extraction_status, r.structured_status
ORDER BY p.brand, r.extraction_method, r.extraction_status, r.structured_status;
```

Propósito: separar texto (`PdfText`) de Vision, estados de extracción y estado de publicación. `observation_count` es el valor registrado por el reporte; no sustituye una auditoría de completitud contra el PDF.

## 6. Presencia de objetos en storage, solo sí/no y cantidades

Ejecutar en Tinker en el entorno autorizado; no imprimir `storage_path`:

```bash
php artisan tinker --execute='use App\\Models\\LaboratoryResultVersion; use Illuminate\\Support\\Facades\\Storage; $versions = LaboratoryResultVersion::query()->whereBetween("created_at", ["2026-01-01 00:00:00", "2026-10-27 23:59:59"])->get(["id", "storage_path"]); $present = $versions->filter(fn ($v) => Storage::exists($v->storage_path))->count(); echo json_encode(["versions" => $versions->count(), "objects_present" => $present, "objects_missing" => $versions->count() - $present]), PHP_EOL;'
```

Limitación: esta comprobación confirma existencia para el disk configurado en la aplicación; no confirma que el contenido sea clínicamente completo.

## Resultado agregado que debe devolverse

Entregar únicamente las tablas agregadas, flags sin secretos, periodo/zona horaria, ambiente y commit desplegado. No entregar filas individuales, `storage_path`, `payload`, Base64, PDFs, identificadores de paciente ni URLs.
