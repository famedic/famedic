# TI-04 — Matriz retry/read-back por operationId

Contexto: FAMEDIC → Akúbica · Asistente virtual Leo

- OpenAPI base: v1.2.3
- Operaciones totales: 61
- Mutaciones: 33
- Rutas con Idempotency-Key: 9

Esta matriz documenta comportamiento implementado en el contrato y runtime actual. No amplía el alcance funcional de Leo. El timeout numérico del cliente no está definido contractualmente. El valor efectivo de `API_V1_IDEMPOTENCY_ENABLED` en staging será verificado en TI-05; aquí solo se documentan defaults de configuración/código. Payment links, citas y cancelación automática siguen fuera del alcance operativo de Leo aunque existan endpoints.

## Clasificaciones

| Categoría | Significado |
|---|---|
| IDEMPOTENT_KEY | La ruta tiene soporte explícito `api.idempotency`; ante timeout se debe reintentar con la misma `Idempotency-Key` y el mismo payload. |
| NATURALLY_SAFE | Repetir la misma intención no crea efecto duplicado por diseño observado; se reconcilia con read-back posterior cuando existe. |
| CONDITIONAL_RETRY | Solo puede reintentarse bajo una condición verificable, normalmente después de consultar estado. |
| READ_BACK_FIRST | Ante timeout o respuesta ambigua se debe consultar estado antes de repetir para evitar duplicados. |
| NO_RETRY | No existe retry automático seguro; puede consumir OTP, emitir token/grant o disparar efectos no recuperables. |
| EXCLUDED | Endpoint existente / fuera del alcance operativo de Leo MVP. |

## Rutas con idempotencia explícita

| operationId | Método/path | Scope | TTL/lease | Mismo key/payload | Payload distinto | In progress | Conducta |
|---|---|---|---|---|---|---|---|
| authLoginRequestCode | POST `/auth/login/request-code` | `public:{hash}` por teléfono/email | Defaults de código: TTL 24h / lease 60s | Replay si la respuesta quedó persistida | 409 `IDEMPOTENCY_KEY_CONFLICT` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; usar `Retry-After` solo si runtime lo devuelve | Reintentar con la misma `Idempotency-Key` y mismo payload; si queda `UNCERTAIN`, no repetir ciegamente. |
| authRegister | POST `/auth/register` | `public:{hash}` por teléfono+email | Defaults de código: TTL 24h / lease 60s | Replay si la respuesta quedó persistida | 409 `IDEMPOTENCY_KEY_CONFLICT` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; usar `Retry-After` solo si runtime lo devuelve | Reintentar con la misma `Idempotency-Key` y mismo payload; nueva key solo para intento nuevo deliberado. |
| checkoutPaymentLink | POST `/checkout/payment-link` | `customer:{id}` | Defaults de código: TTL 24h / lease 60s | Replay si la respuesta quedó persistida | 409 `IDEMPOTENCY_KEY_CONFLICT` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; usar `Retry-After` solo si runtime lo devuelve | Endpoint existente / fuera del alcance operativo de Leo MVP; si se usa fuera de Leo, persistir key y URL recibida. |
| appointmentsStore | POST `/laboratory-appointments` | `customer:{id}` | Defaults de código: TTL 24h / lease 60s | Replay si la respuesta quedó persistida | 409 `IDEMPOTENCY_KEY_CONFLICT` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; usar `Retry-After` solo si runtime lo devuelve | Endpoint existente / fuera del alcance operativo de Leo MVP; si se usa fuera de Leo, misma key y listado posterior. |
| ordersResultsStepUpRequest | POST `/orders/{order_id}/results/step-up/request` | `customer:{id}` + método/path/order en hash | Defaults de código: TTL 24h / lease 60s | Replay si la respuesta quedó persistida | 409 `IDEMPOTENCY_KEY_CONFLICT` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; usar `Retry-After` solo si runtime lo devuelve | Reintentar con la misma `Idempotency-Key` y mismo payload; no pedir nuevo OTP sin decisión ante `UNCERTAIN`. |
| ordersResultsSecureLink | POST `/orders/{order_id}/results/secure-link` | `customer:{id}` + método/path/order en hash | Defaults de código: TTL 24h / lease 60s | Replay si la respuesta quedó persistida | 409 `IDEMPOTENCY_KEY_CONFLICT` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; usar `Retry-After` solo si runtime lo devuelve | Reintentar con la misma `Idempotency-Key` y mismo payload; guardar la URL devuelta. |
| ordersInvoiceStepUpRequest | POST `/orders/{order_id}/invoices/{invoice_id}/step-up/request` | `customer:{id}` + método/path/order/invoice en hash | Defaults de código: TTL 24h / lease 60s | Replay si la respuesta quedó persistida | 409 `IDEMPOTENCY_KEY_CONFLICT` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; usar `Retry-After` solo si runtime lo devuelve | Reintentar con la misma `Idempotency-Key` y mismo payload; no pedir nuevo OTP sin decisión ante `UNCERTAIN`. |
| ordersInvoiceSecureLink | POST `/orders/{order_id}/invoices/{invoice_id}/secure-link` | `customer:{id}` + método/path/order/invoice en hash | Defaults de código: TTL 24h / lease 60s | Replay si la respuesta quedó persistida | 409 `IDEMPOTENCY_KEY_CONFLICT` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; usar `Retry-After` solo si runtime lo devuelve | Reintentar con la misma `Idempotency-Key` y mismo payload; guardar la URL devuelta. |
| ordersInvoiceRequestStore | POST `/orders/{order_id}/invoice-request` | `customer:{id}` + método/path/order en hash | Defaults de código: TTL 24h / lease 60s | Replay si la respuesta quedó persistida | 409 `IDEMPOTENCY_KEY_CONFLICT` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; usar `Retry-After` solo si runtime lo devuelve | Reintentar con la misma `Idempotency-Key` y mismo payload; ante `UNCERTAIN`, consultar status antes de nueva key. |

