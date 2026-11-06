# Matriz de diferencias contractuales — RC1.1

Comparacion local semantica por documentos parseados. No constituye aprobacion contractual ni prueba de runtime. No se modificaron los historicos.

## Procedencia inmutable

| Referencia | Operaciones | SHA-256 | Estado |
|---|---:|---|---|
| Historica en commit `6ffb35c7cdaee26e8deb09398c85ff9bcf7c46e5` | 61 | `6ace97823aa528171f0d5c34c8ec9674d5730c429db2a95c18dc0c560f5b3749` | Aprobacion contractual PENDIENTE |
| Archivo actual etiquetado v1.2.3 | 67 | `c46ec1f832f2c8a22bdc9cefc2341ab1afc63e01f94dc6cd3946a5ae34e4e2d2` | Se preserva discrepancia, sin restaurar |
| RC1 conservado | 67 | `bbf110ffb55f9092b7a9678561509e11bfb0eb644251efc2b44ceec3a8d1398b` | Historico intacto |
| RC1.1 independiente | 67 | `567ec2c72d11896c73069a7d67aad923f656efb0fba28c3e1681595a73c71a06` | Candidato local, no distribuible |

La referencia historica contiene dos refs no resueltas: `#/components/responses/ForbiddenError` y `#/components/responses/TooManyRequestsError`. Se registran; no se repara el historico. La expansion de dependencias diferencia refs no resueltas de refs que ya existen en RC1.

## Conteos

| Comparacion | Agregadas | Retiradas | Modificacion directa | Efecto solo por componentes | Schemas agregados | Schemas modificados |
|---|---:|---:|---:|---:|---:|---:|
| Historica 61 a RC1 | 6 | 0 | 10 | 40 | 24 | 3 |
| Historica 61 a RC1.1 | 6 | 0 | 12 | 38 | 54 | 3 |
| RC1 a RC1.1 | 0 | 0 | 12 | 2 | 30 | 11 |
| Base actual 67 a RC1 | 0 | 0 | 6 | 45 | 8 | 2 |
| Base actual 67 a RC1.1 | 0 | 0 | 13 | 39 | 38 | 8 |

No se retiro ni renombro ningun operationId. Una operacion directa sin cambios puede quedar afectada por su schema compartido; se contabiliza aparte. Las diferencias incluyen metadata/ejemplos y no se presentan como un clasificador automatico de breaking changes.

## Historica 61 a RC1

### Operaciones agregadas

- `DELETE /cart/medical-benefit`
- `GET /cart/medical-benefit`
- `GET /memberships/plans`
- `GET /memberships/status`
- `POST /cart/medical-benefit`
- `POST /integrations/atix/phone-lookups`

### Operaciones con modificacion directa

- `DELETE /cart/items/{cart_item_id}`
- `GET /cart`
- `GET /cart/coupon`
- `GET /cart/totals`
- `GET /catalog/laboratory-tests`
- `GET /catalog/laboratory-tests/{laboratory_test_id}`
- `GET /checkout/prepare`
- `POST /auth/login/request-code`
- `POST /auth/login/verify-code`
- `POST /cart/items`

### Operaciones afectadas solo por dependencias

- `DELETE /cart`
- `DELETE /cart/coupon`
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
- `POST /cart/coupon`
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

### Schemas agregados

- `AtixPhoneLookupRequest`
- `AtixPhoneLookupResponse`
- `ContactRecognitionResponse`
- `ContextualOtpVerifiedTokenResponse`
- `LaboratoryCartItemCreateLegacy`
- `LaboratoryPricing`
- `LoginRequestCodeContextual`
- `MedicalServiceBenefitResponse`
- `MedicalServiceCart`
- `MedicalServiceCartBenefit`
- `MedicalServiceCartItem`
- `MedicalServiceCartResponse`
- `MedicalServiceCartTotals`
- `MedicalServiceCartTotalsResponse`
- `MedicalServiceCheckoutPrepareData`
- `MedicalServiceCheckoutPrepareResponse`
- `MembershipCartItemCreate`
- `MembershipPlan`
- `MembershipPlansResponse`
- `MembershipStatus`
- `MembershipStatusResponse`
- `PhoneLookupRecognizedData`
- `PhoneLookupUnknownData`
- `PreOtpContactRecognitionResponse`

