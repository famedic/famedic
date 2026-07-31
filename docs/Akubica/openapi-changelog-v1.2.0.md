# OpenAPI changelog — v1.2.0 (P0-D1)

**Fecha:** 2026-07-31  
**Archivo:** `docs/Akubica/akubica-openapi.yaml`  
**Base previa:** v1.1.0 (22 operaciones, OTP email-centric, catálogo como Bearer)

## Resumen

Alineación del contrato OpenAPI con `routes/api/v1.php` tras P0-A/B/C (registro/login SMS, step-up, secure links, enforcement Bearer, Sanctum 180 min). Sin cambios de lógica de negocio.

## Autenticación

- Login/register documentan contrato **P0-A SMS** (`phone` / `challenge_id`) y legacy email cuando flags OFF.
- Añadidos `POST /auth/login/resend-code` y `POST /auth/register/resend-code`.
- Token: `expires_in` / `expires_at`; con `OTP_P0A_SANCTUM_3H_ENABLED` ≈ 10800 s (180 min). Sin refresh token.

## Step-up y secure links

- Results: `.../results/step-up/request|verify`, `.../results/secure-link`
- Invoices: `.../invoices/{invoice_id}/step-up/request|verify`, `.../secure-link`
- Público: `GET /secure-downloads/{token}` (token opaco 64 hex; **no** API key)

## Enforcement

- Header `X-Step-Up-Grant` en descargas Bearer.
- Documentados `STEP_UP_REQUIRED`, `STEP_UP_GRANT_INVALID`, `STEP_UP_EXPIRED`, `STEP_UP_REVOKED`.
- Flags OFF vs ON descritos en operation description.

## Catálogo / descargas / resto

- Catálogo marcado **público** (sin Bearer).
- Descargas PDF Bearer + metadata `RESULT_NOT_READY` / `INVOICE_NOT_READY`.
- Completadas rutas ausentes en v1.1.0 (cart totals/coupon/clear, checkout, appointments, user CRUD, invoice-request, etc.).

## Esquemas nuevos / alias

`ApiSuccessResponse`, `ApiErrorResponse`, `OtpChallenge`, `OtpGrant`, `SecureLink`, `TokenResponse`, más parámetros `InvoiceId`, `SecureDownloadToken`, security scheme `StepUpGrantHeader`.

## Fuera de OpenAPI

- Pruning `akubica:prune-otp` → solo runbook P0-C2.
- Variable obsoleta `OTP_P0A_SMS_DELIVERY_PROVIDER` no documentada como válida.

## Servers

Hosts canónicos `.com.mx` alineados a Postman staging/production.