## Matriz principal

| operationId | Método/path | Alcance Leo | Categoría | Idempotency-Key | Timeout/no response | Persiste sin respuesta | Read-back | Concurrencia | Efecto persistente | Conducta Akúbica |
|---|---|---|---|---|---|---|---|---|---|---|
| authLoginRequestCode | POST `/auth/login/request-code` | Leo MVP | IDEMPOTENT_KEY | Sí | Reintentar con la MISMA Idempotency-Key y mismo payload | Sí | No suficiente | Idempotency unique/lease + throttle | OTP state, delivery | Reintentar con la MISMA Idempotency-Key y mismo payload; si `IDEMPOTENCY_OPERATION_UNCERTAIN`, no repetir ciegamente. |
| authLoginVerifyCode | POST `/auth/login/verify-code` | Leo MVP | NO_RETRY | No | No existe retry automático seguro | Sí | Inexistente | Throttle; consumo OTP | OTP state, token/session | No reintentar automáticamente; reiniciar login o escalar si la respuesta se perdió. |
| authLoginResendCode | POST `/auth/login/resend-code` | Leo MVP | NO_RETRY | No | No existe retry automático seguro | Sí | Inexistente | Throttle/cooldown | OTP delivery | No duplicar resend; esperar `Retry-After` si runtime lo devuelve. |
| authRegister | POST `/auth/register` | Leo MVP | IDEMPOTENT_KEY | Sí | Reintentar con la MISMA Idempotency-Key y mismo payload | Sí | No suficiente | Idempotency unique/lease + throttle | OTP/intención registro, delivery | Reintentar con la MISMA Idempotency-Key y mismo payload; nueva key solo para un intento nuevo deliberado. |
| authRegisterVerifyCode | POST `/auth/register/verify-code` | Leo MVP | NO_RETRY | No | No existe retry automático seguro | Sí | No suficiente | Throttle; transacciones internas de OTP/registro | Usuario/customer, token/session | No reintentar ciegamente; usar login posterior o escalar. |
| authRegisterResendCode | POST `/auth/register/resend-code` | Leo MVP | NO_RETRY | No | No existe retry automático seguro | Sí | Inexistente | Throttle/cooldown | OTP delivery | No duplicar resend; esperar `Retry-After` si runtime lo devuelve. |
| authRevokeToken | DELETE `/auth/token` | Leo MVP | NATURALLY_SAFE | No | Tratar token como posiblemente revocado | Sí | Inexistente | Delete de token actual | Token/session delete | Si luego responde 401, considerar revocación exitosa funcionalmente y reautenticar si hace falta. |
| cartClear | DELETE `/cart` | Leo MVP | NATURALLY_SAFE | No | Consultar `cartIndex` | Sí | `cartIndex` | Delete por customer/brand | DB delete/update draft | Reconciliar con `cartIndex`; si el carrito está vacío, no repetir. |
| cartItemsStore | POST `/cart/items` | Leo MVP | READ_BACK_FIRST | No | Consultar `cartIndex` antes de repetir | Sí | `cartIndex` | Check app-level; sin unique/lock comprobado | DB create | Si el item ya aparece en `cartIndex`, no repetir; si no aparece, reenviar. |
| cartItemsDestroy | DELETE `/cart/items/{cart_item_id}` | Leo MVP | NATURALLY_SAFE | No | Consultar `cartIndex` | Sí | `cartIndex` | Delete por PK/ownership | DB delete | Si el item ya no aparece, considerar éxito funcional. |
| cartCouponApply | POST `/cart/coupon` | Leo MVP | CONDITIONAL_RETRY | No | Consultar `cartCouponShow`/`cartTotals` antes | Sí | `cartCouponShow`, `cartTotals` | Sin lock específico comprobado | DB update draft/coupon | Reintentar solo si el cupón deseado no quedó aplicado. |
| cartCouponRemove | DELETE `/cart/coupon` | Leo MVP | NATURALLY_SAFE | No | Consultar `cartCouponShow`/`cartTotals` | Sí | `cartCouponShow`, `cartTotals` | Sin lock específico comprobado | DB update draft/coupon | Si ya no hay cupón aplicado, considerar éxito funcional. |
| checkoutDraft | POST `/checkout/draft` | Endpoint existente / fuera del alcance operativo de Leo MVP | EXCLUDED | No | No ampliar flujo Leo | Sí | `checkoutPrepare` | `updateOrCreate`/draft por customer+brand | DB create/update | Endpoint existente / fuera del alcance operativo de Leo MVP; si se usa fuera de Leo, reconciliar con `checkoutPrepare`. |
| checkoutPaymentLink | POST `/checkout/payment-link` | Endpoint existente / fuera del alcance operativo de Leo MVP | IDEMPOTENT_KEY | Sí | Reintentar con la MISMA Idempotency-Key y mismo payload | Sí | No suficiente | Idempotency unique/lease | DB create link | Endpoint existente / fuera del alcance operativo de Leo MVP; persistir key y URL devuelta. |
| appointmentsStore | POST `/laboratory-appointments` | Endpoint existente / fuera del alcance operativo de Leo MVP | IDEMPOTENT_KEY | Sí | Reintentar con la MISMA Idempotency-Key y mismo payload | Sí | `appointmentsIndex` parcial | Idempotency + validación duplicado | DB create/update cita | Endpoint existente / fuera del alcance operativo de Leo MVP; si se usa fuera de Leo, misma key y listar citas. |
| appointmentsDestroy | DELETE `/laboratory-appointments/{appointment_id}` | Endpoint existente / fuera del alcance operativo de Leo MVP | EXCLUDED | No | No ampliar citas Leo | Sí | `appointmentsIndex` | Delete por PK/ownership | DB delete | Endpoint existente / fuera del alcance operativo de Leo MVP; si se usa fuera de Leo, reconciliar por lista. |
| ordersCancel | PUT `/orders/{order_id}/cancel` | Endpoint existente / fuera del alcance operativo de Leo MVP | EXCLUDED | No | No reintentar | No comprobado | `ordersStatus` no confirma cancelación automática | No aplica; feature disabled | Ninguno comprobado si 503 | Endpoint existente / fuera del alcance operativo de Leo MVP; no usar para cancelación automática. |
| ordersResultsStepUpRequest | POST `/orders/{order_id}/results/step-up/request` | Leo MVP | IDEMPOTENT_KEY | Sí | Reintentar con la MISMA Idempotency-Key y mismo payload | Sí | No suficiente | Idempotency unique/lease + throttle | OTP state, delivery | Reintentar con la MISMA Idempotency-Key y mismo payload; si `UNCERTAIN`, no pedir nuevo OTP sin decisión. |
| ordersResultsStepUpVerify | POST `/orders/{order_id}/results/step-up/verify` | Leo MVP | NO_RETRY | No | No existe retry automático seguro | Sí | No suficiente | Throttle; issue grant | OTP consume, grant | No reintentar automáticamente; reiniciar step-up si no hay grant recuperable. |
| ordersResultsSecureLink | POST `/orders/{order_id}/results/secure-link` | Leo MVP | IDEMPOTENT_KEY | Sí | Reintentar con la MISMA Idempotency-Key y mismo payload | Sí | No suficiente | Idempotency unique/lease + throttle | Secure-link issuance | Reintentar con la MISMA Idempotency-Key y mismo payload; guardar URL devuelta. |
| ordersInvoiceStepUpRequest | POST `/orders/{order_id}/invoices/{invoice_id}/step-up/request` | Leo MVP | IDEMPOTENT_KEY | Sí | Reintentar con la MISMA Idempotency-Key y mismo payload | Sí | No suficiente | Idempotency unique/lease + throttle | OTP state, delivery | Reintentar con la MISMA Idempotency-Key y mismo payload; si `UNCERTAIN`, no pedir nuevo OTP sin decisión. |
| ordersInvoiceStepUpVerify | POST `/orders/{order_id}/invoices/{invoice_id}/step-up/verify` | Leo MVP | NO_RETRY | No | No existe retry automático seguro | Sí | No suficiente | Throttle; issue grant | OTP consume, grant | No reintentar automáticamente; reiniciar step-up si no hay grant recuperable. |
| ordersInvoiceSecureLink | POST `/orders/{order_id}/invoices/{invoice_id}/secure-link` | Leo MVP | IDEMPOTENT_KEY | Sí | Reintentar con la MISMA Idempotency-Key y mismo payload | Sí | No suficiente | Idempotency unique/lease + throttle | Secure-link issuance | Reintentar con la MISMA Idempotency-Key y mismo payload; guardar URL devuelta. |
| ordersInvoiceRequestStore | POST `/orders/{order_id}/invoice-request` | Leo MVP | IDEMPOTENT_KEY | Sí | Reintentar con la MISMA Idempotency-Key y mismo payload; luego consultar status si uncertain | Sí | `ordersInvoiceRequestStatus` | Idempotency + DB transaction | DB create, storage copy condicional, audit post-commit | Reintentar con la MISMA Idempotency-Key y mismo payload; ante `UNCERTAIN`, consultar status antes de nueva key. |
| userTaxProfilesStore | POST `/user/tax-profiles` | Leo MVP | READ_BACK_FIRST | No | Consultar `userTaxProfilesIndex` antes de repetir | Sí | `userTaxProfilesIndex` parcial | Sin unique/lock comprobado | DB create | Buscar RFC/datos equivalentes; evitar crear duplicado. |
| userTaxProfilesUpdate | PUT `/user/tax-profiles/{tax_profile_id}` | Leo MVP | NATURALLY_SAFE | No | Consultar `userTaxProfilesIndex` | Sí | `userTaxProfilesIndex` | Update por PK/ownership | DB update | Repetir mismo payload es seguro; reconciliar con lista. |
| userTaxProfilesDestroy | DELETE `/user/tax-profiles/{tax_profile_id}` | Leo MVP | NATURALLY_SAFE | No | Consultar `userTaxProfilesIndex` | Sí | `userTaxProfilesIndex` | Delete por PK/ownership | DB delete | Si el perfil ya no aparece, considerar éxito funcional. |
| userAddressesStore | POST `/user/addresses` | Leo MVP | READ_BACK_FIRST | No | Consultar `userAddressesIndex` antes de repetir | Sí | `userAddressesIndex` parcial | Sin unique/lock comprobado | DB create | Buscar dirección equivalente; evitar crear duplicado. |
| userAddressesUpdate | PUT `/user/addresses/{address_id}` | Leo MVP | NATURALLY_SAFE | No | Consultar `userAddressesIndex` | Sí | `userAddressesIndex` | Update por PK/ownership | DB update | Repetir mismo payload es seguro; reconciliar con lista. |
| userAddressesDestroy | DELETE `/user/addresses/{address_id}` | Leo MVP | NATURALLY_SAFE | No | Consultar `userAddressesIndex` | Sí | `userAddressesIndex` | Delete por PK/ownership | DB delete | Si la dirección ya no aparece, considerar éxito funcional. |
| userContactsStore | POST `/user/contacts` | Leo MVP | READ_BACK_FIRST | No | Consultar `userContactsIndex` antes de repetir | Sí | `userContactsIndex` parcial | Sin unique/lock comprobado | DB create | Buscar contacto equivalente; evitar crear duplicado. |
| userContactsUpdate | PUT `/user/contacts/{contact_id}` | Leo MVP | NATURALLY_SAFE | No | Consultar `userContactsIndex` | Sí | `userContactsIndex` | Update por PK/ownership | DB update | Repetir mismo payload es seguro; reconciliar con lista. |
| userContactsDestroy | DELETE `/user/contacts/{contact_id}` | Leo MVP | NATURALLY_SAFE | No | Consultar `userContactsIndex` | Sí | `userContactsIndex` | Delete por PK/ownership | DB delete | Si el contacto ya no aparece, considerar éxito funcional. |