### Schemas modificados

- `LaboratoryTest`
- `LaboratoryTestListItem`
- `LoginRequestCodeSms`

## RC1 a RC1.1

### Operaciones agregadas

Ninguno.

### Operaciones con modificacion directa

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

### Operaciones afectadas solo por dependencias

- `GET /cart/medical-benefit`
- `POST /integrations/atix/phone-lookups`

### Schemas agregados

- `CheckoutAddress`
- `CheckoutContact`
- `CheckoutPaymentMethod`
- `CheckoutWarning`
- `ContextualPersonalization`
- `LaboratoryCart`
- `LaboratoryCartItem`
- `LaboratoryCartItemAddedResponse`
- `LaboratoryCartItemRemovedResponse`
- `LaboratoryCartResponse`
- `LaboratoryCartTotals`
- `LaboratoryCartTotalsResponse`
- `LaboratoryCheckoutPrepareData`
- `LaboratoryCheckoutPrepareResponse`
- `LaboratoryCheckoutRequirements`
- `LaboratoryCheckoutTotals`
- `LaboratoryCouponAppliedResponse`
- `LaboratoryCouponPreview`
- `LaboratoryCouponRemovedResponse`
- `LaboratoryCouponResponse`
- `LaboratoryCouponTotals`
- `LoginRequestCodeEmailNonSms`
- `LoginRequestCodeSmsNonContextual`
- `LoginTokenData`
- `LoginVerifiedResponse`
- `MedicalServiceBenefitData`
- `MedicalServiceBenefitRemovedResponse`
- `MedicalServiceCartBaseTotals`
- `MedicalServiceCheckoutRequirements`
- `TokenUserProfile`

### Schemas modificados

- `AtixPhoneLookupRequest`
- `ContextualOtpVerifiedTokenResponse`
- `MedicalServiceBenefitResponse`
- `MedicalServiceCart`
- `MedicalServiceCartBenefit`
- `MedicalServiceCartItem`
- `MedicalServiceCartResponse`
- `MedicalServiceCartTotals`
- `MedicalServiceCartTotalsResponse`
- `MedicalServiceCheckoutPrepareData`
- `MedicalServiceCheckoutPrepareResponse`

## Interpretacion de compatibilidad

- Las seis operaciones agregadas respecto de la revision historica ya existian en RC1. RC1.1 no incorpora las rutas propuestas de beneficios opacos.
- Se mantiene TokenResponse heredado. Nuevos wrappers de login separan contextual/no contextual y cierran campos inesperados sin abrir globalmente ApiSuccessResponse.
- Se preservan los payloads de respuesta legacy que el codigo realmente serializa. Tipar una respuesta antes generica puede cambiar clientes generados y rechazar extensiones que antes pasaban por un schema vacio; requiere revision del consumidor.
- Se declara code nullable en beneficio existente y se elimina precio fijo de ejemplo de item medico: el precio real viene del catalogo configurado.
- Se conservan los tres flags con semanticas distintas. No se altera la operacion payment-link ni se acredita su restriccion funcional para Leo.
- La regex principal E.164 coincide con la del FormRequest; la clausula adicional de no-whitespace del candidato rechaza LF final que la regex PHP acepta. Alineacion runtime completa: PENDIENTE.
- Permanecen 26 anotaciones nullable heredadas fuera de los schemas corregidos. JSON Schema 2020-12 no les atribuye semantica nullable; la lista exacta esta en FASE-1-VALIDACION.json. Su migracion debe revisarse explicitamente antes de aprobar cobertura contractual integral.
- La matriz completa de nombres de todos los diffs esta en FASE-1-VALIDACION.json. No incluye datos de reportes expuestos.
