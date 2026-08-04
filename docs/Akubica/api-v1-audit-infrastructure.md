# API V1 — Auditoría transversal

**Estado:** infraestructura (bloque 1) + Auth/OTP (bloque 2) + secure links / downloads (bloque 3) + carrito / cupón / checkout draft (bloque 4) + citas / preferencia de callback (bloque 5).  
**Flag default:** `API_V1_AUDIT_ENABLED=false`  
**Tabla:** `api_v1_audit_events` (append-only)

Payment links, invoice-request, perfiles, contactos/direcciones, eventos de idempotencia y fallback global 5xx **siguen pendientes**.  
**API V1 no crea `LaboratoryPurchase`:** `api_v1.orders.created` y cualquier `api_v1.payment.*` **no** forman parte de este bloque.

Eventos de negocio fuera de API V1 (checkout web, admin, webhooks, sistema) **no** se escriben en `api_v1_audit_events`. Usan la infraestructura independiente documentada en [`business-audit-infrastructure.md`](./business-audit-infrastructure.md) (`business_audit_events`, `BUSINESS_AUDIT_ENABLED`).

---

## 1. Qué es y qué no es

| Concepto | Rol | Persistencia |
|----------|-----|--------------|
| **Log de aplicación** (`Log::info/error`) | Observabilidad operativa / depuración | Efímero (canal log) |
| **Auditoría API v1** (este módulo) | Evidencia de seguridad/ops de acciones sensibles | Tabla append-only |
| **Historial de negocio** | Dominio visible al usuario (órdenes, citas, etc.) | Modelos de negocio |
| **Métricas** | Agregados / SLIs | Telemetría (no esta tabla) |

La auditoría **no** reemplaza logs, historial de negocio ni métricas.

---

## 2. Configuración

Fuente: `config/api_v1.php` → `api_v1.audit.*`

| Variable | Default | Notas |
|----------|---------|-------|
| `API_V1_AUDIT_ENABLED` | `false` | Master switch. phpunit fuerza `false`. |
| `API_V1_AUDIT_MAX_METADATA_BYTES` | `2048` | Tamaño máximo del JSON de metadata |
| `API_V1_AUDIT_MAX_METADATA_DEPTH` | `2` | Profundidad máxima (escalares / arrays planos) |

**No** hay flags de cleanup en esta fase (pertenecen a un bloque posterior).

En tests que ejercen la infra: `config()->set('api_v1.audit.enabled', true)`.

---

## 3. Schema (`api_v1_audit_events`)

Columnas principales: `event_name`, `occurred_at`, `correlation_id`, `related_correlation_id`, `actor_type`, `actor_key`, `customer_id`, `user_id`, `personal_access_token_id`, `resource_type`, `resource_key`, `route_name`, `method`, `outcome`, `http_status`, `error_code`, `retryable`, `idempotency_record_id`, `idempotency_effect`, `ip_hash`, `user_agent_hash`, `metadata`, `created_at`.

- **Sin** `updated_at`.
- **Sin** FK a users / customers / tokens / idempotency.
- **Sin** unique global sobre `correlation_id` (un request puede emitir varios eventos).
- Prefijo estable de eventos: `api_v1.*`.

Migración: `database/migrations/2026_08_03_200000_create_api_v1_audit_events_table.php`.

---

## 4. Append-only / Writer fail-soft

Modelo: `App\Models\Api\V1\ApiV1AuditEvent` — sin updates/deletes ordinarios.  
Writer: `App\Services\Api\V1\Audit\AuditEventWriter` — `write()` fail-soft (no `report()`, no queue, no altera transacciones ajenas). Log sanitizado: `akubica_audit_write_failed` con `event_name`, `correlation_id`, `exception_class`.

Con respuestas binarias (PDF): la falla de auditoría **no** altera status, body, `Content-Type`, `Content-Disposition` ni el efecto principal (creación/consumo del link).

---

## 5. Actores

Resolver: `App\Services\Api\V1\Audit\AuditActorResolver`