## Read-back disponible

| Mutación | Read-back | Criterio de reconciliación | Suficiente | Observación |
|---|---|---|---|---|
| cartClear | `cartIndex` | Carrito vacío o sin ítems esperados | Suficiente | Evita repetir un clear ya aplicado. |
| cartItemsStore | `cartIndex` | Ítem con `laboratory_test_id` esperado aparece | Parcial | Evita repetir si ya se observa, pero no elimina riesgo de carrera previa. |
| cartItemsDestroy | `cartIndex` | `cart_item_id` ya no aparece | Suficiente | 404 posterior equivale a eliminado funcional. |
| cartCouponApply | `cartCouponShow`, `cartTotals` | Cupón esperado aplicado y totales reflejados | Suficiente | Reintentar solo si el cupón deseado no quedó aplicado. |
| cartCouponRemove | `cartCouponShow`, `cartTotals` | Sin cupón aplicado | Suficiente | Remove es reconciliable por estado. |
| checkoutDraft | `checkoutPrepare` | Draft/preparación refleja datos enviados | Parcial | Endpoint existente / fuera del alcance operativo de Leo MVP. |
| appointmentsStore | `appointmentsIndex` | Cita equivalente o ID devuelto aparece | Parcial | Endpoint existente / fuera del alcance operativo de Leo MVP; idempotency es el control principal. |
| appointmentsDestroy | `appointmentsIndex` | Cita ya no aparece | Suficiente | Endpoint existente / fuera del alcance operativo de Leo MVP. |
| ordersInvoiceRequestStore | `ordersInvoiceRequestStatus` | Estado indica solicitud existente/en proceso | Suficiente | Read-back secundario útil ante `IDEMPOTENCY_OPERATION_UNCERTAIN`. |
| userTaxProfilesStore | `userTaxProfilesIndex` | RFC/datos fiscales equivalentes aparecen | Parcial | Sin ID cliente ni unique comprobada. |
| userTaxProfilesUpdate | `userTaxProfilesIndex` | Registro ID refleja payload | Suficiente | Lista, no detalle individual. |
| userTaxProfilesDestroy | `userTaxProfilesIndex` | Registro ID ausente | Suficiente | Lista, no detalle individual. |
| userAddressesStore | `userAddressesIndex` | Dirección equivalente aparece | Parcial | Sin ID cliente ni unique comprobada. |
| userAddressesUpdate | `userAddressesIndex` | Registro ID refleja payload | Suficiente | Lista, no detalle individual. |
| userAddressesDestroy | `userAddressesIndex` | Registro ID ausente | Suficiente | Lista, no detalle individual. |
| userContactsStore | `userContactsIndex` | Contacto equivalente aparece | Parcial | Sin ID cliente ni unique comprobada. |
| userContactsUpdate | `userContactsIndex` | Registro ID refleja payload | Suficiente | Lista, no detalle individual. |
| userContactsDestroy | `userContactsIndex` | Registro ID ausente | Suficiente | Lista, no detalle individual. |
| authLoginRequestCode | Ninguno adecuado | No hay endpoint de recuperación de OTP challenge | Inexistente | Usar idempotency; no inventar read-back. |
| authLoginVerifyCode | Ninguno adecuado | No hay endpoint de recuperación de token emitido | Inexistente | No retry automático seguro. |
| authLoginResendCode | Ninguno adecuado | No hay endpoint de recuperación de delivery resend | Inexistente | No retry automático seguro. |
| authRegister | Ninguno adecuado | No hay endpoint público de recuperación de intención/challenge | Inexistente | Usar idempotency; no inventar read-back. |
| authRegisterVerifyCode | Ninguno adecuado | No hay endpoint de recuperación de token emitido | Inexistente | No retry automático seguro. |
| authRegisterResendCode | Ninguno adecuado | No hay endpoint de recuperación de delivery resend | Inexistente | No retry automático seguro. |
| ordersResultsStepUpRequest | Ninguno adecuado | No hay endpoint de recuperación de OTP challenge | Inexistente | Usar idempotency. |
| ordersResultsStepUpVerify | Ninguno adecuado | No hay endpoint de recuperación de grant emitido | Inexistente | No retry automático seguro. |
| ordersResultsSecureLink | Ninguno adecuado | No hay endpoint de recuperación de secure-link URL emitida | Inexistente | Usar idempotency y persistir URL recibida. |
| ordersInvoiceStepUpRequest | Ninguno adecuado | No hay endpoint de recuperación de OTP challenge | Inexistente | Usar idempotency. |
| ordersInvoiceStepUpVerify | Ninguno adecuado | No hay endpoint de recuperación de grant emitido | Inexistente | No retry automático seguro. |
| ordersInvoiceSecureLink | Ninguno adecuado | No hay endpoint de recuperación de secure-link URL emitida | Inexistente | Usar idempotency y persistir URL recibida. |
| checkoutPaymentLink | Ninguno adecuado | No hay endpoint de recuperación de payment-link URL emitida | Inexistente | Endpoint existente / fuera del alcance operativo de Leo MVP. |

