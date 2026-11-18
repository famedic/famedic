# Checklist de endpoints — Akúbica v1.3.0

Marcar cada caso solo con evidencia sanitizada: HTTP, `operationId`, `X-Correlation-ID`, fecha y código de error. Nunca registrar secretos, OTP, Bearer, teléfonos completos, contexto ni respuestas sensibles.

| Listo | Método | Endpoint | operationId | Criterio |
|---|---|---|---|---|
| [ ] | GET | `/catalog/laboratory-tests?brand={brand}` | `catalogLaboratoryTests` | Público; `price_cents` entero; sin precio enviado por cliente. |
| [ ] | GET | `/catalog/laboratory-tests/{laboratory_test_id}` | `catalogShowLaboratoryTest` | Detalle y pricing del servidor. |
| [ ] | GET | `/memberships/plans` | `membershipPlansIndex` | Planes y precios canónicos. |
| [ ] | GET | `/memberships/status` | `membershipStatusShow` | Bearer; no acceso pre-OTP. |
| [ ] | GET | `/cart?service_line=medical_services` | `cartIndex` | Aislamiento de carrito médico. |
| [ ] | GET | `/cart/totals?service_line=medical_services` | `cartTotals` | Totales no negativos y recalculados. |
| [ ] | POST | `/cart/medical-benefit` | `medicalServiceBenefitApply` | **Perfil native; para Leo DIFERIDO (C6/L16, 403).** Cupón/saldo aplicado sin importar enviado por cliente. Leo sólo informa que los beneficios elegibles se aplican en el checkout de FAMEDIC. |
| [ ] | DELETE | `/cart/medical-benefit?service_line=medical_services` | `medicalServiceBenefitRemove` | **Perfil native; para Leo DIFERIDO (C6/L16, 403).** Beneficio retirado. |
| [ ] | GET | `/checkout/prepare?service_line=medical_services` | `checkoutPrepare` | `assistant_can_pay=false`, `can_continue_on_platform=true`, `can_continue_to_payment_platform=false`; `continuity_url` sólo first-party y con B24 (staging); sin pago, liga de pago ni gateway. |
| [ ] | GET | `/checkout/prepare?service_line=laboratory&brand={brand}` | `checkoutPrepare` | **Batch 2 (D-2).** Respuesta Leo minimizada (`LeoLaboratoryCheckoutPrepareResponse`) con las mismas tres banderas y continuidad first-party sólo en staging. |
| [ ] | POST | `/integrations/atix/phone-lookups` | `atixPhoneLookup` | HMAC, nonce, timestamp, idempotencia y anti-enumeración. |

## ATIX negativos

| Caso | Resultado esperado |
|---|---|
| Firma inválida | `401 ATIX_SIGNATURE_INVALID`. |
| Timestamp expirado | `401 ATIX_TIMESTAMP_EXPIRED`. |
| Nonce repetido | `409 ATIX_NONCE_REPLAY`. |
| Canal inválido | `422` por validación de canal. |
| Replay idempotente | Mismo resultado contractual y replay detectable. |
| Conflicto de payload | `409 ATIX_EVENT_REPLAY_CONFLICT`. |
| Teléfono desconocido | `202` anti-enumeración, sin datos privados. |

## Fuera de alcance

`POST /checkout/payment-link` sólo se ejecuta como prueba negativa de seguridad de Leo (B25/S14: HTTP 403, sin liga ni cobro); nunca con credencial de pagos. No ejecutar compras de laboratorio, citas, farmacia, pagos, activaciones de membresía ni operaciones de producción.
