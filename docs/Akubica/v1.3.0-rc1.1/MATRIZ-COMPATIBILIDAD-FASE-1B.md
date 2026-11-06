# Compatibilidad contractual — Fase 1B

No se restauraron, regeneraron ni sobrescribieron los tres contratos historicos. Aprobacion formal de la revision historica de 61 operaciones: PENDIENTE.

| Referencia | Operaciones | SHA-256 |
|---|---:|---|
| Historica en `6ffb35c7cdaee26e8deb09398c85ff9bcf7c46e5` | 61 | `6ace97823aa528171f0d5c34c8ec9674d5730c429db2a95c18dc0c560f5b3749` |
| Archivo actual etiquetado v1.2.3 | 67 | `c46ec1f832f2c8a22bdc9cefc2341ab1afc63e01f94dc6cd3946a5ae34e4e2d2` |
| RC1 historico | 67 | `bbf110ffb55f9092b7a9678561509e11bfb0eb644251efc2b44ceec3a8d1398b` |
| RC1.1 Fase 1B | 67 | `a737a0c8380ac470f20d95bc3f08739cf2cd38a71092e1e80c4b1a959905010f` |

| Comparacion | Altas | Bajas | Cambio directo | Solo dependencias | Schemas agregados | Schemas modificados |
|---|---:|---:|---:|---:|---:|---:|
| Historica 61 a candidato actual | 6 | 0 | 12 | 41 | 54 | 9 |
| Base etiquetada 1.2.3 (67) a candidato actual | 0 | 0 | 13 | 45 | 38 | 18 |
| RC1 historico a candidato actual | 0 | 0 | 12 | 16 | 30 | 22 |

## Historica 61 a candidato actual

### Altas

- `DELETE /cart/medical-benefit`
- `GET /cart/medical-benefit`
- `GET /memberships/plans`
- `GET /memberships/status`
- `POST /cart/medical-benefit`
- `POST /integrations/atix/phone-lookups`
### Modificacion directa

- `DELETE /cart/coupon`
- `DELETE /cart/items/{cart_item_id}`
- `GET /cart`
- `GET /cart/coupon`
- `GET /cart/totals`
- `GET /catalog/laboratory-tests`
- `GET /catalog/laboratory-tests/{laboratory_test_id}`
- `GET /checkout/prepare`
- `POST /auth/login/request-code`
- `POST /auth/login/verify-code`
- `POST /cart/coupon`
- `POST /cart/items`
### Efecto por dependencia

- `DELETE /cart`
- `DELETE /laboratory-appointments/{appointment_id}`
- `DELETE /user/addresses/{address_id}`
- `DELETE /user/contacts/{contact_id}`
- `DELETE /user/tax-profiles/{tax_profile_id}`
- `GET /laboratory-appointments`
- `GET /laboratory-appointments/requirements`
- `GET /orders`
- `GET /orders/invoices`
- `GET /orders/results`
- `GET /orders/{order_id}/invoice-request/status`
- `GET /orders/{order_id}/invoices`
- `GET /orders/{order_id}/products`
- `GET /orders/{order_id}/results`
- `GET /orders/{order_id}/status`
- `GET /secure-downloads/{token}`
- `GET /user/addresses`
- `GET /user/contacts`
- `GET /user/family`
- `GET /user/payment-methods`
- `GET /user/tax-profiles`
- `POST /auth/login/resend-code`
- `POST /auth/register`
- `POST /auth/register/resend-code`
- `POST /checkout/draft`
- `POST /checkout/payment-link`
- `POST /laboratory-appointments`
- `POST /orders/{order_id}/invoice-request`
- `POST /orders/{order_id}/invoices/{invoice_id}/secure-link`
- `POST /orders/{order_id}/invoices/{invoice_id}/step-up/request`
- `POST /orders/{order_id}/invoices/{invoice_id}/step-up/verify`
- `POST /orders/{order_id}/results/secure-link`
- `POST /orders/{order_id}/results/step-up/request`
- `POST /orders/{order_id}/results/step-up/verify`
- `POST /user/addresses`
- `POST /user/contacts`
- `POST /user/tax-profiles`
- `PUT /orders/{order_id}/cancel`
- `PUT /user/addresses/{address_id}`
- `PUT /user/contacts/{contact_id}`
- `PUT /user/tax-profiles/{tax_profile_id}`
### Schemas modificados