## Mutaciones sin read-back suficiente

| operationId | Riesgo | Conducta recomendada |
|---|---|---|
| authLoginVerifyCode | Código OTP consumido y token quizá emitido sin respuesta | No reintentar automáticamente; reiniciar login o escalar. |
| authRegisterVerifyCode | Usuario/customer/token pueden haberse creado sin respuesta | No reintentar ciegamente; usar login posterior o escalar. |
| authLoginResendCode | Doble delivery/cooldown | No reintentar automático; esperar `Retry-After` si runtime lo devuelve. |
| authRegisterResendCode | Doble delivery/cooldown | No reintentar automático; esperar `Retry-After` si runtime lo devuelve. |
| ordersResultsStepUpVerify | OTP consumido y grant quizá emitido | No reintentar automáticamente; reiniciar step-up si no hay grant recuperable. |
| ordersInvoiceStepUpVerify | OTP consumido y grant quizá emitido | No reintentar automáticamente; reiniciar step-up si no hay grant recuperable. |
| ordersResultsSecureLink | URL/token no recuperable por GET | Reintentar solo con la misma `Idempotency-Key` y mismo payload; guardar URL recibida. |
| ordersInvoiceSecureLink | URL/token no recuperable por GET | Reintentar solo con la misma `Idempotency-Key` y mismo payload; guardar URL recibida. |
| checkoutPaymentLink | URL no reconstruible desde read-back | Endpoint existente / fuera del alcance operativo de Leo MVP; si se usa fuera de Leo, misma key y persistir URL. |
| userTaxProfilesStore | Duplicado posible | Consultar `userTaxProfilesIndex` antes de repetir. |
| userAddressesStore | Duplicado posible | Consultar `userAddressesIndex` antes de repetir. |
| userContactsStore | Duplicado posible | Consultar `userContactsIndex` antes de repetir. |

