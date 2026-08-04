# OpenAPI changelog — v1.2.1 (P1-A6)

Fecha: 2026-08-03

## Cambios aditivos (compatibles)

- Envelope de error: `error.retryable` (boolean) y `error.correlation_id` (string).
- Header request opcional `X-Correlation-ID`.
- Header response siempre `X-Correlation-ID` (JSON y PDF).
- Éxito: sin cambios de body; correlation solo en header.
- Componentes: `parameters.XCorrelationId`, `headers.X-Correlation-ID`.
- Documentación: `docs/Akubica/p1-a6-errors-correlation.md`.

## No breaking

- Códigos de dominio y HTTP status intactos.
- `fields` / `details` siguen opcionales.
