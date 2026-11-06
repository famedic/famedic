# Evidencia Newman staging — Akúbica v1.3.0-rc1

Documento sanitizado para entrega. **No** contiene OTP, Bearer, secretos HMAC, integration keys, teléfonos reales ni cookies.

## Metadatos de la corrida

| Campo | Valor |
|-------|--------|
| **Fecha de ejecución (UTC)** | 2026-10-29 (Fase A previa; Fase B ~21:56 UTC) |
| **Rama Git** | `feature/akubica-api-v1.3.0` |
| **Commit** | `481a8871153cb4995e3fb05f6fba682d45ae33d4` |
| **Build / etiqueta** | `v1.3.0-rc1` (`info.version` OpenAPI) |
| **Ambiente** | staging (`https://staging.famedic.com.mx/api/v1`) |
| **Colección** | `Famedic-Akubica-v1.3.0-rc1.postman_collection.json` |
| **OpenAPI** | `akubica-openapi-v1.3.0-rc1.yaml` |
| **Resultado global** | **PASS** |

## Resumen por fase

| Fase | Carpeta Newman | Requests | Assertions | Fallos | Resultado |
|------|----------------|----------|------------|--------|-----------|
| **A** | `00 - RC1 Fase A (staging OTP)` | 2 | 14 | 0 | **PASS** |
| **B** | `02 - RC1 Fase B (Bearer y carrito)` | 12 | 78 | 0 | **PASS** |
| **Total RC1 Leo (A+B)** | — | 14 | 92 | 0 | **PASS** |

Artefactos sanitizados adjuntos en el paquete: `newman-staging-junit-sanitized.xml`, `newman-staging-report-sanitized.json`, `newman-staging-summary.json`.

---

## Fase A — OTP y contexto ATIX

| # | Request (Postman) | Método | Ruta | operationId | HTTP | Assertions | `correlation_id` (sanitizado) | Clasificación | Observaciones | Resultado |
|---|-------------------|--------|------|-------------|------|------------|-------------------------------|---------------|---------------|-----------|
| A1 | A1 atixPhoneLookup reconocido (Fase A) | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` | **202** | 7 | `[REDACTED-uuid]` | contrato ATIX RC1 | `recognition_status` / personalización; sin OTP ni Bearer | **PASS** |
| A2 | A2 authLoginRequestCode contextual (Fase A) | POST | `/auth/login/request-code` | `authLoginRequestCode` | **202** | 7 | `[REDACTED-uuid]` | auth contextual | `challenge_id` generado; binding conversación Fase A | **PASS** |

**Checks Fase A:** `X-Correlation-ID` coherente; sin `account_context_token` en respuestas; HMAC válido (credenciales solo en proceso local, no en este documento).

---

## Fase B — Bearer, membresía y carrito médico

| # | Request (Postman) | Método | Ruta | operationId | HTTP | Assertions | `correlation_id` (sanitizado) | Clasificación | Observaciones | Resultado |
|---|-------------------|--------|------|-------------|------|------------|-------------------------------|---------------|---------------|-----------|
| B1 | B1 authLoginVerifyCode invalido | POST | `/auth/login/verify-code` | `authLoginVerifyCode` | **422** | 7 | `[REDACTED-uuid]` | validación OTP | Código inválido; envelope de error | **PASS** |
| B2 | B2 authLoginVerifyCode valido contextual | POST | `/auth/login/verify-code` | `authLoginVerifyCode` | **200** | 8 | `[REDACTED-uuid]` | auth RC1 | Bearer capturado en colección (valor **no** registrado aquí); `otp_verified` | **PASS** |
| B3 | B3 membershipStatusShow | GET | `/memberships/status` | `membershipStatusShow` | **200** | 6 | `[REDACTED-uuid]` | membresía M1 | Estado con Bearer | **PASS** |
| B4 | B4 cartItemsStore basic_annual | POST | `/cart/items` | `cartItemsStore` | **201** | 5 | `[REDACTED-uuid]` | carrito M2B | `service_line=medical_services`, plan `basic_annual` | **PASS** |
| B5 | B5 cartIndex medical_services | GET | `/cart` | `cartIndex` | **200** | 5 | `[REDACTED-uuid]` | carrito M2B | Query `service_line=medical_services` | **PASS** |
| B6 | B6 cartTotals medical_services | GET | `/cart/totals` | `cartTotals` | **200** | 5 | `[REDACTED-uuid]` | carrito M2B | Totales médicos | **PASS** |
| B7 | B7 medicalServiceBenefitApply cupon o saldo | POST | `/cart/medical-benefit` | `medicalServiceBenefitApply` | **200** | 7 | `[REDACTED-uuid]` | beneficios M2C | Cupón/saldo sintético staging | **PASS** |
| B8 | B8 medicalServiceBenefitRemove | DELETE | `/cart/medical-benefit` | `medicalServiceBenefitRemove` | **200** | 5 | `[REDACTED-uuid]` | beneficios M2C | Retiro de beneficio | **PASS** |
| B9 | B9 checkoutPrepare medical_services | GET | `/checkout/prepare` | `checkoutPrepare` | **200** | 7 | `[REDACTED-uuid]` | checkout RC1 Leo | `assistant_can_pay=false`, `can_continue_on_platform=true` | **PASS** |
| B10 | B10 cartItemsDestroy membresia | DELETE | `/cart/items/{cart_item_id}` | `cartItemsDestroy` | **200** | 5 | `[REDACTED-uuid]` | carrito M2B | Limpieza ítem membresía | **PASS** |
| B11 | B11 Aislamiento laboratorio vs medical_services | POST | `/cart/items` | `cartItemsStore` | **404** | 8 | `[REDACTED-uuid]` | aislamiento / catálogo | `error.code=LAB_TEST_NOT_FOUND` (fixture `laboratory_test_id` staging) | **PASS** |
| B12 | B12 Aislamiento farmacia (carrito pharmacy vacio) | GET | `/cart` | `cartIndex` | **409** | 8 | `[REDACTED-uuid]` | aislamiento RC1 | `error.code=CART_SERVICE_LINE_MISMATCH` (`service_line=pharmacy` + `brand`) | **PASS** |

**Checks Fase B:** sin `payment_link` / Stripe en respuestas; `error.correlation_id` cuando aplica; B11/B12 con aserción explícita de `error.code`.

---

## Clasificación transversal

| Tipo | Fase A | Fase B |
|------|--------|--------|
| Contrato HTTP / OpenAPI RC1 | 2/2 PASS | 12/12 PASS |
| Seguridad (sin fugas en reportes sanitizados) | OK | OK |
| Aislamiento líneas de servicio | N/A | B11 + B12 PASS |

## Diferencias runtime vs contrato

Ninguna bloqueante observada en esta corrida para el flujo Leo RC1 (A+B). Checkout en staging alineado con flags documentados (`assistant_can_pay`, `can_continue_on_platform`).

## Excluido de evidencia pública (por diseño)

- OTP, Bearer, HMAC, integration key, teléfonos E.164 reales
- `newman-staging-phase-a-raw.json`, reportes raw, `.rc1-staging-run-state.local.json`
- Variables de entorno locales (`.newman-rc1-staging.env.local`)