- `AddressWriteRequest`
- `CancelOrderRequest`
- `Invoice`
- `LaboratoryTest`
- `LaboratoryTestListCategory`
- `LaboratoryTestListItem`
- `LoginRequestCodeSms`
- `OtpChallenge`
- `TaxProfileWriteRequest`

## Base etiquetada 1.2.3 (67) a candidato actual

### Altas

Ninguno.
### Modificacion directa

- `DELETE /cart/coupon`
- `DELETE /cart/items/{cart_item_id}`
- `DELETE /cart/medical-benefit`
- `GET /cart`
- `GET /cart/coupon`
- `GET /cart/totals`
- `GET /checkout/prepare`
- `POST /auth/login/request-code`
- `POST /auth/login/verify-code`
- `POST /cart/coupon`
- `POST /cart/items`
- `POST /cart/medical-benefit`
- `POST /integrations/atix/phone-lookups`
### Efecto por dependencia

- `DELETE /cart`
- `DELETE /laboratory-appointments/{appointment_id}`
- `DELETE /user/addresses/{address_id}`
- `DELETE /user/contacts/{contact_id}`
- `DELETE /user/tax-profiles/{tax_profile_id}`
- `GET /cart/medical-benefit`
- `GET /catalog/laboratory-tests`
- `GET /catalog/laboratory-tests/{laboratory_test_id}`
- `GET /laboratory-appointments`
- `GET /laboratory-appointments/requirements`
- `GET /memberships/status`
- `GET /orders`
- `GET /orders/invoices`
- `GET /orders/results`
- `GET /orders/{order_id}/invoice-request/status`
- `GET /orders/{order_id}/invoices`
- `GET /orders/{order_id}/products`
- `GET /orders/{order_id}/results`
- `GET /orders/{order_id}/status`
- `GET /secure-downloads/{token}`
- `GET /user/addresses`
- `GET /user/contacts`
- `GET /user/family`
- `GET /user/payment-methods`
- `GET /user/tax-profiles`
- `POST /auth/login/resend-code`
- `POST /auth/register`
- `POST /auth/register/resend-code`
- `POST /checkout/draft`
- `POST /checkout/payment-link`
- `POST /laboratory-appointments`
- `POST /orders/{order_id}/invoice-request`
- `POST /orders/{order_id}/invoices/{invoice_id}/secure-link`
- `POST /orders/{order_id}/invoices/{invoice_id}/step-up/request`
- `POST /orders/{order_id}/invoices/{invoice_id}/step-up/verify`
- `POST /orders/{order_id}/results/secure-link`
- `POST /orders/{order_id}/results/step-up/request`
- `POST /orders/{order_id}/results/step-up/verify`
- `POST /user/addresses`
- `POST /user/contacts`
- `POST /user/tax-profiles`
- `PUT /orders/{order_id}/cancel`
- `PUT /user/addresses/{address_id}`
- `PUT /user/contacts/{contact_id}`
- `PUT /user/tax-profiles/{tax_profile_id}`
### Schemas modificados

- `AddressWriteRequest`
- `AtixPhoneLookupRequest`
- `AtixPhoneLookupResponse`
- `CancelOrderRequest`
- `ContactRecognitionResponse`
- `Invoice`
- `LaboratoryPricing`
- `LaboratoryTest`
- `LaboratoryTestListCategory`
- `LaboratoryTestListItem`
- `MedicalServiceBenefitResponse`
- `MedicalServiceCart`
- `MedicalServiceCartBenefit`
- `MedicalServiceCartItem`
- `MedicalServiceCartResponse`
- `MembershipStatus`
- `OtpChallenge`
- `TaxProfileWriteRequest`

## RC1 historico a candidato actual

### Altas

Ninguno.
### Modificacion directa

- `DELETE /cart/coupon`
- `DELETE /cart/items/{cart_item_id}`
- `DELETE /cart/medical-benefit`
- `GET /cart`
- `GET /cart/coupon`
- `GET /cart/totals`
- `GET /checkout/prepare`
- `POST /auth/login/request-code`
- `POST /auth/login/verify-code`
- `POST /cart/coupon`
- `POST /cart/items`
- `POST /cart/medical-benefit`
### Efecto por dependencia

