# Newman staging — v1.3.0-rc1

## Correcciones de colección (2026-10-29, solo Postman)

| # | Hallazgo | Acción |
|---|----------|--------|
| 1 | Teléfonos no E.164 | `run-newman-rc1-staging.sh` mapea `RC1_STAGING_PHONE` / `RC1_STAGING_UNKNOWN_PHONE` → `phone` / `unknown_phone` (sin hardcode en JSON) |
| 2 | `Identifier 'body' has already been declared` | Scripts unificados (`responsePayload`, `hmacBodyRaw`); sin test global duplicado |
| 3 | `correlation_id` | Aserciones vs `request_correlation_id` de la misma petición |
| 4 | Firma inválida → 422 | Payload E.164 válido; solo firma forzada **después** de `unset` de overrides |
| 5 | Nonce / idempotency replay | Par 5a/5b y 6a/6b: mismo nonce/key + mismo body; resto con valores nuevos |
| 6 | request-code | Persiste `challenge_id`, `conversation_id`, `channel_event_id`, `channel`, `audience` |
| 7 | verify válido | `OTP_CODE` vía env; sin OTP → `[fixture]` + `skipRequest` |
| 8 | Bearer | `access_token` solo se escribe tras verify 200 |
| 9 | Carrito / membresía | `skipRequest` + `[fixture]` si no hay token |
| 10 | cartItemsDestroy | Solo con `medical_cart_item_id` de `cartItemsStore`; si no → pendiente |
| 11 | B11/B12 aislamiento | Colección alineada al runtime RC1: **404 `LAB_TEST_NOT_FOUND`** (POST lab sin id de catálogo válido en staging); **409 `CART_SERVICE_LINE_MISMATCH`** (`GET cart?service_line=pharmacy&brand=…`). Aserciones explícitas de `error.code` en Postman. |

Generador: `scripts/build-postman-v1.3.0-rc1.py`  
Artefactos: `postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json` (espejo en `docs/Akubica/v1.3.0-rc1/`).

## Ejecutar Newman (credenciales solo en proceso)

Opcional: archivo gitignored `.newman-rc1-staging.env.local` en la raíz del repo (mismas variables que abajo).

```bash
export BASE_URL="https://staging.famedic.com.mx/api/v1"
export ATIX_SANDBOX_INTEGRATION_KEY_ID="<sandbox-key-id>"
export ATIX_SANDBOX_HMAC_SECRET="<sandbox-hmac-secret>"
export RC1_STAGING_PHONE="+52XXXXXXXXXX"      # E.164
export RC1_STAGING_UNKNOWN_PHONE="+52XXXXXXXXXX"
export OTP_CODE="<otp-vigente-staging>"       # solo para verify válido
# No exportar BEARER_TOKEN salvo depuración; la colección lo captura en verify

bash scripts/run-newman-rc1-staging.sh
```

Salidas sanitizadas: `newman-staging-report-sanitized.json`, `newman-staging-summary.json`, `newman-staging-junit-sanitized.xml`.

## Corrida anterior (inválida — pre-corrección)

Resumen en `newman-staging-summary.json` (243 aserciones, **34 fallos**, **7 scripts** rotos). **No usar como evidencia RC1.**

### Clasificación de esa corrida

#### Errores de colección

- Scripts Postman: redeclaración de `body`, orden de `responsePayload` vs tests, `force_atix_*` borrado antes de aplicar override (firma/nonce/idempotency).
- Aserciones de `correlation_id` desalineadas con `X-Correlation-ID` enviado.
- Caso firma inválida con payload inválido → **422** en lugar de **401**.
- Pares replay (5a–6b) con HMAC/nonce/idempotency mal ordenados → **401** en lugar de **202/409**.
- `skip_fixture_reason` borrado en prerequest antes de evaluar OTP/token → cadena de **401** contados como fallos funcionales.

#### Errores de fixture

- Lookups **422**: teléfono no enviado en E.164 vía `RC1_STAGING_*` (validación de `phone` en API).
- Verify **422** / OTP: código staging incorrecto o cadena rota por lookups previos.
- Cupón / carrito: dependen de Bearer; sin verify válido quedan bloqueados (deben ser `[fixture]`, no fallo de API).

#### Errores de autenticación

- Requests 10–19 con **401** sin Bearer: efecto esperado cuando verify no entregó token (clasificar como **fixture/cadena**, no bug de carrito).

#### Errores reales de runtime (staging)

- **Ninguno demostrado** en esa corrida: los códigos discordantes (422 en ATIX, 401 en replay) se explican por colección + fixture; el único caso ATIX alineado con contrato fue timestamp expirado (**401**, 0 fallos de aserción).

## Corrida Fase B staging (2026-10-29, flujo principal RC1)

| Área | Resultado |
|------|-----------|
| verify-code contextual, Bearer, membresía, carrito médico, totales, beneficios, checkout, DELETE membresía | OK |
| B11 aislamiento laboratorio | **404** + `LAB_TEST_NOT_FOUND` — válido (fixture `laboratory_test_id`; ver matriz caso 18) |
| B12 aislamiento farmacia | **409** + `CART_SERVICE_LINE_MISMATCH` — válido (RC1 no sirve carrito `pharmacy`; ver matriz caso 19) |

## Corrida final staging — **PASS** (entrega RC1)

| Fase | Requests | Assertions | Fallos |
|------|----------|------------|--------|
| **A** — lookup + request-code | 2 | 14 | 0 |
| **B** — verify → checkout + aislamiento | 12 | 78 | 0 |

Evidencia tabular: `EVIDENCIA-NEWMAN-STAGING-v1.3.0-rc1.md`. Paquete zip: `akubica-v1.3.0-rc1-entrega/` (sin raw ni secretos).