| Tipo | `actor_key` | Notas |
|------|-------------|-------|
| `customer` | `customer:{id}` | Incluye `customer_id`, `user_id`, `personal_access_token_id` si aplica. **Nunca** bearer. |
| `public` | `public:{hmac}` | Requiere `purpose` + material **ya normalizado**. |
| `system` | `system:{allowlisted}` | Solo keys allowlisted. |

HMAC namespace: `audit|v1|actor|{purpose}` (domain separation; no reutiliza login/register/idempotency).

Purposes públicos usados:

| Purpose | Uso |
|---------|-----|
| `login` / `register` | Auth OTP |
| `secure_link_results_open` | Apertura pública results |
| `secure_link_invoices_open` | Apertura pública invoices |

Material de apertura: token opaco presentado (solo en memoria). **Nunca** se persiste el token, public ID, URL ni material HMAC original.

---

## 6. Outcomes

| outcome | Uso |
|---------|-----|
| `succeeded` | Autorización y respuesta confirmadas |
| `rejected` | Denegación esperada (seguridad, expiración, ownership, binding, …) |
| `failed` | Falla conocida de infra/config (`FEATURE_DISABLED`, `DOCUMENT_STORAGE_UNAVAILABLE`, …) |
| `uncertain` | Solo cuando no se puede afirmar el efecto final |

`http_status`, `error_code` y `retryable` reflejan el contrato P1-A6 (`ApiErrorRetryability`) **sin** mutar la respuesta.

---

## 7. Middleware `api.audit`

Alias → `InitializeApiV1AuditContext`. Solo hidrata contexto; no escribe eventos.

### Rutas con `api.audit`

**Bloque 2 — Auth/OTP:**

- `auth/login/request-code|verify-code|resend-code`
- `auth/register|register/verify-code|register/resend-code`
- `orders/{id}/results/step-up/request|verify`
- `orders/{id}/invoices/{id}/step-up/request|verify`

**Bloque 3 — Secure links / downloads:**

- `POST orders/{id}/results/secure-link` — tras `api.idempotency`
- `POST orders/{id}/invoices/{id}/secure-link` — tras `api.idempotency`
- `GET secure-downloads/{token}` — tras throttle
- `GET orders/{id}/results/download`
- `GET orders/{id}/invoices/{id}/download`

**Bloque 4 — Carrito / cupón / checkout draft:**

- `POST cart/items`
- `DELETE cart/items/{cart_item_id}`
- `DELETE cart`
- `POST cart/coupon`
- `DELETE cart/coupon`
- `POST checkout/draft`

**No** instrumentados en bloque 4: `GET cart`, `GET cart/totals`, `GET cart/coupon`, `GET checkout/prepare`, `POST checkout/payment-link`.

**Bloque 5 — Citas / preferencia de callback:**

- `POST laboratory-appointments` — tras `api.idempotency`
- `DELETE laboratory-appointments/{appointment_id}`

**No** instrumentados en bloque 5: `GET laboratory-appointments/requirements`, `GET laboratory-appointments`, ni rutas web/admin de concierge/callback-availability.

Orden autenticado (creación):

```
force.json → api.correlation → api.token.guard
  → auth:sanctum → api.customer
  → throttle → api.idempotency → api.audit → controller
```

Orden Bearer download:

```
… → auth:sanctum → api.customer → api.audit → controller
```

Orden público open:

```
force.json → api.correlation → api.token.guard → throttle → api.audit → controller
```

Orden carrito / draft (sin idempotencia):

```
force.json → api.correlation → api.token.guard
  → auth:sanctum → api.customer → api.audit → controller
```

Orden citas (POST con idempotencia opcional):

```
force.json → api.correlation → api.token.guard
  → auth:sanctum → api.customer → api.idempotency → api.audit → controller
```

Orden citas (DELETE):

```
force.json → api.correlation → api.token.guard
  → auth:sanctum → api.customer → api.audit → controller
```

