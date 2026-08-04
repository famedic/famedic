# P1-A6 — Errores compatibles y correlation ID transversal

**Estado:** implementado en `feature/apis-akubica` (sin commit automático).  
**Alcance:** API V1 `/api/v1` únicamente. No incluye idempotencia, citas, catálogo, payment link ni handoff.

## Contrato

### Request

Header opcional:

```http
X-Correlation-ID: <opaque-value>
```

Reglas de aceptación (`AkubicaCorrelationId`):

- longitud 8–128;
- charset `[A-Za-z0-9._-]`;
- rechazo de espacios, `@`, y valores con más de dos `.` (anti-JWT);
- si falta o es inválido → se genera UUID v4 opaco;
- nunca secuencial ni derivado de PII.

### Response

- Siempre: header `X-Correlation-ID` (JSON y PDF).
- Éxito: **solo header** (body `success`/`data` sin cambios).
- Error: body aditivo:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Los datos enviados no son válidos.",
    "fields": {},
    "details": {},
    "retryable": false,
    "correlation_id": "…"
  }
}
```

`fields` / `details` siguen omitiéndose cuando son null.  
`retryable` y `correlation_id` **siempre** presentes en errores.

`error.correlation_id` === header `X-Correlation-ID`.

## retryable

Clasificación central: `App\Support\Api\V1\ApiErrorRetryability`.

| retryable | Ejemplos |
|-----------|----------|
| `false` | `VALIDATION_ERROR`, `INVALID_CODE`, ownership 404, `STEP_UP_*`, secure link 410, `FEATURE_DISABLED`, `OTP_CONFIGURATION_INVALID`, `INTERNAL_ERROR` |
| `true` | `TOO_MANY_REQUESTS`, `OTP_COOLDOWN`, `OTP_RATE_LIMITED`, `OTP_BLOCKED`, `OTP_TEMPORARY_UNAVAILABLE`, `DELIVERY_FAILED`, `DOCUMENT_STORAGE_UNAVAILABLE`, `CATALOG_UNAVAILABLE` |

Códigos desconocidos → `false`. No se deduce retry solo por HTTP 5xx.

## Componentes

| Pieza | Rol |
|-------|-----|
| `AssignAkubicaCorrelationId` | Middleware (`api.correlation`) en grupo `routes/api/v1.php` |
| `AkubicaCorrelationId` | Resolve / validate / bind / generate |
| `ApiErrorRetryability` | Mapa de códigos |
| `ApiResponse` | Envelope + header en success/error |
| OTP delivery | Usa `currentOrGenerate()` en lugar de UUID aislado |

## Logging

`Log::shareContext(['correlation_id' => …])` por request.

Allowlist (no ampliar con secretos):

- `correlation_id`
- (existente OTP) `purpose`, `channel`, `provider_alias`, `result_class`, …

Prohibido: Authorization, OTP, teléfono, correo, grant, secure URL, token/hash, bodies, datos clínicos.

## Compatibilidad

- No se renombran códigos ni se cambian status HTTP.
- Clientes que ignoran campos nuevos siguen funcionando.
- OpenAPI: campos aditivos en `ApiErrorResponse` + parámetro/header documentados.

## Tests

`tests/Feature/Api/V1/AkubicaErrorsCorrelationP1a6Test.php`

## Postman

Variable de colección `correlation_id` + script de captura del header (ver `postman/README.md`).