- `GET /cart/medical-benefit`
- `GET /catalog/laboratory-tests`
- `GET /catalog/laboratory-tests/{laboratory_test_id}`
- `GET /memberships/status`
- `GET /orders/{order_id}/invoices`
- `POST /auth/login/resend-code`
- `POST /auth/register`
- `POST /auth/register/resend-code`
- `POST /integrations/atix/phone-lookups`
- `POST /orders/{order_id}/invoices/{invoice_id}/step-up/request`
- `POST /orders/{order_id}/results/step-up/request`
- `POST /user/addresses`
- `POST /user/tax-profiles`
- `PUT /orders/{order_id}/cancel`
- `PUT /user/addresses/{address_id}`
- `PUT /user/tax-profiles/{tax_profile_id}`
### Schemas modificados

- `AddressWriteRequest`
- `AtixPhoneLookupRequest`
- `CancelOrderRequest`
- `ContextualOtpVerifiedTokenResponse`
- `Invoice`
- `LaboratoryPricing`
- `LaboratoryTest`
- `LaboratoryTestListCategory`
- `LaboratoryTestListItem`
- `MedicalServiceBenefitResponse`
- `MedicalServiceCart`
- `MedicalServiceCartBenefit`
- `MedicalServiceCartItem`
- `MedicalServiceCartResponse`
- `MedicalServiceCartTotals`
- `MedicalServiceCartTotalsResponse`
- `MedicalServiceCheckoutPrepareData`
- `MedicalServiceCheckoutPrepareResponse`
- `MembershipStatus`
- `OtpChallenge`
- `PhoneLookupRecognizedData`
- `TaxProfileWriteRequest`

## Posibles cambios incompatibles y dependencias

| Cambio | Riesgo de compatibilidad | Dependencia/verificacion |
|---|---|---|
| E.164 /D y valor JSON original | Rechaza clientes que dependian de trim de entradas invalidas. E.164 canonico valido sigue admitido. | 8 positivos y 20 negativos con middleware real; revisar consumidores JSON firmados. Otros formatos no declarados no se certifican. |
| 22 uniones con null | Clientes estrictos/generados pueden necesitar tipos union aunque runtime ya podia emitir null. No se cambia el payload de negocio. | Matriz nullable y regeneracion controlada de SDK/clientes, sin asumir uso externo conocido. |
| Cuatro anotaciones retiradas | Generadores antiguos pueden distinguir opcional de nullable. JSON Schema 3.1 ya rechazaba null en estos nodos antes. | Revisar defaults de clientes; no sustituir campos omitidos por null. |
| Respuestas tipadas y oneOf corregido de Fase 1 | Puede romper extensiones que antes pasaban por data generica; preserva respuestas documentadas desde serializers. | Fixtures por schema especifico y operacion; prueba de cliente generado pendiente. |
| operationId y rutas | No se renombro ni retiro ninguno respecto de RC1. Seis altas frente a 61 ya estaban en RC1. | Verificar baseline formal y dependencias de consumidores. |
| Autenticacion declarada | No se cambia esquema Bearer/HMAC ni ruta payment-link. Los scopes generales no prueban restriccion de Leo. | Revision offline de 67 operaciones PASS; enforcement desplegado y cierre Fase 0 pendientes. |
| Referencias historicas rotas | ForbiddenError y TooManyRequestsError faltan en la revision de 61; no se altera para pasar checks. | Confirmar artefacto formalmente aprobado; diferencias por refs resueltas se separan de altas. |
| Validador integral | Sin una ejecucion integral no se certifica compatibilidad total OpenAPI 3.1. | Herramienta aprobada instalada y evidencia vinculada al hash actual, pendiente. |

Los conteos incluyen cambios de metadata/ejemplos y expansion de dependencias; no son un clasificador formal de todos los breaking changes. FASE-1B-VALIDACION.json conserva el detalle completo, incluidos schemas agregados y listas vacias de eliminados/operationId cambiados. El antiguo informe de Fase 1 refleja el candidato anterior; esta matriz y su hash corresponden al candidato actualizado.