**Replay idempotente:** `api.idempotency` corta antes de `api.audit` → no duplica evento semántico ni crea otro link/cita.  
Las mutaciones de carrito/cupón/draft **no** usan `api.idempotency` (riesgo de doble submit preexistente; no resuelto en este bloque).

**Antes de instrumentación:** 401 Sanctum / 403 sin customer / token malformado en ruta → **sin** evento de este bloque.

---

## 8. Bloque 2 — Eventos Auth/OTP

Emisor: `AuthOtpAuditRecorder`. Ver sección histórica / tests `AkubicaAuditAuthOtpP2Test`.

Eventos: `api_v1.auth.*`, `api_v1.otp.step_up_*`.

---

## 9. Bloque 3 — Secure links y descargas PDF

Emisor: `App\Services\Api\V1\Audit\DocumentAccessAuditRecorder`

### Hallazgos de dominio (código real)

- Creación results: `POST /api/v1/orders/{order_id}/results/secure-link`
- Creación invoices: `POST /api/v1/orders/{order_id}/invoices/{invoice_id}/secure-link`
- Apertura pública (ambos): `GET /api/v1/secure-downloads/{token}` (token hex 64)
- Descarga Bearer results: `GET /api/v1/orders/{order_id}/results/download`
- Descarga Bearer invoices: `GET /api/v1/orders/{order_id}/invoices/{invoice_id}/download`
- **Solo PDF** en estas rutas API V1 (no hay XML en este flujo).
- Respuesta: `Illuminate\Http\Response` con contenido PDF en memoria (comportamiento preexistente; no se cambió a streaming).
- Ownership: `customer->laboratoryPurchases()->withTrashed()->find($orderId)`.
- Step-up Bearer: header `X-Step-Up-Grant`; flags `step_up_bearer_*` / master.
- Secure link: hash SHA-256 del token; valida expiración, `max_opens`/`consumed_at`, revocación de link o grant, purpose flags.
- **Incremento de aperturas:** después de resolver el PDF y **antes** de construir la respuesta HTTP; atómico con `lockForUpdate`. Rechazos previos **no** consumen aperturas.
- Confirmación de creación: tras `OtpSecureDownloadLink::create` (sin transacción envolvente adicional).

### Eventos

| event_name | Hecho |
|------------|-------|
| `api_v1.results.secure_link_created` | Link results persistido (o rechazo relevante de creación) |
| `api_v1.results.secure_link_opened` | Apertura pública results validada + open confirmado + respuesta PDF emitida (o rechazo con link conocido) |
| `api_v1.results.downloaded` | Descarga Bearer results: auth/ownership/step-up/archivo OK y respuesta PDF emitida (o rechazo relevante) |
| `api_v1.invoices.secure_link_created` | Análogo facturas |
| `api_v1.invoices.secure_link_opened` | Análogo facturas |
| `api_v1.invoices.downloaded` | Análogo facturas |

### Semántica

**`secure_link_created`:** éxito solo tras persistir el row. No guarda token, public ID, URL ni firma. Replay idempotente → sin segundo evento.

**`secure_link_opened`:** link validado, PDF resuelto, `open_count` incrementado de forma confirmada, y el servidor **emitió** la respuesta binaria. No afirma que el cliente recibió todos los bytes. Un solo evento terminal por request pública (no se emite también `downloaded`).

**`downloaded`:** Bearer + ownership (+ step-up si enforcement ON) + archivo disponible + respuesta binaria emitida. No afirma transferencia completa al cliente.

**Token desconocido (`SECURE_LINK_NOT_FOUND`):** no se emite evento de purpose (no se puede clasificar results vs invoices sin oráculo).

### Matriz ruta → evento terminal

| Ruta | Evento terminal |
|------|-----------------|
| `POST …/results/secure-link` | `api_v1.results.secure_link_created` |
| `POST …/invoices/…/secure-link` | `api_v1.invoices.secure_link_created` |
| `GET …/secure-downloads/{token}` (results) | `api_v1.results.secure_link_opened` |
| `GET …/secure-downloads/{token}` (invoices) | `api_v1.invoices.secure_link_opened` |
| `GET …/results/download` | `api_v1.results.downloaded` |
| `GET …/invoices/…/download` | `api_v1.invoices.downloaded` |

