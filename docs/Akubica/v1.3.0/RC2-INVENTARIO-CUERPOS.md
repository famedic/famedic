# Inventario de cuerpos de solicitud · rc2-NEWMAN y LEO-FUNCIONAL (Batch 1 · rev5)

Revisión documental local (IT-0026), sin QA y sin red. Cada solicitud de las dos colecciones funcionales tiene una fila: **117 de 117** en total (NEWMAN 69 de 69, LEO-FUNCIONAL 48 de 48).

## Método y cobertura exacta

- **Esquema:** el `requestBody` de la operación del OpenAPI `akubica-openapi-v1.3.0-rc2.json` que corresponde al método y la ruta. Si la operación declara `x-request-schema-by-consumer`, se usa el esquema del consumidor de la solicitud (Leo o native, según la credencial del encabezado). Validación JSON Schema 2020-12 con formatos.
- **Variables:** se sustituyen como texto, igual que Postman, con valores sintéticos (teléfonos `+5255000000xx`, OTP `000000`, UUID fijo, `test_id_*` 11/12, `lab_brand=olab`, `lab_brand_not_granted=swisslab`, `tax_profile_ref=tp_3Lm9aZ`). `"{{x}}"` queda string; `{{x}}` sin comillas toma el tipo del texto.
- **Pre-request:** se analizan los scripts de solicitud y de colección (no hay scripts de carpeta). Sólo las A01–A07 reescriben el cuerpo, y lo hacen con el mismo contenido ya resuelto, para firmarlo con HMAC. Las variables que fijan (`hmac_tamper`, `hmac_ts_offset`, `hmac_fixed_nonce`) no aparecen en el cuerpo.
- **Captura dinámica:** además, las dos colecciones se ejecutaron con Newman 6.2.3 **sin red** (`unshare -rn`) y valores sintéticos, para que corrieran los pre-request reales. Se registró el cuerpo que se habría enviado (evento `beforeRequest`); no se recibió ninguna respuesta. 114 de 117 solicitudes llegaron a ese punto. B18, R05 y R08, que no tienen cuerpo, se omiten por diseño (`*_never_set`). **Los 45 cuerpos capturados coinciden con la resolución estática.**
- **Clasificación:**
  - `CONFORME`: válido contra el esquema.
  - `INVALIDO_DELIBERADO`: inválido a propósito. Requiere que la prueba espere el rechazo contractual indicado y que la solicitud lo declare `INVALIDO_DELIBERADO` en su descripción.
  - `DISCREPANCIA_PENDIENTE`: cualquier otro cuerpo no conforme.
  - `N/A`: la solicitud no lleva cuerpo.
- **Fuera de esta revisión:**
  - encabezados, consulta y parámetros de ruta;
  - cuerpos de respuesta (se validan los ejemplos en `validaciones/`);
  - `rc2-90` y las colecciones históricas;
  - la existencia real de ids y fixtures, porque los valores son sintéticos y sólo se comprueba la forma.
- **Lo que no es:** una validación total del contrato ni un PASS de staging. Es la forma de los cuerpos de estas 117 solicitudes.

## Totales

| Colección | Estado | CONFORME | INVALIDO_DELIBERADO | DISCREPANCIA_PENDIENTE | N/A | Total |
|---|---|---|---|---|---|---|
| `…-rc2-NEWMAN` | inicial (rev4 `2af45719`) | 22 | 0 | 6 | 41 | 69 |
| `…-rc2-NEWMAN` | **final (rev5)** | 24 | 4 | 0 | 41 | 69 |
| `…-rc2-LEO-FUNCIONAL` | inicial (rev4 `2af45719`) | 14 | 0 | 3 | 31 | 48 |
| `…-rc2-LEO-FUNCIONAL` | **final (rev5)** | 15 | 2 | 0 | 31 | 48 |

**Discrepancias iniciales (9) y cómo se resolvieron, en una sola pasada:**

