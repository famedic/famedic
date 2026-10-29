# Manifiesto de procedencia de campos

Las muestras se presentan como payloads persistidos. No puede garantizarse equivalencia byte a byte con el HTTP recibido ni distinguir, solo desde el respaldo, cada modificación posterior.

| Campo o grupo | Evidencia local | Conclusión |
|---|---|---|
| `payload` | `laboratory_notifications.payload` | Payload persistido asociado a una notificación; origen HTTP exacto no verificado independientemente. |
| `GDA_menssage` | Presente dentro de algunos payloads persistidos | Se reporta como persistido; no se garantiza que sea idéntico al recibido ni que no haya sido transformado. |
| `header`, `requisition`, `code.coding`, `subject` | Rutas presentes en payloads | Se conservan estructuralmente y se anonimizan identificadores; no se atribuye semántica adicional sin contrato. |
| `laboratory_purchases.gda_response` | Código `HandleResultsNotificationAction.php:334-360` | No es evidencia suficiente de respuesta de consulta: el flujo guarda allí el webhook (`$data`). |
| `infogda_preanaliticos` | Rutas presentes/vacías en payloads | Puede contener preguntas, identificadores y unidades vacías; no equivale a resultados clínicos estructurados. |