### Actores (bloque 3)

| Flujo | Actor |
|-------|-------|
| Creación / Bearer download | `customer:{customer_id}` + PAT id si existe |
| Apertura pública results | `public:{HMAC(secure_link_results_open, token)}` |
| Apertura pública invoices | `public:{HMAC(secure_link_invoices_open, token)}` |

### Recursos / IDs

| Dominio | `resource_type` | `resource_key` |
|---------|-----------------|----------------|
| Results | `laboratory_purchase` | id interno del pedido |
| Invoices | `invoice` | id interno de la factura |

Metadata / columnas seguras: `secure_link_row_id`, `step_up_row_id`, `laboratory_purchase_row_id`, `order_row_id`, `invoice_row_id`, `ttl_minutes`, `max_opens`, `open_number`, `purpose` (`results`\|`invoices`).

**Cross-user / ownership 404:** no se persisten `resource_key` ni IDs del recurso ajeno.

**Rechazo de grant Bearer:** no se resuelve ni persiste `step_up_row_id` a partir del header inválido (evita filtrar existencia de grants ajenos). En éxito (o post-grant válido) sí se registra el id interno.

### Metadata allowlist (bloque 3)

Creación: `purpose`, `secure_link_row_id`, `step_up_row_id`, `laboratory_purchase_row_id`, `order_row_id`, `invoice_row_id`, `ttl_minutes`, `max_opens`.

Apertura: `purpose`, `secure_link_row_id`, `step_up_row_id`, `laboratory_purchase_row_id`, `order_row_id`, `invoice_row_id`, `open_number`, `max_opens`.

Download: `purpose`, `step_up_row_id`, `laboratory_purchase_row_id`, `order_row_id`, `invoice_row_id`.

**No** se incluye `document_format` (solo PDF en estas rutas). **No** `delivery_mode` (redundante con el event_name).

Prohibido persistir: token, URL, public IDs, bearer, `X-Step-Up-Grant`, `grant_public_id`, paths, contenido PDF, OTP, phone, email, Idempotency-Key, bodies, exception messages.

### Outcomes auditados (ejemplos)

| Caso | outcome | error_code típico |
|------|---------|-------------------|
| Creación / open / download OK | succeeded | — |
| Ownership | rejected | `ORDER_NOT_FOUND` / `INVOICE_NOT_FOUND` |
| Grant inválido (issue) | rejected | `STEP_UP_GRANT_INVALID` |
| Grant ausente/expirado/revocado (Bearer) | rejected | `STEP_UP_REQUIRED` / `STEP_UP_*` |
| Link expirado / consumido / revocado | rejected | `SECURE_LINK_*` |
| PDF no listo | rejected | `RESULT_NOT_READY` / `INVOICE_NOT_READY` |
| Storage temporal | failed | `DOCUMENT_STORAGE_UNAVAILABLE` |
| Feature flag off (cuando se alcanza con contexto) | failed | `FEATURE_DISABLED` |

### Idempotencia

Endpoints de creación son idempotentes. Primera ejecución → 1 link + 1 evento. Replay → mismo body/status + `Idempotency-Replayed` + **0** eventos adicionales. Conflict → no inventa evento semántico extra (el de la primera ejecución permanece).

### Limitaciones

- Sin fallback global 5xx.
- Token inexistente en apertura pública: sin evento (purpose desconocido).
- 401/403 previos a `api.audit`: sin evento.
- “Opened”/“downloaded” no confirman recepción completa de bytes en el cliente.
- Ventana mínima entre `consumeOpenAtomically` y `pdfResponse`: el evento de éxito se escribe **después** de construir la respuesta PDF en el controller.

---

## 10. Bloque 4 — Carrito, cupón/saldo y checkout draft

Emisor: `App\Services\Api\V1\Audit\CartCheckoutAuditRecorder`

