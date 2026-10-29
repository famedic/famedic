# Changelog OpenAPI — v1.3.0-rc1

Base: `akubica-openapi.yaml` (v1.2.3, congelado). Destino: `akubica-openapi-v1.3.0-rc1.yaml`.

## Añadido / corregido

- **Versión** `1.3.0-rc1` y tags `Memberships`, `Integrations`.
- **ATIX** `atixPhoneLookup`: esquema de seguridad `atixHmac`; respuesta sin OTP (`recognition_status`, `assurance_level`, `personalization`).
- **Auth contextual**: `LoginRequestCodeContextual`; `422` incluye `ACCOUNT_CONTEXT_REQUIRED`; verify-code con `ContextualOtpVerifiedTokenResponse` (Bearer + `otp_verified` + `assurance_level`).
- **Errores**: alias `ForbiddenError`, `TooManyRequestsError`; respuestas `AccountContextRequired`, `AtixUnauthorized`.
- **Carrito médico**: refs explícitas a `MedicalServiceCartResponse`, `MedicalServiceCartTotalsResponse`.
- **`GET /cart` (`cartIndex`)**: respuesta **409** `CART_SERVICE_LINE_MISMATCH` (RC1 solo acepta `service_line=medical_services`; rechaza `pharmacy` y combinaciones incompatibles con `brand`).
- **Checkout RC1**: `MedicalServiceCheckoutPrepareResponse` con `assistant_can_pay=false` y `can_continue_on_platform`.
- **E.164** en `AtixPhoneLookupRequest.phone` (patrón existente conservado).
- Eliminadas extensiones `x-proposed-v1-3-0-context` en auth ya implementado.

## Sin cambios de compatibilidad

- Mismos **67** `operationId` que v1.2.3.
- Rutas y métodos HTTP intactos salvo descripciones y esquemas de respuesta.

## Pendiente de alinear en runtime (fuera de Fase 3)

- Payload exacto de `checkoutPrepare` para flags Leo documentados.
- Endpoints laboratorio/farmacia transaccionales fuera del alcance RC1 Leo.