| Solicitud | Inicial | Resolución rev5 | Final |
|---|---|---|---|
| NEWMAN B06b | `laboratory_test_id` string y `quantity` (no admitido) | Entero sin comillas y sin `quantity`, según `LaboratoryCartItemCreateLegacy`; se omite si el id no es entero o la marca no es válida | CONFORME |
| NEWMAN B25 | Sin `brand` (requerida) | `{"brand": "{{lab_brand}}"}`, válido para `checkoutPaymentLink`; credencial Leo y 403 sin cambios | CONFORME |
| LEO LF-L17a | Igual que B25 | Igual que B25 | CONFORME |
| NEWMAN A07 | Inválido sin marca explícita | Se marca INVALIDO_DELIBERADO (negativo intencional de validación, 422); cuerpo y aserción sin cambios | INVALIDO_DELIBERADO |
| NEWMAN B22 | Inválido sin marca explícita | Se marca INVALIDO_DELIBERADO (intención de farmacia, 503 PHARMACY_SUSPENDED); sin otros cambios | INVALIDO_DELIBERADO |
| NEWMAN R07a | Inválido sin marca explícita | Se marca INVALIDO_DELIBERADO (plan inexistente, 422); sin otros cambios | INVALIDO_DELIBERADO |
| NEWMAN R07a2 | Inválido sin marca explícita | Se marca INVALIDO_DELIBERADO (autorización antes que validación, 403); sin otros cambios | INVALIDO_DELIBERADO |
| LEO LF-L13g | Inválido sin marca explícita | Se marca INVALIDO_DELIBERADO (entero para Leo, 422); sin otros cambios | INVALIDO_DELIBERADO |
| LEO LF-L13x | Inválido sin marca explícita | Se marca INVALIDO_DELIBERADO (cuerpo vacío a propósito, 403); sin otros cambios | INVALIDO_DELIBERADO |

No se cambió ninguna aserción, credencial ni código HTTP esperado. **DISCREPANCIA_PENDIENTE final: 0.**

## Nota rev6 (IT-0027)

B25 (`rc2-NEWMAN`) exige ahora `leo_negative_writes_authorized` con valor exactamente `"1"`, igual que LF-L17a. Su cuerpo, su clasificación (CONFORME) y su justificación no cambian; sólo la columna de efectos del pre-request agrega `omite si f !== '1'`. Los totales siguen iguales a los de rev5. En esta revisión no se repitió la revisión general: se regeneró el inventario completo desde las colecciones y sólo cambió esa celda.

## Inventario completo (una fila por solicitud)

El CSV `RC2-INVENTARIO-CUERPOS.csv` trae además los errores de esquema completos y los efectos del pre-request sin abreviar.