### Hallazgos de dominio (código real)

- El “carrito” API V1 **no** es una fila `cart`: son `LaboratoryCartItem` filtrados por `customer_id` + `LaboratoryTest.brand`.
- No hay `POST` de creación explícita de carrito → **no** existe `api_v1.cart.created`.
- No hay `PATCH/PUT` de ítem (cantidad/sucursal/modalidad) → **no** existe `api_v1.cart.item_updated`.
- Cupón/saldo: se persiste en `LaboratoryCheckoutDraft.coupon_id` (tipo de dominio expuesto como `balance`).
- `GET checkout/prepare` siempre responde 200 con warnings → **no** se audita como `checkout.validated`.
- `POST checkout/payment-link` crea `AkubicaCheckoutLink` (liga opaca) **sin** cobro y **sin** `LaboratoryPurchase` → **fuera de alcance** de este bloque (payment links / pagos).
- Creación real de `LaboratoryPurchase`: checkout web (`OrderAction` / PayPal finalize → `FulfillLaboratoryCartOrderAction`), **fuera de API V1** → **no** se emite `api_v1.orders.created`.

### Eventos

| event_name | Hecho |
|------------|-------|
| `api_v1.cart.item_added` | Ítem persistido en carrito (o rechazo relevante de add) |
| `api_v1.cart.item_removed` | Ítem eliminado tras ownership OK (o rechazo sin IDs ajenos) |
| `api_v1.cart.cleared` | Ítems de la marca eliminados + draft de cupón limpiado |
| `api_v1.cart.benefit_applied` | Cupón/saldo persistido en draft (o rechazo de aplicación) |
| `api_v1.cart.benefit_removed` | Cupón retirado del draft tras cambio material (`removed=true`) |
| `api_v1.checkout.draft_synced` | Draft upsert confirmado (o rechazo EMPTY_CART / ownership) |

### Semántica

**`item_added`:** éxito solo tras `AddItemToCartAction` persistir el row. Cantidad siempre 1 (API no soporta quantity).

**`item_removed`:** éxito solo tras borrar el ítem propio. Cross-user `FORBIDDEN`: sin `resource_key` ni `cart_item_row_id` / `laboratory_test_row_id` ajenos.

**`cleared`:** éxito tras borrar ítems de la marca y `clearForCustomer` del draft. `item_count` = cantidad eliminada.

**`benefit_applied`:** éxito tras `persistCoupon`. Metadata monetaria copia totales confirmados (`*_minor` = centavos). **Nunca** `coupon_code`.

**`benefit_removed`:** solo si hubo cupón previo (`removed=true`). No-op (`removed=false`) → **cero** eventos.

**`draft_synced`:** éxito tras `updateOrCreate` del draft. No persiste `contact_id`, `address_id`, `tax_profile_id`, `notes`. `checkout_ready` refleja `is_ready_for_payment_link` sin afirmar pago.

### Matriz ruta → evento terminal

| Ruta | Evento terminal |
|------|-----------------|
| `POST /api/v1/cart/items` | `api_v1.cart.item_added` |
| `DELETE /api/v1/cart/items/{id}` | `api_v1.cart.item_removed` |
| `DELETE /api/v1/cart` | `api_v1.cart.cleared` |
| `POST /api/v1/cart/coupon` | `api_v1.cart.benefit_applied` |
| `DELETE /api/v1/cart/coupon` | `api_v1.cart.benefit_removed` (solo si material) |
| `POST /api/v1/checkout/draft` | `api_v1.checkout.draft_synced` |

### Actores / resources

| Flujo | Actor | resource_type | resource_key |
|-------|-------|---------------|--------------|
| Mutaciones autenticadas | `customer:{customer_id}` + PAT | `laboratory_cart_item` / `laboratory_cart` / `laboratory_checkout_draft` | id interno del ítem; `{customer_id}:{brand}` para carrito; id del draft |
| Rechazo cross-user / not-found | mismo actor customer | — | **null** (sin IDs ajenos) |

### Metadata allowlist (bloque 4)

