# Checklist endpoint / operationId — v1.3.0-rc1

Marca cada fila al validar con Postman/Newman contra sandbox.

| ✓ | Caso | Método | Ruta | operationId |
|---|------|--------|------|-------------|
| | Lookup reconocido sin OTP | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` |
| | Teléfono desconocido | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` |
| | Firma inválida | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` |
| | Timestamp expirado | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` |
| | Nonce replay | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` |
| | Idempotency replay | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` |
| | Request-code contextual | POST | `/auth/login/request-code` | `authLoginRequestCode` |
| | Verify-code inválido | POST | `/auth/login/verify-code` | `authLoginVerifyCode` |
| | Verify-code válido + Bearer | POST | `/auth/login/verify-code` | `authLoginVerifyCode` |
| | Membresía con Bearer | GET | `/memberships/status` | `membershipStatusShow` |
| | Agregar basic_annual | POST | `/cart/items` | `cartItemsStore` |
| | Consultar carrito | GET | `/cart` | `cartIndex` |
| | Consultar totales | GET | `/cart/totals` | `cartTotals` |
| | Aplicar cupón/saldo | POST | `/cart/medical-benefit` | `medicalServiceBenefitApply` |
| | Retirar beneficio | DELETE | `/cart/medical-benefit` | `medicalServiceBenefitRemove` |
| | Preparar checkout | GET | `/checkout/prepare` | `checkoutPrepare` |
| | Retirar membresía | DELETE | `/cart/items/{cart_item_id}` | `cartItemsDestroy` |
| | Aislamiento laboratorio | POST | `/cart/items` | `cartItemsStore` |
| | Aislamiento farmacia | GET | `/cart` | `cartIndex` |

Detalle de códigos HTTP y `error.code`: `endpoint-matrix-v1.3.0-rc1.md` (casos 18–19: **404 `LAB_TEST_NOT_FOUND`** en lab con fixture de catálogo; **409 `CART_SERVICE_LINE_MISMATCH`** en `service_line=pharmacy` + `brand`).

OpenAPI RC1 documenta **67** operationId (misma matriz que v1.2.3); esta checklist cubre el mínimo Leo RC1.