## HTTP status y timeout

| Situación | Regla general | Excepciones relevantes |
|---|---|---|
| 2xx | Considerar la operación exitosa y persistir IDs/URLs devueltos que no tienen read-back. | En idempotency, un replay puede devolver el body/status original con `Idempotency-Replayed: true`. |
| 401 | No reintentar la misma mutación; renovar/reemitir autenticación. | `authRevokeToken`: si el token ya no funciona después de timeout, tratarlo como revocado funcionalmente. |
| 403 | No reintentar; requiere permisos/customer válido. | Ninguna excepción runtime confirmada. |
| 404 | No reintentar ciegamente; validar ownership o existencia del recurso. | Deletes: si el read-back confirma ausencia, considerar éxito funcional. |
| 409 | Leer `error.code`; conflictos de negocio no son retry automático. | `IDEMPOTENCY_KEY_CONFLICT`: no reutilizar key con payload distinto. `IDEMPOTENCY_REQUEST_IN_PROGRESS`: esperar `Retry-After` si runtime lo devuelve y reintentar misma key. `IDEMPOTENCY_OPERATION_UNCERTAIN`: no repetir ciegamente. |
| 422 | Corregir input/header; no retry automático del mismo payload inválido. | `Idempotency-Key` inválida se rechaza antes de persistir record. |
| 429 | Respetar `Retry-After` si se incluye. No inventar backoff contractual. | En rutas idempotentes, mantener la misma key/payload después de esperar. |
| 5xx | No hay regla universal de retry; aplicar la categoría de cada `operationId`. | En IDEMPOTENT_KEY, reintentar misma key/payload; en NO_RETRY, no repetir automáticamente. |
| timeout/no response | El timeout numérico del cliente no está definido contractualmente. Aplicar la categoría de cada `operationId`. | IDEMPOTENT_KEY: misma key/payload. READ_BACK_FIRST: consultar GET antes. NO_RETRY: escalar o reiniciar flujo según operación. |