Ítem: `laboratory_brand`, `cart_item_row_id`, `laboratory_test_row_id`, `item_count`, `quantity`.

Clear: `laboratory_brand`, `item_count`.

Beneficio: `laboratory_brand`, `benefit_type`, `coupon_row_id`, `applied_amount_minor`, `removed_amount_minor`, `item_count`, `subtotal_minor`, `discount_minor`, `credit_minor`, `total_minor`, `currency`.

Draft: `laboratory_brand`, `draft_row_id`, `checkout_step`, `item_count`, `checkout_ready`.

Prohibido: `coupon_code`, `promo_code`, bearer, card/cvv, payment_*, phone/email/name/address/RFC, bodies, Idempotency-Key, exception messages.

### Outcomes auditados (ejemplos)

| Caso | outcome | error_code típico |
|------|---------|-------------------|
| Add/remove/clear/apply/draft OK | succeeded | — |
| Estudio inexistente / marca incompatible | rejected | `LAB_TEST_NOT_FOUND` |
| Duplicado en carrito | rejected | `ITEM_ALREADY_IN_CART` |
| Ítem ajeno | rejected | `FORBIDDEN` |
| Ítem inexistente | rejected | `CART_ITEM_NOT_FOUND` |
| Carrito vacío (cupón/draft) | rejected | `EMPTY_CART` |
| Cupón ajeno / inexistente | rejected | `COUPON_NOT_FOUND` |
| Cupón usado/sin saldo | rejected | `COUPON_EXPIRED` |
| Cupón no aplicable | rejected | `COUPON_NOT_APPLICABLE` |
| Contacto/dirección/perfil ajeno | rejected | `CONTACT_NOT_FOUND` / `ADDRESS_NOT_FOUND` / `TAX_PROFILE_NOT_FOUND` |
| Error inesperado de dominio | failed | `INTERNAL_ERROR` |

### Límites checkout / pedido / pago

```
API V1 (bloque 4)                 Web / gateways (fuera de bloque)
─────────────────                 ───────────────────────────────
cart items / coupon / draft
        │
POST /checkout/payment-link ──► AkubicaCheckoutLink  [NO auditado aquí]
        │
GET /akubica/checkout/{token} ► checkout web
        │
POST laboratory/.../checkout    OrderAction: charge + Fulfill → LaboratoryPurchase
```

### Limitaciones

- Sin `orders.created` en API V1.
- Sin eventos de payment-link / pagos / 3DS / webhooks.
- Sin idempotencia en mutaciones de carrito (doble submit posible; preexistente).
- Sin fallback global 5xx.
- 401/403 previos a `api.audit`: sin evento.
- `VALIDATION_ERROR` 422 (FormRequest) ocurre antes del controller → sin evento de este bloque.

---

## 11. Bloque 5 — Citas / preferencia de callback

### Inspección del dominio real (API V1)

En API V1 **solo** existen:

| Method | Ruta | Mutación |
|--------|------|----------|
| GET | `/api/v1/laboratory-appointments/requirements` | No (consultiva) |
| GET | `/api/v1/laboratory-appointments` | No (listado) |
| POST | `/api/v1/laboratory-appointments` | Sí — crea pending + ventana callback 1h + avanza draft |
| DELETE | `/api/v1/laboratory-appointments/{id}` | Sí — soft delete |

**No** hay en API V1: endpoints de concierge, callback CRUD, availability/slots, PUT/PATCH de cita, confirmación de sucursal, ni hold/reserva.

El POST **no** confirma una cita en sucursal: persiste `LaboratoryAppointment` con `confirmed_at = null`, copia datos del contacto al appointment, guarda `callback_availability_starts_at` / `_ends_at` (+1h), registra interaction `patient_callback_preference` (`source: akubica_api`) y hace `updateOrCreate` del `LaboratoryCheckoutDraft` (`checkout_step=confirmation`). Confirmación real (`appointment_date`, `laboratory_store_id`, `confirmed_at`) es flujo admin/concierge **fuera** de API V1.

