# Colección funcional diferenciada de Leo (Batch 1 · rev3; rev4: referencia opaca de perfil fiscal; rev5: cuerpos conformes)

Archivos: `Famedic-Akubica-v1.3.0-rc2-LEO-FUNCIONAL.postman_collection.json` (48 solicitudes en 7 carpetas), `Famedic-Akubica-v1.3.0-rc2-LEO-FUNCIONAL.environment.template.json` (plantilla **sin secretos**) y `RC2-LEO-FUNCIONAL-COBERTURA.csv` (cobertura por solicitud, generada desde la colección).

## Papel

| Colección | Solicitudes | Papel |
|---|---|---|
| `…-rc2-NEWMAN` | 69 | **Certificación** A/B/R (A01–A12, B01–B26, R01–R08). Sin cambios en rev3; en rev4 sólo se alinean los cuerpos de B20/B21x y se explicita B21x (ver abajo) |
| `…-rc2-LEO-FUNCIONAL` | 48 | **Funcional diferenciada de Leo**: flujos obligatorios de `RC2-MATRIZ-PERMISOS-LEO.md` que faltaban en la NEWMAN (step-up, ligas seguras, solicitud y estado de factura, lectura fiscal mínima D-1, pedidos, resultados, facturas, carrito y continuidad de laboratorio D-2) y los negativos de alcance de Leo |
| `…-rc2-90` | 92 | Antecedente offline; su guard omite siempre las 92. **Sin cambios** |

Contrato congelado de referencia: `akubica-openapi-v1.3.0-rc2.json` (rev2; rev4 agrega `LeoOpaqueTaxProfileId`, `LeoInvoiceRequestCreate` y `NativeInvoiceRequestCreate`), `RC2-MATRIZ-PERMISOS-LEO.md` (L01–L20 y L13b, D-1, D-2) y la semántica de checkout de Batch 1 · T4 (`assistant_can_pay=false`, `can_continue_on_platform=true`, `can_continue_to_payment_platform=false`; payment link siempre 403 para Leo; `continuity_url` first-party de un uso, TTL ≤ 10 min, sólo staging, nunca payment link).

**Corre sólo sobre el build de Batch 2** (`leo_batch2_contract=1`). En el build QA actual (`3fb5feb8`) Leo recibe 403 en L06–L15, por eso todo se omite sin esa bandera. **En Batch 1 no se ejecutó en QA**; sólo se validó localmente y sin red.

## Reutilización

Cada solicitud declara en su descripción la solicitud de origen (`reutiliza NEWMAN:<ID>` o `reutiliza P90:<nombre>`):

- De `rc2-NEWMAN` se copian sin cambiar aserciones R01 (LF-L07a), R02a (LF-L09b), R03a (LF-L12b), R04 (LF-L08a), B23 (LF-L05m) y B25 (LF-L17a); sólo cambia el prefijo del nombre de la prueba. En rev5, LF-L17a toma el cuerpo corregido de B25 (`brand`) y exige `leo_negative_writes_authorized`; las aserciones no cambian.
- De `rc2-90` se toman método, ruta y cuerpo de las demás. Las referencias `{{vault:*}}` pasan a variables de la plantilla y se agregan las aserciones del contrato (esquemas `Leo*Response`, soft deny 404, propósito del grant, TTL y aperturas de la liga).
- Las aserciones comunes de colección son las de `rc2-NEWMAN` (correlation ID, sin datos protegidos, error tipado). Se agrega un control Leo: no recibe PDF/XML, datos de pago ni RFC completo.

## Guardas (prerequest de colección)

Fallan cerradas: aserción `GUARDA …` en FAIL, la solicitud se omite (no sale tráfico) y la corrida termina con exit ≠ 0.