## Concurrencia

| operationId | Protección | Clasificación | Riesgo |
|---|---|---|---|
| authLoginRequestCode | Idempotency unique/lease + throttle | protegida | `UNCERTAIN` si 5xx/no JSON o lease vencido sin respuesta persistida. |
| authLoginVerifyCode | Throttle; consumo OTP | parcialmente protegida | Token puede emitirse y perderse para el cliente. |
| authLoginResendCode | Throttle/cooldown | parcialmente protegida | Doble delivery o cooldown. |
| authRegister | Idempotency unique/lease + throttle | protegida | `UNCERTAIN` si 5xx/no JSON o lease vencido sin respuesta persistida. |
| authRegisterVerifyCode | Throttle; transacciones internas de OTP/registro | parcialmente protegida | Usuario/token pueden quedar creados sin respuesta. |
| authRegisterResendCode | Throttle/cooldown | parcialmente protegida | Doble delivery o cooldown. |
| authRevokeToken | Delete de token actual | parcialmente protegida | 401 posterior al primer delete. |
| cartClear | Delete por customer/brand | parcialmente protegida | Bajo; reconciliable por `cartIndex`. |
| cartItemsStore | Check app-level; sin unique/lock comprobado | parcialmente protegida | Duplicado por carrera. |
| cartItemsDestroy | Delete por PK/ownership | parcialmente protegida | 404 posterior. |
| cartCouponApply | Sin lock específico comprobado | sin protección específica | Carrera con otro apply/remove. |
| cartCouponRemove | Sin lock específico comprobado | sin protección específica | Carrera con otro apply/remove. |
| checkoutDraft | `updateOrCreate`/draft por customer+brand | parcialmente protegida | Last write wins. |
| checkoutPaymentLink | Idempotency unique/lease | protegida | URL perdida si no quedó persistida/replayable. |
| appointmentsStore | Idempotency + validación duplicado | protegida | Endpoint existente / fuera del alcance operativo de Leo MVP. |
| appointmentsDestroy | Delete por PK/ownership | parcialmente protegida | Endpoint existente / fuera del alcance operativo de Leo MVP. |
| ordersCancel | Feature disabled | no aplica | Endpoint existente / fuera del alcance operativo de Leo MVP. |
| ordersResultsStepUpRequest | Idempotency unique/lease + throttle | protegida | OTP invalidado/uncertain. |
| ordersResultsStepUpVerify | Throttle; issue grant | parcialmente protegida | Grant emitido sin respuesta. |
| ordersResultsSecureLink | Idempotency unique/lease + throttle | protegida | URL perdida si no queda replayable. |
| ordersInvoiceStepUpRequest | Idempotency unique/lease + throttle | protegida | OTP invalidado/uncertain. |
| ordersInvoiceStepUpVerify | Throttle; issue grant | parcialmente protegida | Grant emitido sin respuesta. |
| ordersInvoiceSecureLink | Idempotency unique/lease + throttle | protegida | URL perdida si no queda replayable. |
| ordersInvoiceRequestStore | Idempotency + DB transaction | protegida | Storage copy/commit ambiguo si queda uncertain. |
| userTaxProfilesStore | Create simple; sin unique/lock comprobado | sin protección específica | Duplicado. |
| userTaxProfilesUpdate | Update por PK/ownership | parcialmente protegida | Last write wins. |
| userTaxProfilesDestroy | Delete por PK/ownership | parcialmente protegida | 404 posterior. |
| userAddressesStore | Create simple; sin unique/lock comprobado | sin protección específica | Duplicado. |
| userAddressesUpdate | Update por PK/ownership | parcialmente protegida | Last write wins. |
| userAddressesDestroy | Delete por PK/ownership | parcialmente protegida | 404 posterior. |
| userContactsStore | Create simple; sin unique/lock comprobado | sin protección específica | Duplicado. |
| userContactsUpdate | Update por PK/ownership | parcialmente protegida | Last write wins. |
| userContactsDestroy | Delete por PK/ownership | parcialmente protegida | 404 posterior. |

