# P0-B1 — Step-up OTP e infraestructura de grants

## Objetivo

Infraestructura reusable para otorgar **grants temporales** tras verificar un OTP SMS
de step-up, aplicable a resultados (este bloque) y facturas (siguiente).

**Estado posterior:** P0-B2/B3/B4 cubren secure links, step-up facturas y enforcement Bearer.
Este documento describe el contrato de grants (aún vigente).

## Feature flags

| Variable | Rol |
|----------|-----|
| `OTP_P0A_STEP_UP_RESULTS_ENABLED` | Activa request/verify de resultados |
| `OTP_P0A_ANTI_ABUSE_ENABLED` | Obligatorio si step-up ON |
| `OTP_P0A_SMS_DELIVERY_ENABLED` | Obligatorio para SMS |
| `OTP_P0A_STEP_UP_GRANT_TTL_MINUTES` | TTL del grant (default 10) |
| `OTP_P0A_STEP_UP_BIND_SANCTUM_TOKEN` | Liga grant al PAT actual (default true) |
| `OTP_P0A_STEP_UP_BIND_PURPOSE` | Liga al purpose (default true) |
| `OTP_P0A_STEP_UP_BIND_RESOURCE` | Liga a resource_type + resource_id (default true) |

Con flags OFF:

- `POST .../results/step-up/*` → `503 FEATURE_DISABLED`
- Descargas Bearer existentes **sin cambio**

## Propósitos

| Purpose | Uso |
|---------|-----|
| `step_up_results` | Step-up para resultados de un pedido |
| `step_up_invoices` | Step-up facturas (P0-B3) |

**No** reutilizar `akubica_login` ni `akubica_register`. Cross-purpose → `INVALID_CODE`.

## Endpoints (resultados)

Ambos requieren `auth:sanctum` + `api.customer` + ownership del pedido (404 si ajeno).

### Request

`POST /api/v1/orders/{order_id}/results/step-up/request`

Body: `{}` (campos `phone`, `purpose`, `user_id`, etc. **prohibidos**).

Teléfono: resuelto del usuario autenticado (verificado según policy).

Respuesta `202`:

- `challenge_id`, `purpose=step_up_results`, `channel=sms`
- `destination_masked`, `resource_type=laboratory_purchase`, `resource_id`
- `expires_at`, `resend_available_at`
- Sin OTP, sin grant, sin token

Reenviar: volver a llamar request (invalida challenge activo previo del mismo scope).

### Verify

`POST /api/v1/orders/{order_id}/results/step-up/verify`

```json
{ "challenge_id": "<uuid>", "code": "<OTP_FROM_SMS>" }
```

Éxito `200`:

```json
{
  "success": true,
  "data": {
    "grant_id": "<uuid>",
    "purpose": "step_up_results",
    "resource_type": "laboratory_purchase",
    "resource_id": 0,
    "expires_at": "YYYY-MM-DDTHH:mm:ssZ"
  }
}
```

Sin URL de descarga.

## Challenge

- `purpose`: `step_up_results`
- `context_type`: `laboratory_purchase`
- `context_id`: id del pedido
- `user_id`: autenticado
- Canal SMS; sin fallback email
- Consumo único; delivery failure invalida el challenge

## Grant (`otp_step_up_grants`)

Binding obligatorio (según flags):

- `user_id`
- `purpose`
- `resource_type` + `resource_id`
- `personal_access_token_id` (Sanctum PAT; columna nullable solo si bind=false / TransientToken)

Un grant no sirve para otro usuario, propósito, recurso, token, ni tras expirar/revocar.
Al emitir uno nuevo se revocan grants activos del mismo binding (`superseded`).

## Servicios

| Clase | Rol |
|-------|-----|
| `AkubicaStepUpOtpService` | Request/verify OTP resultados |
| `OtpStepUpGrantService` | Emitir / validar / revocar grants |
| `OtpAbusePolicy` + delivery P0-A | Anti-abuso + SMS |

## Seguridad

- Ownership antes de SMS
- 404 para pedido ajeno (sin SMS)
- Sin PII/OTP/tokens en responses ni logs de aplicación del flujo
- Descarga Bearer **aún no** consulta grants

## Relacionados

- P0-B2 secure links resultados · P0-B3 facturas · P0-B4 enforcement Bearer