1. `BASE_URL_HTTPS_REQUERIDA`: `base_url` debe ser https. **Rev7:** además se valida la URL efectiva, con las variables resueltas según la precedencia de Postman (`HTTPS_REQUERIDO`, `URL_INVALIDA`, `URL_CON_VARIABLES_SIN_RESOLVER`). Un `base_url` sustituido en el archivo de datos o como variable local queda bloqueado.
2. Host exacto `staging.famedic.com.mx` o su alias exacto `famedic-otp.on-forge.com`. Lo demás se bloquea: `HOST_PRODUCCION_BLOQUEADO` (`*.famedic.com.mx`, `*.famedic.mx`) o `HOST_DESCONOCIDO_BLOQUEADO`. El host debe estar también en `allowed_hosts` (`HOST_NO_PERMITIDO`). Desde rev7 la comparación es exacta, sin pasar a minúsculas, y `allowed_hosts` no puede contener hosts fuera de la lista cerrada (`ALLOWED_HOSTS_FUERA_DE_LISTA_CERRADA`).
3. `TARGET_ENV_STAGING_REQUERIDO`: `target_env=staging`, declarado por el operador. La plantilla lo trae vacío.
4. `RELEASE_SHA_REQUERIDO`: `expected_release_sha` de 40 hex.
5. Política: `URL_FUERA_DE_BASE_URL`, `FIRST_PARTY_PROHIBIDO` (`secure-downloads`, `checkout/continue`, `benefits/consent`, `medical-attention`), `PAGO_SOLO_COMO_NEGATIVA` (`checkout/payment-link` y `checkout/draft` sólo en solicitudes `NEGATIVA_SEGURIDAD` que esperan 403), `SOLO_CREDENCIAL_LEO` (sólo `{{machine_credential}}` y `Bearer {{bearer_token}}`/`{{bearer_none}}`) y `CREDENCIAL_NATIVE_PROHIBIDA`.

## Precondiciones (se omiten, nunca fallan ni cuentan como PASS)

`SKIPPED_PRECONDITION` si falta `leo_batch2_contract=1` o cualquier variable de `requires:`. Las banderas `*_authorized`, `*_enabled` y `*_contract` deben valer exactamente el texto `"1"`. Desde rev7 deben valerlo tanto en el valor efectivo como en el environment: no basta fijarlas en globals, en el archivo de datos ni como variable local. Matriz offline en el paquete rev7.

| Bandera o variable | Quién la fija | Habilita |
|---|---|---|
| `leo_batch2_contract=1` | Operador, sólo sobre el build de Batch 2 | Toda la colección |
| `sms_authorized=1` | Seguridad (Eulalio), por ventana | Step-up L10/L14: envía SMS al titular |
| `step_up_code_results`, `step_up_code_invoices` | El titular lo lee en su dispositivo y el operador lo teclea con `read -s` | Verificación step-up. Nunca se lee de BD, logs ni canales internos |
| `qa_writes_authorized=1` | Eulalio (ventana de escrituras QA) | Alta y retiro en el carrito de laboratorio (L06) |
| `invoice_request_authorized=1` | Eulalio (fixture INT-005) | Solicitud de factura (L13) |
| `leo_negative_writes_authorized=1` | Eulalio | Negativos de escritura cuyo defecto tendría efecto: cancelar pedido, vaciar carrito, draft, payment link (LF-L17a desde rev5) |
| `lab_continuity_enabled=1` | Sólo si la bandera de continuidad de laboratorio está encendida en staging | Exige `continuity_url` en LF-L05a; sin ella se exige su ausencia |
| `own_order_id`, `own_invoice_id`, `foreign_order_id`, `invoice_request_order_id`, `invoice_old_order_id`, `lab_brand`, `test_id_available` (entero; si no lo es, la solicitud se omite) | Fixtures INT-005 | Lecturas y escrituras sobre recursos propios y ajenos |
| `tax_profile_ref` | Encadenada: LF-L13b la toma de la lectura mínima (perfil por omisión o el primero) | Id opaco que LF-L13c y LF-L13f envían sin transformar como `tax_profile_id` (`LeoInvoiceRequestCreate`). No hay fixture entero para Leo |
| `machine_credential`, `bearer_token`, `bearer_none` | Gestor seguro (INT-003); `--env-var` en runtime | Credencial y Bearer de Leo. Nunca native |

