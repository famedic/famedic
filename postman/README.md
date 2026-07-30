# Famedic Akubica — Postman

Colección y environments para la API Akubica (`/api/v1`).

- **Rama:** `feature/apis-akubica`
- **Actualizado:** 2026-07-30
- **Schemas:** Collection v2.1.0 · Environment v2.1.0

## Importar en Postman

1. Open Postman → **Import**
2. Importar `Famedic-Akubica-API-v1.postman_collection.json`
3. Importar el environment deseado (recomendado: **Staging**)
4. Seleccionar el environment en el selector superior derecho

Archivos:

| Archivo | Uso |
|---------|-----|
| `Famedic-Akubica-API-v1.postman_collection.json` | Colección |
| `Famedic-Akubica-Local.postman_environment.json` | Local `http://localhost:8080/api/v1` |
| `Famedic-Akubica-Staging.postman_environment.json` | Staging `https://staging.famedic.com.mx/api/v1` |
| `Famedic-Akubica-Production.postman_environment.json` | Production (solo lectura autorizada) |

## Environment recomendado

Usar **Famedic Akubica - Staging** para QA. La URL proviene del environment Postman previo (no se tomó de código Laravel).

## Variables QA — Registro (carpeta 01)

- `test_email` — email de prueba **nuevo**
- `duplicate_test_email` — otro email (mismo `test_phone` en 01.5)
- `test_phone` — E.164 de prueba controlado
- `test_full_name` — ya tiene default por environment
- `correct_otp` — dígitos del SMS tras **01.1** (nunca commitear)
- `incorrect_otp` — default `000000`

## Variables QA — Login (carpeta 02)

- `login_phone` — teléfono de un usuario **ya registrado**
- `unknown_login_phone` — teléfono que no exista (anti-enumeración)
- `login_incorrect_otp` — default `000000`
- `login_correct_otp` — dígitos del SMS tras **02.1** (manual; nunca desde BD/logs)
- `login_challenge_public_id` — se llena en 02.1 / 02.5
- `login_decoy_challenge_id` — se llena en 02.6 (no sobrescribe el challenge real)

Tras verify exitoso: `access_token`, `user_id` (y `customer_id` si el contrato lo expone).

## Flujo OTP registro (orden 01.1–01.6)

1. **01.1** Solicitar OTP por SMS → 202 / stop si `DELIVERY_FAILED`
2. **01.2** OTP incorrecto → 422 `INVALID_CODE`
3. **01.3** OTP correcto → 200 + `access_token` (crea User+Customer; no hay `complete`)
4. **01.4** Challenge consumido → 422
5. **01.5** Teléfono duplicado → 202 señuelo (`duplicate_challenge_id` only)
6. **01.6** Verify señuelo → 422 `INVALID_CODE`

## Flujo OTP login SMS P0-A (orden 02.1–02.8)

Requiere flags: `OTP_P0A_AKUBICA_LOGIN_ENABLED`, `OTP_P0A_ANTI_ABUSE_ENABLED`, `OTP_P0A_SMS_DELIVERY_ENABLED`.

1. **02.1** Solicitar OTP login por SMS (`phone`) → 202 `login_challenge_public_id`
2. **02.2** OTP incorrecto → 422
3. **02.3** OTP correcto → 200 + token (User/Customer existentes; no crea)
4. **02.4** Challenge consumido → 422
5. **02.5** Reenviar OTP (respetar cooldown) → 202
6. **02.6** Teléfono inexistente → 202 señuelo (`login_decoy_challenge_id`)
7. **02.7** Verify señuelo → 422 sin token
8. **02.8** Revoke token (opcional)

Si login P0-A está **off**: el mismo endpoint acepta body legacy `{ "email" }` y `resend-code` responde `503 FEATURE_DISABLED`.

## P0-B4 — Enforcement Bearer (`X-Step-Up-Grant`)

Carpetas **18** (resultados) y **19** (facturas): tras el flujo step-up/secure-link, requests **18.7–18.9** / **19.7–19.9** documentan descarga Bearer.

- Header: `X-Step-Up-Grant: {{results_step_up_grant_id}}` o `{{invoice_step_up_grant_id}}` (no query string).
- Sin header: **200** si enforcement OFF; **403** `STEP_UP_REQUIRED` si ON (`OTP_P0A_STEP_UP_BEARER_*`).
- Grant cross-purpose / inválido con enforcement ON → **403** `STEP_UP_GRANT_INVALID`.
- Secure link GET (18.4 / 19.4) sigue sin Bearer ni step-up header.
- Vars `results_step_up_grant_id` / `invoice_step_up_grant_id` vacías en environments trackeados.

## Variables QA — Secure links / resultados (carpeta 18)

- `results_step_up_challenge_id` — challenge id del step-up (se llena en el flujo 18)
- `results_step_up_grant_id` — grant id tras OTP step-up correcto
- `results_secure_download_url` — URL de descarga segura (secret; nunca commitear)
- `results_correct_otp` — OTP del SMS step-up (secret; manual; nunca desde BD/logs)

## Variables QA — Secure links / facturas (carpeta 19)

- `invoice_order_id` — pedido propio con factura
- `invoice_id` — id de factura del pedido
- `invoice_step_up_challenge_id` — challenge id del step-up (se llena en el flujo 19)
- `invoice_step_up_grant_id` — grant id tras OTP step-up correcto
- `invoice_secure_download_url` — URL de descarga segura (secret; nunca commitear)
- `invoice_correct_otp` — OTP del SMS step-up (secret; manual; nunca desde BD/logs)

## Seguridad

- No PII real en git; secrets vacíos en environments trackeados
- No ejecutar escritura en **Production** sin autorización
- Stop conditions: HTTP 500, `DELIVERY_FAILED`, OTP leak en response body
- Preferir variables `secret` en Postman para token/OTP/email/phone
- Nunca `console.log` de OTP, teléfono completo o token

## Limitaciones

- OpenAPI del repo puede estar incompleto vs esta colección
- Copias bajo `docs/Akubica/` pueden estar gitignored; la copia trackeable está en `/postman`

## Despliegue (ops)

Tras activar flags de login SMS en un entorno:

```bash
php artisan optimize:clear
php artisan config:cache
```

Si hay workers de cola procesando notificaciones/jobs OTP, reiniciarlos con el proceso habitual del entorno (p. ej. `php artisan queue:restart`) — no ejecutar contra production desde esta guía.

## Copias

- **Canónica (git):** `/postman/`
- Espejo docs: `docs/Akubica/guias-y-postman/postman/`
- `docs/Akubica/Docs akubica/postman/` → ver `OBSOLETE.txt`
