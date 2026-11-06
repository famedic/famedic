# Matriz endpoint / operationId / caso Postman (v1.3.0-rc1)

| # | Caso Postman | Método | Ruta | operationId | HTTP esperado |
|---|--------------|--------|------|-------------|---------------|
| 1 | atixPhoneLookup reconocido sin OTP | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` | 202 |
| 2 | teléfono desconocido | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` | 202 |
| 3 | firma inválida | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` | 401 |
| 4 | timestamp expirado | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` | 401 |
| 5 | nonce replay | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` | 409 |
| 6 | idempotency replay | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` | 409 |
| 7 | request-code contextual | POST | `/auth/login/request-code` | `authLoginRequestCode` | 202 |
| 8 | verify-code inválido | POST | `/auth/login/verify-code` | `authLoginVerifyCode` | 422 |
| 9 | verify-code válido | POST | `/auth/login/verify-code` | `authLoginVerifyCode` | 200 |
| 10 | captura Bearer | GET | `/memberships/status` | `membershipStatusShow` | 200 |
| 11 | agregar basic_annual | POST | `/cart/items` | `cartItemsStore` | 201 |
| 12 | consultar carrito | GET | `/cart` | `cartIndex` | 200 |
| 13 | consultar totales | GET | `/cart/totals` | `cartTotals` | 200 |
| 14 | aplicar cupón/saldo | POST | `/cart/medical-benefit` | `medicalServiceBenefitApply` | 200/404/409 |
| 15 | retirar cupón/saldo | DELETE | `/cart/medical-benefit` | `medicalServiceBenefitRemove` | 200 |
| 16 | preparar checkout | GET | `/checkout/prepare` | `checkoutPrepare` | 200 |
| 17 | retirar membresía | DELETE | `/cart/items/{id}` | `cartItemsDestroy` | 200/404 |
| 18 | aislamiento vs laboratorio | POST | `/cart/items` | `cartItemsStore` | 404/201/409/403/422 |
| 19 | aislamiento farmacia | GET | `/cart` | `cartIndex` | 409/403/422 |

### Notas de aislamiento (casos 18–19)

| Caso | HTTP observado (staging RC1) | `error.code` | Razón contractual |
|------|------------------------------|--------------|-------------------|
| 18 — POST body laboratorio (`laboratory_test_id` + `brand`, sin `service_line`) | **404** | `LAB_TEST_NOT_FOUND` | OpenAPI RC1 documenta 404 cuando el estudio no existe o no coincide con `brand`; no es rechazo por carrito médico (carritos laboratorio y `medical_services` son independientes). Con `laboratory_test_id` válido en el entorno puede responder **201**. |
| 18 — duplicado o mezcla inválida | 409 | `ITEM_ALREADY_IN_CART` / `CART_SERVICE_LINE_MISMATCH` | Mismos códigos que flujo laboratorio/médico documentados en OpenAPI para `cartItemsStore`. |
| 19 — `GET /cart?service_line=pharmacy&brand=olab` | **409** | `CART_SERVICE_LINE_MISMATCH` | En RC1, `service_line` en query solo opera para `medical_services`; combinar `pharmacy` con `brand` de laboratorio es inválido (`CartController::index`). Farmacia no expone carrito Akubica en esta versión. |

## Endpoints OpenAPI sin caso Postman RC1 (referencia)

Operaciones documentadas en v1.3.0-rc1 pero no cubiertas por los 19 casos mínimos: auth legacy, register, resend-code, checkout draft/payment-link, pedidos, citas, user profile, secure downloads, resto de catálogo laboratorio, etc. (matriz completa: 67 `operationId` en OpenAPI).