## Cobertura de los flujos obligatorios de Leo

| Fila | Flujo | Escenario | Solicitudes | Fuente contractual | Esperado | Guarda y precondiciones |
|---|---|---|---|---|---|---|
| L01 | Identificación ATIX | — | rc2-NEWMAN A01–A07 (A07: negativo intencional de validación, cuerpo INVALIDO_DELIBERADO, 422) | Matriz L01 | Sin cambios | Guardas de `rc2-NEWMAN` |
| L02 | Login OTP y Bearer · además rc2-NEWMAN A08–A12 (sin cambios); aquí sólo el readback LF00 | S03 | LF00 `GET /memberships/status` | Matriz L02; `membershipStatusShow` (—) | LF00: 200; el Bearer de Leo proviene de A10 (rc2-NEWMAN), inyectado en runtime | Guardas de colección + `leo_batch2_contract=1`<br>LF00: bearer_token,machine_credential |
| L03 | Catálogo y precios | — | rc2-NEWMAN B01–B07 (sin cambios) | Matriz L03 | Sin cambios | Guardas de `rc2-NEWMAN` |
| L04 | Carrito médico (membresía) · además rc2-NEWMAN B08–B17 y B26 (sin cambios) | S12 | LF-L05m `GET /checkout/prepare` | Matriz L04; `checkoutPrepare` (—) | LF-L05m: 200 assistant_can_pay=false; continuidad según b24_enabled | Guardas de colección + `leo_batch2_contract=1`<br>LF-L05m: bearer_none,machine_credential |
| L05 | Continuidad de checkout (membresía; D-2 laboratorio) · además rc2-NEWMAN B23, B24a y B24b (membresía, sin cambios) | S12 | LF-L05a `GET /checkout/prepare`<br>LF-L05m `GET /checkout/prepare` | Matriz L05; `checkoutPrepare` (—) | LF-L05a: 200 LeoLaboratoryCheckoutPrepareResponse; assistant_can_pay=false; continuity_url first-party de un uso ≤10 min sólo si lab_continuity_enabled=1<br>LF-L05m: 200 assistant_can_pay=false; continuidad según b24_enabled | Guardas de colección + `leo_batch2_contract=1`<br>LF-L05a: bearer_token,machine_credential,lab_brand<br>LF-L05m: bearer_none,machine_credential |
| L06 | Carrito de laboratorio · además rc2-NEWMAN B20 (native, S08) y B21x (Leo realmente no autorizado: marca sin cart:laboratory:write, 403; rev4) | S07/S08/S12 | LF-L06a `GET /cart`<br>LF-L06b `POST /cart/items`<br>LF-L06c `GET /cart`<br>LF-L06d `GET /cart/totals`<br>LF-L05a `GET /checkout/prepare`<br>LF-L06e `DELETE /cart/items/{cart_item_id}`<br>LF-L06f `GET /cart`<br>LF-L06x `DELETE /cart` | Matriz L06; `cartClear` (—)<br>`cartIndex` (—)<br>`cartItemsDestroy` (—)<br>`cartItemsStore` (—)<br>`cartTotals` (—)<br>`checkoutPrepare` (—) | LF-L06a: 200 carrito de la línea laboratory y la marca<br>LF-L06b: 201 con el ítem; precio calculado en servidor<br>LF-L06c: 200 con el ítem agregado<br>LF-L06d: 200 totales de servidor<br>LF-L05a: 200 LeoLaboratoryCheckoutPrepareResponse; assistant_can_pay=false; continuity_url first-party de un uso ≤10 min sólo si lab_continuity_enabled=1<br>LF-L06e: 200 sin afectar otra línea<br>LF-L06f: 200 sin el ítem retirado<br>LF-L06x: 403 sin efecto | Guardas de colección + `leo_batch2_contract=1`<br>LF-L06a: bearer_token,machine_credential,lab_brand<br>LF-L06b: bearer_token,machine_credential,lab_brand,test_id_available,qa_writes_authorized<br>LF-L06c: bearer_token,machine_credential,lab_brand,lab_item_id_leo<br>LF-L06d: bearer_token,machine_credential,lab_brand<br>LF-L05a: bearer_token,machine_credential,lab_brand<br>LF-L06e: bearer_token,machine_credential,lab_brand,lab_item_id_leo,qa_writes_authorized<br>LF-L06f: bearer_token,machine_credential,lab_brand,lab_item_id_leo,qa_writes_authorized<br>LF-L06x: bearer_token,machine_credential,lab_brand,leo_negative_writes_authorized |
| L07 | Pedidos propios | S09 | LF-L07a `GET /orders`<br>LF-L07b `GET /orders/{order_id}/status`<br>LF-L07c `GET /orders/{order_id}/products`<br>LF-L07d `GET /orders/{order_id}/status`<br>LF-L07x `PUT /orders/{order_id}/cancel` | Matriz L07; `ordersCancel` (native)<br>`ordersIndex` (leo, native)<br>`ordersProducts` (leo, native)<br>`ordersStatus` (leo, native) | LF-L07a: 200 LeoOrdersIndexResponse sin pedido ajeno<br>LF-L07b: 200 LeoOrderStatusResponse<br>LF-L07c: 200 LeoOrderProductsResponse<br>LF-L07d: 404 ORDER_NOT_FOUND<br>LF-L07x: 403 (native-only) | Guardas de colección + `leo_batch2_contract=1`<br>LF-L07a: bearer_token,machine_credential,foreign_order_id<br>LF-L07b: bearer_token,machine_credential,own_order_id<br>LF-L07c: bearer_token,machine_credential,own_order_id<br>LF-L07d: bearer_token,machine_credential,foreign_order_id<br>LF-L07x: bearer_token,machine_credential,own_order_id,leo_negative_writes_authorized |
| L08 | Familiares propios | S09 | LF-L08a `GET /user/family` | Matriz L08; `userFamily` (leo, native) | LF-L08a: 200 LeoFamilyResponse sin fecha de nacimiento ni género | Guardas de colección + `leo_batch2_contract=1`<br>LF-L08a: bearer_token,machine_credential |
| L09 | Metadatos de resultado | S10 | LF-L09a `GET /orders/results`<br>LF-L09b `GET /orders/{order_id}/results`<br>LF-L09c `GET /orders/{order_id}/results` | Matriz L09; `ordersResultsIndex` (leo, native)<br>`ordersResults` (leo, native) | LF-L09a: 200 LeoResultsIndexResponse<br>LF-L09b: 200 LeoOrderResultsResponse sin descarga<br>LF-L09c: 404 ORDER_NOT_FOUND | Guardas de colección + `leo_batch2_contract=1`<br>LF-L09a: bearer_token,machine_credential<br>LF-L09b: bearer_token,machine_credential,own_order_id<br>LF-L09c: bearer_token,machine_credential,foreign_order_id |
| L10 | Step-up y liga segura de resultado | S10 | LF-L10a `POST /orders/{order_id}/results/step-up/request`<br>LF-L10b `POST /orders/{order_id}/results/step-up/verify`<br>LF-L10c `POST /orders/{order_id}/results/secure-link`<br>LF-L10d `POST /orders/{order_id}/results/secure-link`<br>LF-L10e `POST /orders/{order_id}/results/step-up/request` | Matriz L10; `ordersResultsSecureLink` (leo, native)<br>`ordersResultsStepUpRequest` (leo, native)<br>`ordersResultsStepUpVerify` (leo, native) | LF-L10a: 202 OtpChallenge purpose=step_up_results, destino enmascarado<br>LF-L10b: 200 OtpGrant purpose=step_up_results<br>LF-L10c: 201 SecureLink (url temporal, ≤60 min, max_opens ≤3)<br>LF-L10d: 422 STEP_UP_GRANT_INVALID sin liga<br>LF-L10e: 404 ORDER_NOT_FOUND sin challenge ni SMS | Guardas de colección + `leo_batch2_contract=1`<br>LF-L10a: bearer_token,machine_credential,own_order_id,sms_authorized<br>LF-L10b: bearer_token,machine_credential,own_order_id,results_challenge_id,step_up_code_results<br>LF-L10c: bearer_token,machine_credential,own_order_id,results_grant_id<br>LF-L10d: bearer_token,machine_credential,own_order_id<br>LF-L10e: bearer_token,machine_credential,foreign_order_id,sms_authorized |
| L11 | Descarga directa de resultado (negativa) | S10 | LF-L11 `GET /orders/{order_id}/results/download` | Matriz L11; `ordersResultsDownload` (native) | LF-L11: 403 sin PDF | Guardas de colección + `leo_batch2_contract=1`<br>LF-L11: bearer_token,machine_credential,own_order_id |
| L12 | Metadatos de factura | S11 | LF-L12a `GET /orders/invoices`<br>LF-L12b `GET /orders/{order_id}/invoices` | Matriz L12; `ordersInvoicesIndex` (leo, native)<br>`ordersInvoices` (leo, native) | LF-L12a: 200 LeoInvoicesIndexResponse<br>LF-L12b: 200 LeoOrderInvoicesResponse sin descarga | Guardas de colección + `leo_batch2_contract=1`<br>LF-L12a: bearer_token,machine_credential<br>LF-L12b: bearer_token,machine_credential,own_order_id |
| L13 | Solicitud y estado de factura | S11 | LF-L13a `GET /orders/{order_id}/invoice-request/status`<br>LF-L13g `POST /orders/{order_id}/invoice-request`<br>LF-L13c `POST /orders/{order_id}/invoice-request`<br>LF-L13d `GET /orders/{order_id}/invoice-request/status`<br>LF-L13e `GET /orders/{order_id}/invoice-request/status`<br>LF-L13f `POST /orders/{order_id}/invoice-request` | Matriz L13; `ordersInvoiceRequestStatus` (leo, native)<br>`ordersInvoiceRequestStore` (leo, native) | LF-L13a: 200 LeoInvoiceRequestStatusResponse<br>LF-L13g: 422 VALIDATION_ERROR sin efecto (LeoInvoiceRequestCreate sólo admite el id opaco string)<br>LF-L13c: 201 LeoInvoiceRequestStatusResponse con invoice_request<br>LF-L13d: 200 con el mismo invoice_request.id<br>LF-L13e: 404 ORDER_NOT_FOUND<br>LF-L13f: 409 ORDER_NOT_INVOICEABLE sin efecto | Guardas de colección + `leo_batch2_contract=1`<br>LF-L13a: bearer_token,machine_credential,invoice_request_order_id<br>LF-L13g: bearer_token,machine_credential,invoice_request_order_id,leo_negative_writes_authorized<br>LF-L13c: bearer_token,machine_credential,invoice_request_order_id,tax_profile_ref,invoice_request_authorized<br>LF-L13d: bearer_token,machine_credential,invoice_request_order_id,lf_invoice_request_id<br>LF-L13e: bearer_token,machine_credential,foreign_order_id<br>LF-L13f: bearer_token,machine_credential,invoice_old_order_id,tax_profile_ref,invoice_request_authorized |
| L13b | Lectura fiscal mínima (D-1) | S11 | LF-L13b `GET /user/tax-profiles`<br>LF-L13x `POST /user/tax-profiles` | Matriz L13b; `userTaxProfilesIndex` (leo, native)<br>`userTaxProfilesStore` (native) | LF-L13b: 200 LeoTaxProfilesResponse (RFC enmascarado, sin PII completa); entrega el id opaco que usa L13<br>LF-L13x: 403 (native-only); cuerpo vacío: aun con un defecto, la validación impide crear | Guardas de colección + `leo_batch2_contract=1`<br>LF-L13b: bearer_token,machine_credential<br>LF-L13x: bearer_token,machine_credential |
| L14 | Step-up y liga segura de factura (PDF) | S11 | LF-L14a `POST /orders/{order_id}/invoices/{invoice_id}/step-up/request`<br>LF-L14b `POST /orders/{order_id}/invoices/{invoice_id}/step-up/verify`<br>LF-L14c `POST /orders/{order_id}/invoices/{invoice_id}/secure-link`<br>LF-L14d `POST /orders/{order_id}/invoices/{invoice_id}/secure-link` | Matriz L14; `ordersInvoiceSecureLink` (leo, native)<br>`ordersInvoiceStepUpRequest` (leo, native)<br>`ordersInvoiceStepUpVerify` (leo, native) | LF-L14a: 202 OtpChallenge purpose=step_up_invoices<br>LF-L14b: 200 OtpGrant purpose=step_up_invoices<br>LF-L14c: 201 SecureLink PDF (sin XML)<br>LF-L14d: 422 STEP_UP_GRANT_INVALID sin liga | Guardas de colección + `leo_batch2_contract=1`<br>LF-L14a: bearer_token,machine_credential,own_order_id,own_invoice_id,sms_authorized<br>LF-L14b: bearer_token,machine_credential,own_order_id,own_invoice_id,invoices_challenge_id,step_up_code_invoices<br>LF-L14c: bearer_token,machine_credential,own_order_id,own_invoice_id,invoices_grant_id<br>LF-L14d: bearer_token,machine_credential,own_order_id,own_invoice_id,results_grant_id |
| L15 | Descarga directa de factura (negativa) | S11 | LF-L15 `GET /orders/{order_id}/invoices/{invoice_id}/download` | Matriz L15; `ordersInvoiceDownload` (native) | LF-L15: 403 sin PDF ni XML | Guardas de colección + `leo_batch2_contract=1`<br>LF-L15: bearer_token,machine_credential,own_order_id,own_invoice_id |
| L16 | Cupones y créditos (diferido) | X01 | LF-L16a `GET /cart/coupon`<br>LF-L16b `GET /cart/medical-benefit` | Matriz L16; `cartCouponShow` (—)<br>`medicalServiceBenefitShow` (—) | LF-L16a: 403<br>LF-L16b: 403 | Guardas de colección + `leo_batch2_contract=1`<br>LF-L16a: bearer_token,machine_credential,lab_brand<br>LF-L16b: bearer_token,machine_credential |
| L17 | Pagos y payment link (negativa) · además rc2-NEWMAN B25 (rev5: cuerpo válido con `brand`) | S14 | LF-L17a `POST /checkout/payment-link`<br>LF-L17b `POST /checkout/draft` | Matriz L17; `checkoutDraft` (—)<br>`checkoutPaymentLink` (—) | LF-L17a: 403 sin liga, intento de pago ni checkout; cuerpo válido con brand (rev5)<br>LF-L17b: 403 sin draft ni liga | Guardas de colección + `leo_batch2_contract=1`<br>LF-L17a: bearer_none,machine_credential,lab_brand,leo_negative_writes_authorized<br>LF-L17b: bearer_token,machine_credential,lab_brand,leo_negative_writes_authorized |
| L18 | Citas (negativa) | S13 | LF-L18 `GET /laboratory-appointments` | Matriz L18; `appointmentsIndex` (native) | LF-L18: 403 (native-only) | Guardas de colección + `leo_batch2_contract=1`<br>LF-L18: bearer_token,machine_credential |
| L19 | Farmacia | — | rc2-NEWMAN B22 (sin cambios) | Matriz L19 | Sin cambios | Guardas de `rc2-NEWMAN` |
| L20 | Resto de la superficie privada (negativa) | S09/S11/S14/S16 | LF-L07x `PUT /orders/{order_id}/cancel`<br>LF-L13x `POST /user/tax-profiles`<br>LF-L20a `GET /user/payment-methods`<br>LF-L20b `GET /user/addresses`<br>LF-L20c `GET /user/contacts` | Matriz L20; `ordersCancel` (native)<br>`userAddressesIndex` (native)<br>`userContactsIndex` (native)<br>`userPaymentMethods` (native)<br>`userTaxProfilesStore` (native) | LF-L07x: 403 (native-only)<br>LF-L13x: 403 (native-only); cuerpo vacío: aun con un defecto, la validación impide crear<br>LF-L20a: 403<br>LF-L20b: 403<br>LF-L20c: 403 | Guardas de colección + `leo_batch2_contract=1`<br>LF-L07x: bearer_token,machine_credential,own_order_id,leo_negative_writes_authorized<br>LF-L13x: bearer_token,machine_credential<br>LF-L20a: bearer_token,machine_credential<br>LF-L20b: bearer_token,machine_credential<br>LF-L20c: bearer_token,machine_credential |

