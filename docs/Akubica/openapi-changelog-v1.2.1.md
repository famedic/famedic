# OpenAPI changelog — v1.2.1 (P1-A6 + cableado documental)

Fecha: 2026-08-03 · actualización documental: 2026-08-04

## Cambios aditivos (compatibles)

- Envelope de error: `error.retryable` (boolean) y `error.correlation_id` (string).
- Header request opcional `X-Correlation-ID`.
- Header response siempre `X-Correlation-ID` (JSON y PDF).
- Éxito: sin cambios de body; correlation solo en header.
- Componentes: `parameters.XCorrelationId`, `headers.X-Correlation-ID`.
- Documentación: `docs/Akubica/p1-a6-errors-correlation.md`.

## Cableado documental (sin bump de versión)

- `$ref` de `XCorrelationId` en las **61** operaciones.
- `$ref` de header `X-Correlation-ID` en respuestas inline y en responses compartidas.
- `Idempotency-Key` + `Idempotency-Replayed` alineados a las **9** rutas con `api.idempotency`
  (no solo `payment-link`).
- Aclaración de alcance: 61 ops; secure links y Bearer PDF incluidos; XML fuera de API V1;
  Business Audit / `web_checkout` no son endpoints públicos.
- Vocabulario público de beneficios: códigos `COUPON_*` (crédito = denominación interna).

## No breaking

- Códigos de dominio y HTTP status intactos.
- `fields` / `details` siguen opcionales.
- Versión OpenAPI permanece **1.2.1**.
