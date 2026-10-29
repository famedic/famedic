# Índice de muestras y procedencia

Se incluye `muestra-olab-respuesta-anonimizada.json`. Es una adaptación anonimizada de la captura archivada `docs/Payload-respuesta-OLAB.txt`; conserva nombres de claves y tipos relevantes, pero sustituye identificadores, token y Base64. La procedencia operativa de la captura (producción/QA) no está documentada, por lo que no se presenta como muestra verificable de producción.

- `app/Http/Controllers/Laboratory/LaboratoryWebhookController.php:160-253`: payload construido con `TEST-*`, `test-token` y base64 de ejemplo. Fixture/ejemplo.
- `tests/Feature/Laboratory/GdaWebhookGabineteIsolatedTest.php`: fixture de webhook.
- `tests/Feature/Laboratory/GdaResultsStoragePhase1IsolatedTest.php` y fases 2–5: PDFs base64 sintéticos/mockeados.
- `tests/Unit/GDA/GdaPayloadSanitizerTest.php:8-36`: prueba de retiro de base64 pesado.

- `docs/Payload-enviado-olab.txt`: captura archivada de solicitud/resultado OLAB; contiene identificadores y token, no se copia al entregable.
- `docs/Payload-respuesta-OLAB.txt`: captura archivada de respuesta con `GDA_menssage` exitoso y `infogda_resultado_b64`; el PDF se sustituye por `<PDF_BASE64_OMITIDO>`.

La captura archivada sí fue anonimizada en el anexo, pero no se acredita como muestra real de producción: **“Captura archivada anonimizada; ambiente y procedencia operativa no verificados”.** Se omitieron `.env`, tokens, URLs firmadas, Base64 completo, PDFs e identificadores personales. El anexo conserva las claves y los tipos principales; sustituyó identificadores/folios por marcadores de cadena, mantuvo `marca` y códigos de estado como valores de estructura, y sustituyó `infogda_resultado_b64` por un marcador de cadena. No puede usarse como contrato exacto ni como evidencia de un ambiente.