| Colección | ID | Método | Ruta | Esquema | Cuerpo resuelto (sin secretos) | Pre-request | Clasificación | Justificación |
|---|---|---|---|---|---|---|---|---|
| NEWMAN | A01 | POST | `/integrations/atix/phone-lookups` | AtixPhoneLookupRequest | `{"phone": "+52******0001", "conversation_id": "conv-r20261118-phone_registered", "channel_event_id": "evt-3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "channel": "whatsapp", "audience": "leo", "personalization": {"allowed": tr` | resuelve y fija el mismo cuerpo antes de firmar HMAC (sin cambio de contenido); agrega encabezados (firma HMAC), no toca el cuerpo; omite si !keyId \|\| !secret | **CONFORME** | Válido contra AtixPhoneLookupRequest (JSON Schema 2020-12, con formatos). Esperado: 202. |
| NEWMAN | A02 | POST | `/integrations/atix/phone-lookups` | AtixPhoneLookupRequest | `{"phone": "+52******0002", "conversation_id": "conv-r20261118-phone_unknown", "channel_event_id": "evt-3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "channel": "whatsapp", "audience": "leo", "personalization": {"allowed": true}` | resuelve y fija el mismo cuerpo antes de firmar HMAC (sin cambio de contenido); agrega encabezados (firma HMAC), no toca el cuerpo; omite si !keyId \|\| !secret | **CONFORME** | Válido contra AtixPhoneLookupRequest (JSON Schema 2020-12, con formatos). Esperado: 202. |
| NEWMAN | A03 | POST | `/integrations/atix/phone-lookups` | AtixPhoneLookupRequest | `{"phone": "+52******0001", "conversation_id": "conv-r20261118-phone_registered", "channel_event_id": "evt-3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "channel": "whatsapp", "audience": "leo", "personalization": {"allowed": tr` | fija hmac_tamper=1 (no está en el cuerpo); resuelve y fija el mismo cuerpo antes de firmar HMAC (sin cambio de contenido); agrega encabezados (firma HMAC), no t | **CONFORME** | Válido contra AtixPhoneLookupRequest (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (401) es de autorización, no de esquema. Esperado: 401. |
| NEWMAN | A04 | POST | `/integrations/atix/phone-lookups` | AtixPhoneLookupRequest | `{"phone": "+52******0001", "conversation_id": "conv-r20261118-phone_registered", "channel_event_id": "evt-3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "channel": "whatsapp", "audience": "leo", "personalization": {"allowed": tr` | fija hmac_ts_offset=-1200 (no está en el cuerpo); resuelve y fija el mismo cuerpo antes de firmar HMAC (sin cambio de contenido); agrega encabezados (firma HMAC | **CONFORME** | Válido contra AtixPhoneLookupRequest (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (401) es de autorización, no de esquema. Esperado: 401. |
| NEWMAN | A05a | POST | `/integrations/atix/phone-lookups` | AtixPhoneLookupRequest | `{"phone": "+52******0002", "conversation_id": "conv-r20261118-phone_unknown", "channel_event_id": "evt-3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "channel": "whatsapp", "audience": "leo", "personalization": {"allowed": true}` | resuelve y fija el mismo cuerpo antes de firmar HMAC (sin cambio de contenido); agrega encabezados (firma HMAC), no toca el cuerpo; omite si !keyId \|\| !secret | **CONFORME** | Válido contra AtixPhoneLookupRequest (JSON Schema 2020-12, con formatos). Esperado: 202. |
| NEWMAN | A05b | POST | `/integrations/atix/phone-lookups` | AtixPhoneLookupRequest | `{"phone": "+52******0002", "conversation_id": "conv-r20261118-phone_unknown", "channel_event_id": "evt-3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "channel": "whatsapp", "audience": "leo", "personalization": {"allowed": true}` | fija hmac_fixed_nonce desde otra variable (no está en el cuerpo); resuelve y fija el mismo cuerpo antes de firmar HMAC (sin cambio de contenido); agrega encabez | **CONFORME** | Válido contra AtixPhoneLookupRequest (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (409) es de autorización, no de esquema. Esperado: 409. |
| NEWMAN | A06a | POST | `/integrations/atix/phone-lookups` | AtixPhoneLookupRequest | `{"phone": "+52******0002", "conversation_id": "conv-r20261118-phone_unknown", "channel_event_id": "evt-r20261118-A06", "channel": "whatsapp", "audience": "leo", "personalization": {"allowed": true}}` | resuelve y fija el mismo cuerpo antes de firmar HMAC (sin cambio de contenido); agrega encabezados (firma HMAC), no toca el cuerpo; omite si !keyId \|\| !secret | **CONFORME** | Válido contra AtixPhoneLookupRequest (JSON Schema 2020-12, con formatos). Esperado: 202. |
| NEWMAN | A06b | POST | `/integrations/atix/phone-lookups` | AtixPhoneLookupRequest | `{"phone": "+52******0002", "conversation_id": "conv-r20261118-phone_unknown", "channel_event_id": "evt-r20261118-A06", "channel": "whatsapp", "audience": "leo", "personalization": {"allowed": true}}` | resuelve y fija el mismo cuerpo antes de firmar HMAC (sin cambio de contenido); agrega encabezados (firma HMAC), no toca el cuerpo; omite si !keyId \|\| !secret | **CONFORME** | Válido contra AtixPhoneLookupRequest (JSON Schema 2020-12, con formatos). Esperado: 202. |
| NEWMAN | A07 | POST | `/integrations/atix/phone-lookups` | AtixPhoneLookupRequest | `{"phone": "+52******0001", "conversation_id": "conv-r20261118-phone_registered", "channel_event_id": "evt-3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "channel": "sms", "audience": "otro", "personalization": {"allowed": true}}` | resuelve y fija el mismo cuerpo antes de firmar HMAC (sin cambio de contenido); agrega encabezados (firma HMAC), no toca el cuerpo; omite si !keyId \|\| !secret | **INVALIDO_DELIBERADO** | Negativo intencional de validación: channel "sms" y audience "otro" fuera de los enum de AtixPhoneLookupRequest (whatsapp / leo); se espera 422 VALIDATION_ERROR sin efecto. Esperado: 422. |
| NEWMAN | A08 | POST | `/auth/login/request-code` | oneOf[LoginRequestCodeSmsNonContextual, LoginRequestCodeContextual, LoginRequestCodeEmailNonSms] | `{"phone": "+52******0001", "phone_country": "MX"}` | — | **CONFORME** | Válido contra oneOf[LoginRequestCodeSmsNonContextual, LoginRequestCodeContextual, LoginRequestCodeEmailNonSms] (JSON Schema 2020-12, con formatos). Esperado: 202. |
| NEWMAN | A09 | POST | `/auth/login/request-code` | oneOf[LoginRequestCodeSmsNonContextual, LoginRequestCodeContextual, LoginRequestCodeEmailNonSms] | `{"phone": "+52******0002", "phone_country": "MX"}` | — | **CONFORME** | Válido contra oneOf[LoginRequestCodeSmsNonContextual, LoginRequestCodeContextual, LoginRequestCodeEmailNonSms] (JSON Schema 2020-12, con formatos). Esperado: 202. |
| NEWMAN | A11a | POST | `/auth/login/verify-code` | oneOf[LoginVerifyCodeSms, LoginVerifyCodeLegacy] | `{"challenge_id": "3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "code": "<sintético>"}` | — | **CONFORME** | Válido contra oneOf[LoginVerifyCodeSms, LoginVerifyCodeLegacy] (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (422) es de autorización, no de esquema. Esperado: 422. |
| NEWMAN | A10 | POST | `/auth/login/verify-code` | oneOf[LoginVerifyCodeSms, LoginVerifyCodeLegacy] | `{"challenge_id": "3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "code": "<sintético>"}` | — | **CONFORME** | Válido contra oneOf[LoginVerifyCodeSms, LoginVerifyCodeLegacy] (JSON Schema 2020-12, con formatos). Esperado: 200. |
| NEWMAN | A10r | GET | `/memberships/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | A11b | POST | `/auth/login/verify-code` | oneOf[LoginVerifyCodeSms, LoginVerifyCodeLegacy] | `{"challenge_id": "3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "code": "<sintético>"}` | — | **CONFORME** | Válido contra oneOf[LoginVerifyCodeSms, LoginVerifyCodeLegacy] (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (401,409,422) es de autorización, no de esquema. Esperado: 401,409,422. |
| NEWMAN | A11c | POST | `/auth/login/verify-code` | oneOf[LoginVerifyCodeSms, LoginVerifyCodeLegacy] | `{"challenge_id": "3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "code": "<sintético>"}` | — | **CONFORME** | Válido contra oneOf[LoginVerifyCodeSms, LoginVerifyCodeLegacy] (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (401,422) es de autorización, no de esquema. Esperado: 401,422. |
| NEWMAN | A12 | GET | `/memberships/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 401. |
| NEWMAN | B01 | GET | `/catalog/laboratory-tests/{laboratory_test_id}` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B01r | GET | `/catalog/laboratory-tests` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B02 | GET | `/catalog/packages` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B02r | GET | `/catalog/packages/{package_id}` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B03 | GET | `/catalog/laboratory-tests/{laboratory_test_id}` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B03r | GET | `/catalog/laboratory-tests` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B04 | GET | `/catalog/laboratory-tests/{laboratory_test_id}` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B05 | GET | `/catalog/laboratory-tests/{laboratory_test_id}` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B06a | GET | `/catalog/laboratory-tests/{laboratory_test_id}` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B06b | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"laboratory_test_id": 12, "brand": "olab"}` | omite si pm.variables.get('test_id_mutable') && !/^[1-9][0-9]*$/.test(String(pm.variables.get('test_id_mutable'))); omite si b && ['olab', 'swisslab', 'jenner', | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Esperado: 201. |
| NEWMAN | B06r | GET | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B07 | GET | `/memberships/plans` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B08 | GET | `/memberships/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B09 | GET | `/memberships/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B10 | GET | `/memberships/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B11 | GET | `/memberships/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B12 | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"service_line": "medical_services", "item_type": "membership", "membership_plan_code": "basic_annual"}` | — | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Esperado: 201. |
| NEWMAN | B12r | GET | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B17 | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"service_line": "medical_services", "item_type": "membership", "membership_plan_code": "basic_annual"}` | — | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Esperado: 201. |
| NEWMAN | B13 | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"service_line": "medical_services", "item_type": "membership", "membership_plan_code": "basic_annual"}` | — | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (409) es de autorización, no de esquema. Esperado: 409. |
| NEWMAN | B13r | GET | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B23 | GET | `/checkout/prepare` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B24a | GET | `/checkout/prepare` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B24b | GET | `{{continuity_url}}` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 302. |
| NEWMAN | B25 | POST | `/checkout/payment-link` | inline (checkoutPaymentLink) | `{"brand": "olab"}` | omite si f !== '1'; omite si b && ['olab', 'swisslab', 'jenner', 'liacsa', 'azteca'].indexOf(b) === -1 | **CONFORME** | Válido contra inline (checkoutPaymentLink) (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (403) es de autorización, no de esquema. Esperado: 403. |
| NEWMAN | B26 | GET | `/memberships/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B16 | DELETE | `/cart/items/{cart_item_id}` | — | — | — | **N/A** | Sin cuerpo de solicitud (DELETE). Esperado: 200. |
| NEWMAN | B16r | GET | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B14 | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"service_line": "medical_services", "item_type": "membership", "membership_plan_code": "basic_annual"}` | — | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (409) es de autorización, no de esquema. Esperado: 409. |
| NEWMAN | B15 | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"service_line": "medical_services", "item_type": "membership", "membership_plan_code": "basic_annual"}` | — | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (409) es de autorización, no de esquema. Esperado: 409. |
| NEWMAN | B18 | GET | `/memberships/plans` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). |
| NEWMAN | B19 | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"service_line": "medical_services", "item_type": "membership", "membership_plan_code": "basic_annual"}` | — | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Esperado: 201,409. |
| NEWMAN | B20 | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"laboratory_test_id": 11, "brand": "olab"}` | omite si pm.variables.get('test_id_available') && !/^[1-9][0-9]*$/.test(String(pm.variables.get('test_id_available'))) | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Esperado: 201,409. |
| NEWMAN | B19r/B20r | GET | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B21 | DELETE | `/cart/items/{cart_item_id}` | — | — | — | **N/A** | Sin cuerpo de solicitud (DELETE). Esperado: 200. |
| NEWMAN | B21r | GET | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | B21x | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"laboratory_test_id": 11, "brand": "swisslab"}` | omite si pm.variables.get('test_id_available') && !/^[1-9][0-9]*$/.test(String(pm.variables.get('test_id_available'))); omite si nb && (['olab', 'swisslab', 'je | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (403) es de autorización, no de esquema. Esperado: 403. |
| NEWMAN | B22 | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"service_line": "pharmacy", "item_type": "medication", "medication_id": 1, "quantity": 1}` | — | **INVALIDO_DELIBERADO** | Intención de farmacia a propósito (service_line=pharmacy, item_type=medication, medication_id): ningún esquema de cartItemsStore la admite; el contrato responde 503 PHARMACY_SUSPENDED (retryable=false) sin efectos. Esperado: 503. |
| NEWMAN | R01 | GET | `/orders` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | R01b | GET | `/orders/{order_id}/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403,404. |
| NEWMAN | R02a | GET | `/orders/{order_id}/results` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | R02b | GET | `/orders/{order_id}/results` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | R02c | GET | `/orders/{order_id}/results/download` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403,409. |
| NEWMAN | R03a | GET | `/orders/{order_id}/invoices` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | R03b | GET | `/orders/{order_id}/invoices` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | R04 | GET | `/user/family` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | R05 | GET | `{{web_base_url}}/` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). |
| NEWMAN | R06 | GET | `/catalog/laboratory-brands` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| NEWMAN | R07a | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"service_line": "medical_services", "item_type": "membership", "membership_plan_code": "plan_inexistente"}` | — | **INVALIDO_DELIBERADO** | Negativo de validación: membership_plan_code "plan_inexistente" fuera de MembershipCartItemCreate (const basic_annual); se espera 422 VALIDATION_ERROR tipado. Esperado: 422. |
| NEWMAN | R07a2 | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"service_line": "medical_services"}` | — | **INVALIDO_DELIBERADO** | Autorización antes que validación: el cuerpo omite item_type a propósito; con credencial Leo se espera 403 CONSUMER_NOT_AUTHORIZED, no 422. Esperado: 403. |
| NEWMAN | R07b | GET | `/memberships/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 401. |
| NEWMAN | R08 | GET | `/memberships/plans` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). |
| LEO | LF00 | GET | `/memberships/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L07a | GET | `/orders` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L07b | GET | `/orders/{order_id}/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L07c | GET | `/orders/{order_id}/products` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L07d | GET | `/orders/{order_id}/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 404. |
| LEO | LF-L07x | PUT | `/orders/{order_id}/cancel` | CancelOrderRequest | `{}` | — | **CONFORME** | Válido contra CancelOrderRequest (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (403) es de autorización, no de esquema. Esperado: 403. |
| LEO | LF-L08a | GET | `/user/family` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L09a | GET | `/orders/results` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L09b | GET | `/orders/{order_id}/results` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L09c | GET | `/orders/{order_id}/results` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 404. |
| LEO | LF-L10a | POST | `/orders/{order_id}/results/step-up/request` | inline (ordersResultsStepUpRequest) | `{}` | — | **CONFORME** | Válido contra inline (ordersResultsStepUpRequest) (JSON Schema 2020-12, con formatos). Esperado: 202. |
| LEO | LF-L10b | POST | `/orders/{order_id}/results/step-up/verify` | StepUpVerifyRequest | `{"challenge_id": "3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "code": "<sintético>"}` | — | **CONFORME** | Válido contra StepUpVerifyRequest (JSON Schema 2020-12, con formatos). Esperado: 200. |
| LEO | LF-L10c | POST | `/orders/{order_id}/results/secure-link` | SecureLinkIssueRequest | `{"grant_id": "3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90"}` | — | **CONFORME** | Válido contra SecureLinkIssueRequest (JSON Schema 2020-12, con formatos). Esperado: 201. |
| LEO | LF-L10d | POST | `/orders/{order_id}/results/secure-link` | SecureLinkIssueRequest | `{"grant_id": "00000000-0000-4000-8000-000000000000"}` | — | **CONFORME** | Válido contra SecureLinkIssueRequest (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (422) es de autorización, no de esquema. Esperado: 422. |
| LEO | LF-L10e | POST | `/orders/{order_id}/results/step-up/request` | inline (ordersResultsStepUpRequest) | `{}` | — | **CONFORME** | Válido contra inline (ordersResultsStepUpRequest) (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (404) es de autorización, no de esquema. Esperado: 404. |
| LEO | LF-L11 | GET | `/orders/{order_id}/results/download` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403. |
| LEO | LF-L12a | GET | `/orders/invoices` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L12b | GET | `/orders/{order_id}/invoices` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L13b | GET | `/user/tax-profiles` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L13a | GET | `/orders/{order_id}/invoice-request/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L13g | POST | `/orders/{order_id}/invoice-request` | LeoInvoiceRequestCreate (por consumidor leo) | `{"tax_profile_id": 1}` | — | **INVALIDO_DELIBERADO** | Negativa de seguridad: Leo envía un entero (forma native) donde LeoInvoiceRequestCreate exige LeoOpaqueTaxProfileId (string); se espera 422 sin solicitud creada. Esperado: 422. |
| LEO | LF-L13c | POST | `/orders/{order_id}/invoice-request` | LeoInvoiceRequestCreate (por consumidor leo) | `{"tax_profile_id": "tp_3Lm9aZ"}` | — | **CONFORME** | Válido contra LeoInvoiceRequestCreate (por consumidor leo) (JSON Schema 2020-12, con formatos). Esperado: 201. |
| LEO | LF-L13d | GET | `/orders/{order_id}/invoice-request/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L13e | GET | `/orders/{order_id}/invoice-request/status` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 404. |
| LEO | LF-L13f | POST | `/orders/{order_id}/invoice-request` | LeoInvoiceRequestCreate (por consumidor leo) | `{"tax_profile_id": "tp_3Lm9aZ"}` | — | **CONFORME** | Válido contra LeoInvoiceRequestCreate (por consumidor leo) (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (409) es de autorización, no de esquema. Esperado: 409. |
| LEO | LF-L13x | POST | `/user/tax-profiles` | TaxProfileWriteRequest | `{}` | — | **INVALIDO_DELIBERADO** | Cuerpo vacío a propósito en POST /user/tax-profiles (native-only): aun con un defecto de autorización, la validación impide crear un perfil fiscal; se espera 403 y un 422 sería FAIL (autorización antes que validación). Esperado: 403. |
| LEO | LF-L14a | POST | `/orders/{order_id}/invoices/{invoice_id}/step-up/request` | inline (ordersInvoiceStepUpRequest) | `{}` | — | **CONFORME** | Válido contra inline (ordersInvoiceStepUpRequest) (JSON Schema 2020-12, con formatos). Esperado: 202. |
| LEO | LF-L14b | POST | `/orders/{order_id}/invoices/{invoice_id}/step-up/verify` | StepUpVerifyRequest | `{"challenge_id": "3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90", "code": "<sintético>"}` | — | **CONFORME** | Válido contra StepUpVerifyRequest (JSON Schema 2020-12, con formatos). Esperado: 200. |
| LEO | LF-L14c | POST | `/orders/{order_id}/invoices/{invoice_id}/secure-link` | SecureLinkIssueRequest | `{"grant_id": "3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90"}` | — | **CONFORME** | Válido contra SecureLinkIssueRequest (JSON Schema 2020-12, con formatos). Esperado: 201. |
| LEO | LF-L14d | POST | `/orders/{order_id}/invoices/{invoice_id}/secure-link` | SecureLinkIssueRequest | `{"grant_id": "3f1c2a9e-0b7d-4c55-9a51-6e2f1b7c8d90"}` | — | **CONFORME** | Válido contra SecureLinkIssueRequest (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (422) es de autorización, no de esquema. Esperado: 422. |
| LEO | LF-L15 | GET | `/orders/{order_id}/invoices/{invoice_id}/download` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403. |
| LEO | LF-L06a | GET | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L06b | POST | `/cart/items` | oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] | `{"laboratory_test_id": 11, "brand": "olab"}` | omite si pm.variables.get('test_id_available') && !/^[1-9][0-9]*$/.test(String(pm.variables.get('test_id_available'))) | **CONFORME** | Válido contra oneOf[LaboratoryCartItemCreateLegacy, MembershipCartItemCreate] (JSON Schema 2020-12, con formatos). Esperado: 201. |
| LEO | LF-L06c | GET | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L06d | GET | `/cart/totals` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L05a | GET | `/checkout/prepare` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L06e | DELETE | `/cart/items/{cart_item_id}` | — | — | — | **N/A** | Sin cuerpo de solicitud (DELETE). Esperado: 200. |
| LEO | LF-L06f | GET | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L06x | DELETE | `/cart` | — | — | — | **N/A** | Sin cuerpo de solicitud (DELETE). Esperado: 403. |
| LEO | LF-L05m | GET | `/checkout/prepare` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 200. |
| LEO | LF-L17a | POST | `/checkout/payment-link` | inline (checkoutPaymentLink) | `{"brand": "olab"}` | omite si b && ['olab', 'swisslab', 'jenner', 'liacsa', 'azteca'].indexOf(b) === -1 | **CONFORME** | Válido contra inline (checkoutPaymentLink) (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (403) es de autorización, no de esquema. Esperado: 403. |
| LEO | LF-L17b | POST | `/checkout/draft` | inline (checkoutDraft) | `{"brand": "olab"}` | — | **CONFORME** | Válido contra inline (checkoutDraft) (JSON Schema 2020-12, con formatos). Negativa: el rechazo esperado (403) es de autorización, no de esquema. Esperado: 403. |
| LEO | LF-L16a | GET | `/cart/coupon` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403. |
| LEO | LF-L16b | GET | `/cart/medical-benefit` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403. |
| LEO | LF-L18 | GET | `/laboratory-appointments` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403. |
| LEO | LF-L20a | GET | `/user/payment-methods` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403. |
| LEO | LF-L20b | GET | `/user/addresses` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403. |
| LEO | LF-L20c | GET | `/user/contacts` | — | — | — | **N/A** | Sin cuerpo de solicitud (GET). Esperado: 403. |

En todas las filas, el script de colección aplica guardas de host y credencial, prerrequisitos y correlation ID, y no modifica el cuerpo. Rev7: la guarda de colección también valida la URL efectiva y exige `qa_writes_authorized="1"` en las escrituras positivas de rc2-NEWMAN. No cambia ningún cuerpo ni ninguna clasificación de este inventario.