No hay jobs ni notifications en create/delete API. No hay `DB::transaction` envolvente en `CreateAkubicaLaboratoryAppointmentAction`. Zona horaria de validación: `scheduled_at` `after:now` según `config('app.timezone')` (sin forzar `America/Monterrey` en API V1).

### Taxonomía implementada

| event_name | Hecho afirmado |
|------------|----------------|
| `api_v1.appointments.requested` | Solicitud pending persistida (o rechazo relevante de store) |
| `api_v1.appointments.cancelled` | Soft delete propio confirmado (o rechazo not-found / cross-user) |

### Taxonomía omitida (justificación)

| Evento candidato | Motivo |
|------------------|--------|
| `api_v1.concierge.callback_*` | No hay CRUD de callback en API V1; la preferencia es metadata del appointment request (`scheduling_mode=callback_window`) |
| `api_v1.appointments.updated` | No existe PUT/PATCH en API V1 |
| `api_v1.appointments.confirmed` | Confirmación es admin; API solo guarda solicitud pending |
| `api_v1.appointments.availability_checked` | No hay endpoint de slots; GETs son consultivos sin efecto |
| `callback_completed` / llamada atendida | El sistema no afirma que se realizó ni contestó una llamada |

### Semántica exacta

**`appointments.requested` (succeeded):** tras persistir appointment + ventana callback + interaction + draft. Afirma solicitud pending con preferencia de callback de 1h. **No** afirma: cita confirmada, sucursal asignada, llamada realizada, notificación entregada, ni atención.

**`appointments.cancelled` (succeeded):** tras soft delete del row propio. **No** afirma cancelación administrativa ni reembolso.

**Distinción obligatoria:** disponibilidad consultada (GET, sin evento) ≠ solicitud persistida (`requested`) ≠ dispatch (no hay) ≠ confirmación (admin) ≠ atención (fuera de alcance).

### Matriz ruta → evento terminal

| Ruta | Evento terminal |
|------|-----------------|
| `POST /api/v1/laboratory-appointments` | `api_v1.appointments.requested` |
| `DELETE /api/v1/laboratory-appointments/{id}` | `api_v1.appointments.cancelled` |
| `GET …/requirements` | *(omitido)* |
| `GET …/laboratory-appointments` | *(omitido)* |

### Actores / resources

| Flujo | Actor | resource_type | resource_key |
|-------|-------|---------------|--------------|
| Mutaciones autenticadas | `customer:{customer_id}` + PAT | `laboratory_appointment` | id interno |
| Rechazo cross-user / not-found | mismo actor customer | — | **null** (sin IDs ajenos) |

### Metadata allowlist

`laboratory_brand`, `appointment_row_id`, `appointment_state`, `previous_state`, `resulting_state`, `scheduling_mode`, `request_channel`, `requested_date` (solo `YYYY-MM-DD`), `requested_window` (`one_hour`), `timezone` (`UTC` \| `America/Monterrey`), `checkout_draft_advanced`.

Prohibido / descartado: `notes`, phone/email/name/address, patient_*, symptoms, clinical details, hora exacta, bodies, Bearer, Idempotency-Key, payloads de terceros.

Enums controlados en recorder: `appointment_state` / `previous_state` / `resulting_state` ∈ `{pending,confirmed,completed,cancelled}`; `scheduling_mode=callback_window`; `request_channel=akubica_api`; `requested_window=one_hour`. Valores inesperados de estado se omiten. `timezone` solo si es identificador IANA válido (no texto libre).

### Outcomes auditados

| Caso | outcome | error_code |
|------|---------|------------|
| POST 201 | succeeded | — |
| DELETE 200 | succeeded | — |
| Contacto/dirección ajeno o inexistente | rejected | `CONTACT_NOT_FOUND` / `ADDRESS_NOT_FOUND` |
| Carrito vacío | rejected | `EMPTY_CART` |
| Estudios sin cita requerida | rejected | `APPOINTMENT_NOT_REQUIRED` |
| Pending/confirmed bloqueante | rejected | `APPOINTMENT_ALREADY_EXISTS` |
| Cita ajena / inexistente | rejected | `APPOINTMENT_NOT_FOUND` |
| Error inesperado de dominio | failed | `INTERNAL_ERROR` |