## Lo que no cubre Newman (y por qué)

- **Abrir la liga segura o la de continuidad:** Leo nunca las abre. La apertura por el titular (sesión web y `session_mismatch`) es prueba de navegador de S10/S11/S12 en Batch 2.
- **El código step-up:** lo recibe el titular en su dispositivo. La colección sólo puede verificarlo si el operador lo teclea (`read -s`); no es automatizable sin leer OTP, y eso está prohibido.
- **Confirmación conversacional** antes de escribir (L04, L06, L13): es UAT de Batch 3 (R08); la API no la verifica.
- **Readback de "sin intento de pago"** tras B25/LF-L17a: consulta de sólo lectura del operador en Batch 2, fuera de Newman.
- **Familiar ajeno (L08):** no hay endpoint por id; se cubre con la lista mínima (LF-L08a, sin campos extra).
- **Concurrencia (B18)** y **farmacia (L19, B22)** siguen en `rc2-NEWMAN` o fuera de Newman, sin cambios.

## Observaciones de rev3 (conciliadas en rev4, IT-0025)

Texto de rev3, que se conserva como antecedente:

- **OBS-CONTRATO-TAXPROFILE-ID:** `LeoTaxProfileSummary.id` es un id opaco (string), pero `POST /orders/{order_id}/invoice-request` pide `tax_profile_id` integer. La colección envía `tax_profile_id_int`, como pide el contrato. Hay que decidirlo antes de implementar en Batch 2.
- **OBS-NEWMAN-B21X:** `rc2-NEWMAN` B21x espera 403 cuando Leo agrega un estudio de laboratorio. Es lo correcto en el build actual, pero contradice L06 en el build de Batch 2. No se modificó (rev3 no cambia la NEWMAN): debe reclasificarse en Batch 2 · S07.
- **OBS-NEWMAN-QUANTITY:** B20 y B21x de `rc2-NEWMAN` envían `quantity`, que `LaboratoryCartItemCreateLegacy` (`additionalProperties: false`) no declara. LF-L06b envía sólo `laboratory_test_id` y `brand`.

