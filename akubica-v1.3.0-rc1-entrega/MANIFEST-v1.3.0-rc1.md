# MANIFEST — entrega Akúbica v1.3.0-rc1

Paquete sanitizado para Leo / ATIX. Sin secretos, sin reportes raw, sin estado local Newman.

| Archivo | Descripción |
|---------|-------------|
| `README-v1.3.0-rc1.md` | Índice de la entrega |
| `GUIA-CONSUMO-v1.3.0-rc1.md` | Guía de consumo |
| `akubica-openapi-v1.3.0-rc1.yaml` | Contrato OpenAPI RC1 |
| `openapi-changelog-v1.3.0-rc1.md` | Delta vs v1.2.3 |
| `endpoint-matrix-v1.3.0-rc1.md` | Matriz endpoint / operationId |
| `CHECKLIST-endpoint-operationId-v1.3.0-rc1.md` | Checklist QA |
| `NEWMAN-staging-v1.3.0-rc1.md` | Resumen Newman staging |
| `NEWMAN-staging-local-runbook.md` | Runbook local/staging |
| `EVIDENCIA-NEWMAN-STAGING-v1.3.0-rc1.md` | Evidencia PASS Fase A + B |
| `Famedic-Akubica-v1.3.0-rc1.postman_collection.json` | Colección Postman |
| `Famedic-Akubica-v1.3.0-rc1.postman_environment.template.json` | Plantilla entorno (sin secretos) |
| `newman-staging-junit-sanitized.xml` | JUnit sanitizado |
| `newman-staging-report-sanitized.json` | Reporte Newman sanitizado |
| `newman-staging-summary.json` | Resumen Newman sanitizado |
| `SHA256SUMS` | Hashes de integridad |

**Excluido a propósito:** OTP, Bearer, HMAC, integration keys, teléfonos reales, cookies, `.rc1-staging-run-state.local.json`, `newman-staging-phase-a-raw.json`, entorno staging con secretos.