### Rechazos omitidos (ruido / previos al controller)

- `VALIDATION_ERROR` 422 FormRequest (p. ej. `scheduled_at` pasado) → sin evento.
- 401 / 403 previos a `api.audit` → sin evento.
- Conflictos de idempotencia (`IDEMPOTENCY_*`) → sin evento semántico inventado (el original sí emite uno).

### Transacciones / jobs

- Sin `DB::transaction` en el action de create; auditoría emite **después** de persistencia confirmada en el action (antes del return 201).
- Sin jobs/notifications en create/delete API → no hay `job_dispatch_state` ni afirmación de entrega.
- Writer fail-soft: si falla el INSERT de auditoría, la cita/cancelación y la respuesta HTTP no cambian.
- Rollback de dominio: no aplica envolvente; si el action falla antes de completar, el controller no emite `succeeded`.

### Idempotencia

- Solo `POST laboratory-appointments` lleva `api.idempotency` (flag `API_V1_IDEMPOTENCY_ENABLED`, default false).
- Primera ejecución: un efecto + un evento.
- Replay: cero efectos adicionales + cero eventos adicionales.
- Conflict: sin evento semántico inventado.
- DELETE **no** tiene idempotencia (doble delete: segundo es `APPOINTMENT_NOT_FOUND` rejected).

### Limitaciones bloque 5

- Sin endpoints de update/reschedule/confirm en API V1.
- Sin auditoría de GETs consultivos.
- Sin afirmar llamadas, SMS, WhatsApp, ActiveCampaign, Zoho, GDA.
- Create sin transacción única: fallo a mitad puede dejar estado parcial (preexistente; no reparado aquí).
- Sin rate limit dedicado en citas.
- Sin fallback transversal 5xx.

---

## 12. Fuera de alcance (siguen pendientes)

- Payment links, invoice-request, perfiles, contactos, direcciones.
- Creación de `LaboratoryPurchase` / pagos (fuera de API V1). La creación confirmada de pedido local se audita en la infraestructura de negocio (`commerce.laboratory_order_created`, ver [`business-audit-infrastructure.md`](./business-audit-infrastructure.md)) — **no** en `api_v1_audit_events`.
- Eventos `api_v1.idempotency.*`.
- Fallback transversal 5xx.
- Cleanup / UI admin.
- Carga/reemplazo administrativo PDF/XML.
- Paths legacy / web concierge fuera de API V1.
- Confirmación administrativa de citas.

---

## 13. Clases y tests clave

| Pieza | Clase / archivo |
|-------|-----------------|
| Modelo | `App\Models\Api\V1\ApiV1AuditEvent` |
| Auth/OTP emitter | `App\Services\Api\V1\Audit\AuthOtpAuditRecorder` |
| Document access emitter | `App\Services\Api\V1\Audit\DocumentAccessAuditRecorder` |
| Cart/checkout emitter | `App\Services\Api\V1\Audit\CartCheckoutAuditRecorder` |
| Appointments emitter | `App\Services\Api\V1\Audit\AppointmentConciergeAuditRecorder` |
| Tests infra | `tests/Feature/Api/V1/Audit/AkubicaAuditInfrastructureP1Test.php` |
| Tests Auth/OTP | `tests/Feature/Api/V1/Audit/AkubicaAuditAuthOtpP2Test.php` |
| Tests bloque 3 | `tests/Feature/Api/V1/Audit/AkubicaAuditDocumentAccessP3Test.php` |
| Tests bloque 4 | `tests/Feature/Api/V1/Audit/AkubicaAuditCartCheckoutP4Test.php` |
| Tests bloque 5 | `tests/Feature/Api/V1/Audit/AkubicaAuditAppointmentConciergeP5Test.php` |