Conciliación rev4 (contrato documental; la implementación sigue en Batch 2):

| Observación | Resolución rev4 | Dónde |
|---|---|---|
| OBS-CONTRATO-TAXPROFILE-ID | Referencia única `LeoOpaqueTaxProfileId` (string). `LeoTaxProfileSummary.id` la usa en la lectura (L13b) y `LeoInvoiceRequestCreate.tax_profile_id` en la solicitud (L13), así que Leo envía el mismo id opaco que recibió. El contrato native se conserva en `NativeInvoiceRequestCreate` (entero, `cfdi_use`, `email`, `notes`). El cuerpo es `oneOf` [Leo, native] y el esquema aplicable lo fija el consumidor autenticado (`x-request-schema-by-consumer`). Un id desconocido, ajeno o entero da 422 VALIDATION_ERROR sin efecto y sin revelar existencia. Se retira `tax_profile_id_int` de la colección y la plantilla de Leo | OpenAPI `ordersInvoiceRequestStore`; LF-L13b (captura `tax_profile_ref`), LF-L13c y LF-L13f (envían el id opaco); LF-L13g nueva (entero ⇒ 422) |
| OBS-NEWMAN-B21X | B21x es ahora el caso realmente no autorizado. Perfil leo (`machine_credential` + `bearer_none`); capacidades: el binding no concede `cart:laboratory:write` para la marca; línea `laboratory`; marca `lab_brand_not_granted`, una `LaboratoryBrand` válida y distinta de la concedida. Esperado 403 sin efecto. El positivo autorizado (`cart:laboratory:write` para `lab_brand`, 201) se traza a L06/S07 con LF-L06b de esta colección. No se agrega a la NEWMAN ninguna escritura positiva de Leo | `rc2-NEWMAN` B21x; OpenAPI `cartItemsStore` (`x-authorization-cases`); LF-L06b |
| OBS-NEWMAN-QUANTITY | B20 y B21x envían `{"laboratory_test_id": <entero>, "brand": …}` sin `quantity`, conforme a `LaboratoryCartItemCreateLegacy`. Si `test_id_available` no es entero, la solicitud se omite (SKIPPED_PRECONDITION) | `rc2-NEWMAN` B20/B21x; LF-L06b |