## Efectos persistentes

| operationId | Efecto persistente | Puede ocurrir sin respuesta al cliente |
|---|---|---|
| authLoginRequestCode | OTP state, delivery | Sí |
| authLoginVerifyCode | OTP consume, token/session | Sí |
| authLoginResendCode | OTP delivery | Sí |
| authRegister | OTP/intención registro, delivery | Sí |
| authRegisterVerifyCode | usuario/customer, token/session | Sí |
| authRegisterResendCode | OTP delivery | Sí |
| authRevokeToken | token/session delete | Sí |
| cartClear | DB delete/update draft | Sí |
| cartItemsStore | DB create | Sí |
| cartItemsDestroy | DB delete | Sí |
| cartCouponApply | DB update draft/coupon | Sí |
| cartCouponRemove | DB update draft/coupon | Sí |
| checkoutDraft | DB create/update | Sí |
| checkoutPaymentLink | DB create link | Sí |
| appointmentsStore | DB create/update cita | Sí |
| appointmentsDestroy | DB delete | Sí |
| ordersCancel | Ninguno comprobado si responde feature disabled | No comprobado |
| ordersResultsStepUpRequest | OTP state, delivery | Sí |
| ordersResultsStepUpVerify | OTP consume, grant | Sí |
| ordersResultsSecureLink | secure-link issuance | Sí |
| ordersInvoiceStepUpRequest | OTP state, delivery | Sí |
| ordersInvoiceStepUpVerify | OTP consume, grant | Sí |
| ordersInvoiceSecureLink | secure-link issuance | Sí |
| ordersInvoiceRequestStore | DB create, storage copy condicional, audit post-commit | Sí |
| userTaxProfilesStore | DB create | Sí |
| userTaxProfilesUpdate | DB update | Sí |
| userTaxProfilesDestroy | DB delete | Sí |
| userAddressesStore | DB create | Sí |
| userAddressesUpdate | DB update | Sí |
| userAddressesDestroy | DB delete | Sí |
| userContactsStore | DB create | Sí |
| userContactsUpdate | DB update | Sí |
| userContactsDestroy | DB delete | Sí |

