# API V1 — Errores y esquemas reutilizables

Envelope real (`App\Http\Responses\ApiResponse`):

**Éxito**
```json
{ "success": true, "data": {} }
```

**Error**
```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Descripción",
    "fields": {},
    "details": {},
    "retryable": false,
    "correlation_id": "opaque-id"
  }
}
```

`fields` / `details` son opcionales (se omiten si null).
`retryable` y `correlation_id` son **aditivos P1-A6** y están siempre en errores.

Header de respuesta (éxito y error, también PDF): `X-Correlation-ID`.
Éxito: correlation solo en header (no en `data`).

Clasificación `retryable`: `App\Support\Api\V1\ApiErrorRetryability` — ver [`p1-a6-errors-correlation.md`](./p1-a6-errors-correlation.md).

## Componentes OpenAPI (alias)

| Schema OpenAPI | Uso |
|----------------|-----|
| `ApiSuccessResponse` / `SuccessResponse` | Envelope éxito |
| `ApiErrorResponse` / `ErrorResponse` | Envelope error |
| `OtpChallenge` | Challenge OTP (login/register/step-up) |
| `OtpGrant` | Grant step-up tras verify |
| `SecureLink` | Emisión de liga (`url`, `expires_at`, `max_opens`) |
| `TokenResponse` / `AuthTokenData` | Bearer + `expires_in` / `expires_at` |
| `Pagination` | Listados paginados |
| `Order` / `OrderSummary` | Pedido |
| `Result` / `OrderResult*` | Resultados |
| `Invoice` / `UserInvoice` | Facturas |

## Códigos de dominio (solo los que genera el código)

### Infra / genéricos

| Código | HTTP típico | Origen |
|--------|-------------|--------|
| `UNAUTHENTICATED` | 401 | Handler / Sanctum |
| `FORBIDDEN` | 403 | `api.customer` / ownership carrito |
| `NOT_FOUND` | 404 | Handler genérico |
| `VALIDATION_ERROR` | 422 | FormRequest / normalización |
| `TOO_MANY_REQUESTS` | 429 | Throttle Laravel |
| `INTERNAL_ERROR` | 500 | Catch controlado |
| `FEATURE_DISABLED` | 503 | Flags OFF / cancel / medications |
| `CATALOG_UNAVAILABLE` | 503 | Medications deshabilitado |

### Auth / OTP

| Código | HTTP | Origen |
|--------|------|--------|
| `DELIVERY_FAILED` | 503 | Vonage / delivery |
| `OTP_CONFIGURATION_INVALID` | 503 | Flags incompletos |
| `OTP_TEMPORARY_UNAVAILABLE` | 503 | Reserva delivery |
| `INVALID_CODE` | 422 | OTP incorrecto / mismatch / señuelo |
| `NO_ACTIVE_CODE` | 422 | Challenge/intent ausente |
| `CODE_EXPIRED` | 422 | Expirado |
| `CODE_ALREADY_USED` | 422 | Consumido |
| `CODE_INVALIDATED` | 422 | Invalidado |
| `ATTEMPTS_EXHAUSTED` | 422 | Intentos agotados |
| `LOGIN_REQUIRED` | 409 | Registro ya completado |
| `EMAIL_ALREADY_REGISTERED` | 409 | Legacy register |
| `PHONE_ALREADY_REGISTERED` | 409 | Legacy register |
| `OTP_COOLDOWN` / rate-limit codes | 429 | `OtpRateLimitDecision` |

### Ownership / documentos

| Código | HTTP | Origen |
|--------|------|--------|
| `ORDER_NOT_FOUND` | 404 | Soft-deny (inexistente = ajeno) |
| `INVOICE_NOT_FOUND` | 404 | Soft-deny |
| `RESULT_NOT_READY` | 409 | PDF resultados no listo |
| `INVOICE_NOT_READY` | 409 | PDF factura no listo |
| `RESULTS_NOT_AVAILABLE` | 404 | Metadata results |
| `DOCUMENT_STORAGE_UNAVAILABLE` | 503 | Storage |

### Step-up / grants / secure links

| Código | HTTP | Origen |
|--------|------|--------|
| `STEP_UP_REQUIRED` | 403 | Header `X-Step-Up-Grant` ausente (enforcement ON) |
| `STEP_UP_GRANT_INVALID` | 403/422 | Binding incorrecto / inexistente |
| `STEP_UP_EXPIRED` | 403 | Grant expirado |
| `STEP_UP_REVOKED` | 403 | Grant revocado |
| `SECURE_LINK_NOT_FOUND` | 404 | Token desconocido |
| `SECURE_LINK_EXPIRED` | 410 | Expirada |
| `SECURE_LINK_CONSUMED` | 410 | Max opens |
| `SECURE_LINK_REVOKED` | 410 | Revocada |

### Carrito / checkout / citas / facturación (existentes)

Incluyen entre otros: `LAB_TEST_NOT_FOUND`, `CART_ITEM_NOT_FOUND`, `ITEM_ALREADY_IN_CART`, `COUPON_NOT_FOUND`, `EMPTY_CART`, `COUPON_EXPIRED`, `COUPON_NOT_APPLICABLE`, `CHECKOUT_NOT_READY`, `APPOINTMENT_REQUIRED`, `APPOINTMENT_NOT_FOUND`, `APPOINTMENT_ALREADY_EXISTS`, `APPOINTMENT_NOT_REQUIRED`, `INVOICE_ALREADY_EXISTS`, `INVOICE_REQUEST_ALREADY_EXISTS`, `ORDER_NOT_INVOICEABLE`, `TAX_PROFILE_NOT_FOUND`, `ADDRESS_NOT_FOUND`, `CONTACT_NOT_FOUND`, `RFC_ALREADY_EXISTS`.

### Idempotencia HTTP (fase 1)

| Código | HTTP | `retryable` | Origen |
|--------|------|-------------|--------|
| `IDEMPOTENCY_KEY_CONFLICT` | 409 | false | Misma `Idempotency-Key`, payload distinto |
| `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 409 | true | Lease `processing` vigente (`Retry-After`) |
| `IDEMPOTENCY_OPERATION_UNCERTAIN` | 409 | false | Lease vencido / 5xx / body demasiado grande sin respuesta persistida |

Header opcional de request: `Idempotency-Key` (8–128, `[A-Za-z0-9._-]`), solo con `API_V1_IDEMPOTENCY_ENABLED=true`.
Replay completado: header `Idempotency-Replayed: true` + `X-Correlation-ID` original.
No garantiza exactly-once ante efectos externos inciertos.

No inventar códigos adicionales en clientes.

## Soft-deny

Recursos ajenos responden **404** con el mismo código que “no encontrado” (`ORDER_NOT_FOUND`, `INVOICE_NOT_FOUND`, etc.), excepto ítems de carrito ajenos → **403**.