## Cuerpos de solicitud (rev5, IT-0026)

Revisión de los cuerpos de las 48 solicitudes contra el `requestBody` del OpenAPI. Las variables se resuelven con valores sintéticos y se consideran los pre-request; además se capturan sin red los cuerpos reales. Ver `RC2-INVENTARIO-CUERPOS.md` / `.csv`.

| Clasificación | Solicitudes |
|---|---|
| CONFORME | 15 |
| INVALIDO_DELIBERADO | 2: LF-L13g (entero para Leo, 422) y LF-L13x (cuerpo vacío a propósito, 403) |
| DISCREPANCIA_PENDIENTE | 0 (antes LF-L17a sin `brand`; corregida) |
| N/A (sin cuerpo) | 31 |

## Validación local (Batch 1 · rev3 y rev4, sin QA)

Estructura (esquema Postman v2.1.0), sintaxis de scripts, controles estáticos de política, simulación de aserciones contra respuestas sintéticas del contrato y corridas de Newman **sin red** (`unshare -rn`), que prueban que las guardas bloquean y que las precondiciones omiten. Rev4 agrega la validación de cuerpos de solicitud y de ejemplos contra los esquemas OpenAPI (jsonschema 2020-12 y verificación cruzada con ajv) y la cadena de extremo a extremo del id opaco L13b→L13. Resultados en el paquete de entrega (`validaciones/leo-funcional/` y `validaciones/rev4/`).

## Ejecución (Batch 2, sólo con autorización)

```bash
read -r -s -p "Bearer Leo: " LEO_BEARER; echo; read -r -s -p "Credencial Leo: " LEO_CRED; echo
newman run Famedic-Akubica-v1.3.0-rc2-LEO-FUNCIONAL.postman_collection.json \
  -e Famedic-Akubica-v1.3.0-rc2-LEO-FUNCIONAL.environment.template.json \
  --env-var target_env=staging --env-var expected_release_sha=<SHA verificado en QA> --env-var run_id=QA-$(date +%Y%m%d%H%M) \
  --env-var leo_batch2_contract=1 --env-var bearer_token="$LEO_BEARER" --env-var machine_credential="$LEO_CRED" \
  --env-var own_order_id=<fixture> --env-var foreign_order_id=<fixture> \
  --reporters cli,junit --reporter-junit-export newman-QA-leo-funcional.xml
unset LEO_BEARER LEO_CRED
```

No usar el reporter `json`, porque incluye los valores del entorno. Las banderas de SMS y de escrituras se agregan sólo con la autorización correspondiente.