## Operaciones excluidas

| operationId | Capacidad | Estado | Motivo |
|---|---|---|---|
| checkoutDraft | Checkout/payment link prep | Endpoint existente | Su presencia técnica no significa que Leo deba usarlo; no ampliar payment links en Leo MVP. |
| checkoutPaymentLink | Payment link | Endpoint existente con `api.idempotency` | Su presencia técnica no significa que Leo deba usarlo; payment links fuera del alcance operativo. |
| appointmentsStore | Citas laboratorio | Endpoint existente con `api.idempotency` | Su presencia técnica no significa que Leo deba usarlo; citas fuera del alcance operativo. |
| appointmentsDestroy | Citas laboratorio | Endpoint existente | Su presencia técnica no significa que Leo deba usarlo; citas fuera del alcance operativo. |
| ordersCancel | Cancelación automática | Endpoint existente; feature disabled en implementación | Su presencia técnica no significa que Leo deba usarlo; cancelación automática fuera del alcance operativo. |

## Requisitos para Akúbica

- Generar y persistir `Idempotency-Key` en las 9 rutas confirmadas.
- Reutilizar la misma key para el mismo intento lógico, con el mismo método/path y payload.
- No reutilizar una key con payload distinto.
- Manejar 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` esperando `Retry-After` solo si runtime lo devuelve.
- Manejar 409 `IDEMPOTENCY_OPERATION_UNCERTAIN` sin retry ciego.
- Conservar IDs y payload normalizado necesarios para read-back.
- Persistir URLs/IDs recibidos que no tienen read-back suficiente: token, OTP challenge, grant, secure-link URL y payment-link URL.
- Diferenciar 422, 429, 5xx y timeout/no response.
- Ejecutar read-back antes de repetir creates sin key.
- No hacer retry automático de OTP verify/resend.

## Alcance / no desarrollo

Esta matriz NO introduce:

- nuevos endpoints;
- nuevos mecanismos de idempotencia;
- nuevos read-backs;
- cambios de runtime;
- payment links en Leo MVP;
- citas en Leo MVP;
- cancelación automática;
- nuevas reglas de negocio.

## Fuentes técnicas

- `docs/Akubica/akubica-openapi.yaml`
- `routes/api/v1.php`
- `app/Http/Middleware/Api/V1/EnforceIdempotencyKey.php`
- `app/Services/Api/V1/Idempotency/IdempotencyService.php`
- `app/Services/Api/V1/Idempotency/IdempotencyActorResolver.php`
- `app/Services/Api/V1/Idempotency/IdempotencyRequestHasher.php`
- `app/Services/Api/V1/Idempotency/IdempotencyKey.php`
- `database/migrations/2026_08_03_160000_create_api_v1_idempotency_records_table.php`
- `config/api_v1.php`
- `docs/Akubica/diseno-idempotencia-api-v1.md`
- `docs/Akubica/api-v1-errors.md`
- `docs/Akubica/p1-a6-errors-correlation.md`
