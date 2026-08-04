# API V1 — Auditoría transversal (bloque 1: infraestructura)

**Estado:** infraestructura (bloque 1) + instrumentación Auth/OTP (bloque 2).  
**Flag default:** `API_V1_AUDIT_ENABLED=false`  
**Tabla:** `api_v1_audit_events` (append-only)

Secure links, downloads, checkout, carrito, citas, invoice-request, perfiles, contactos/direcciones, eventos de idempotencia y fallback global 5xx **siguen pendientes**.

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

### Índices (nombres &lt; 64 chars MySQL)

| Nombre | Columnas |
|--------|----------|
| `api_v1_audit_events_occurred_at_index` | `occurred_at` |
| `api_v1_audit_events_event_name_occurred_at_index` | `event_name`, `occurred_at` |
| `api_v1_audit_events_customer_id_occurred_at_index` | `customer_id`, `occurred_at` |
| `api_v1_audit_events_correlation_id_index` | `correlation_id` |
| `api_v1_audit_events_resource_type_key_occurred_at_index` | `resource_type`, `resource_key`, `occurred_at` |
| `api_v1_audit_events_actor_key_occurred_at_index` | `actor_key`, `occurred_at` |

Migración: `database/migrations/2026_08_03_200000_create_api_v1_audit_events_table.php`  
Compatible MySQL/SQLite vía `MinimumTableContract`.

---

## 4. Append-only

Modelo: `App\Models\Api\V1\ApiV1AuditEvent`

- `UPDATED_AT = null`
- `save()` tras `exists` lanza `LogicException`
- `update()` / `delete()` ordinarios lanzan `LogicException`
- Sin endpoints CRUD
- Columnas aceptadas por el writer: `ApiV1AuditEvent::WRITER_ATTRIBUTES`

Cleanup físico se implementará en otro bloque (comando / retención).

---

## 5. Modelo de actores

Resolver: `App\Services\Api\V1\Audit\AuditActorResolver`

| Tipo | `actor_key` | Notas |
|------|-------------|-------|
| `customer` | `customer:{id}` | Incluye `customer_id`, `user_id`, `personal_access_token_id` si aplica. **Nunca** bearer. |
| `public` | `public:{hmac}` | Requiere `purpose` + material **ya normalizado**. |
| `system` | `system:{allowlisted}` | Solo keys allowlisted (`scheduler`, `console`, `maintenance`, `worker`). |

### HMAC — domain separation

Reutiliza `OtpAbuseKeyHasher::hashOpaque()` con namespace explícito:

```
audit|v1|actor|{purpose}
```

**No** reutiliza el namespace de idempotencia (`idempotency|v1|...`).  
Purpose distintos ⇒ digests distintos. El material original **nunca** se almacena.

IP / User-Agent opcionales: `hashIp` / `hashUserAgent` (HMAC; nunca plaintext).

---

## 6. Metadata — allowlist y redacción

Normalizer: `App\Services\Api\V1\Audit\AuditMetadataNormalizer`  
Definiciones: `App\Services\Api\V1\Audit\AuditEventDefinitions`

Reglas:

1. Allowlist **explícita por `event_name`** (unknown keys eliminadas).
2. Keys en `snake_case`.
3. Profundidad máxima 2; solo escalares y arrays planos de escalares.
4. Máximo 2048 bytes del JSON final.
5. **Sin truncamiento silencioso**: si excede el límite, se **descarta toda** la metadata (fail-soft) y se registra `akubica_audit_metadata_discarded`.
6. Defensa adicional por nombre sensible (`token`, `secret`, `password`, `otp`, `code`, `authorization`, `bearer`, `cookie`, `key`, `grant`, …).
7. Rechazo de `Model`, `Request`, `Response`, `Throwable`, `UploadedFile`, objetos y binarios.

### Datos prohibidos (nunca persistir)

OTP/code, Authorization/Bearer, password, Idempotency-Key original, secure-link token, `X-Step-Up-Grant` presentado, cookies, `APP_KEY`, PDF/XML/documentos, bodies completos, exception message / stack trace, `grant_public_id` completo (tratar como credencial).

Para grants / challenges / links: preferir ID interno; si no hay ID seguro, HMAC con propósito. Nunca el valor presentado por el cliente.

En este documento se listan eventos infra de prueba y los eventos Auth/OTP del bloque 2 (sección 11).

---

## 7. Writer fail-soft

`App\Services\Api\V1\Audit\AuditEventWriter`

| Método | Comportamiento |
|--------|----------------|
| `write()` | Si flag OFF → no-op. Si ON → normaliza + persiste. Captura fallos; **no** rompe la operación principal. |
| `persistOrFail()` | Propaga excepciones (tests / internos). |

Log de fallo:

```
Log::error('akubica_audit_write_failed', [
  'event_name' => ...,
  'correlation_id' => ...,
  'exception_class' => ...,
]);
```

**No** incluye exception message, SQL, bindings, payload ni metadata cruda.  
**No** usa `report($e)` (puede filtrar contexto sensible).  
**No** usa queue en esta fase.  
**No** inicia / confirma / revierte transacciones ajenas.

### Limitación transaccional

Si la conexión actual está dentro de una transacción **ya fallida/abortada**, el `INSERT` puede fallar. `write()` lo traga y loguea. Cuando la atomicidad con un side-effect importe, emitir el evento **después** del commit de negocio (bloques de instrumentación posteriores).

---

## 8. Contexto de request

`App\Services\Api\V1\Audit\ApiV1AuditContext`

Estado por request (atributo `akubica.audit_context`), **sin** estáticos compartidos:

- `correlation_id`, `route_name`, `method`
- actor resuelto
- `idempotency_record_id` / `idempotency_effect`
- `related_correlation_id`
- indicador de evento terminal emitido

No escribe eventos por sí mismo.

---

## 9. Middleware

Alias: `api.audit` → `InitializeApiV1AuditContext`

Solo crea/hidrata contexto. **No** inserta eventos, no lee bodies, no transforma responses, no cambia correlation ID.

### Rutas con `api.audit` (bloque 2)

Aplicado **solo** a Auth/OTP:

- `auth/login/request-code|verify-code|resend-code`
- `auth/register|register/verify-code|register/resend-code`
- `orders/{id}/results/step-up/request|verify`
- `orders/{id}/invoices/{id}/step-up/request|verify`

Orden efectivo:

```
force.json → api.correlation → api.token.guard
  → [auth:sanctum → api.customer]   # solo step-up
  → throttle:akubica-otp
  → [api.idempotency]               # request-code / register / step-up request
  → api.audit
  → controller
```

**Replay idempotente:** `api.idempotency` corta antes de `api.audit` y del controller → **no** se duplica el evento semántico ni el SMS/challenge.

---

## 10. Outcomes

Vocabulario estable (`AuditOutcome`):

| outcome | Uso |
|---------|-----|
| `succeeded` | Operación confirmada |
| `rejected` | Rechazo esperado (código inválido, ownership, cooldown, …) |
| `failed` | Fallo infra/delivery/config |
| `uncertain` | Efecto parcial (p.ej. registro committed + `LOGIN_REQUIRED`) |

`http_status`, `error_code` y `retryable` reflejan el contrato P1-A6 (`ApiErrorRetryability`) **sin** mutar la respuesta.

---

## 11. Bloque 2 — Eventos Auth/OTP

Emisor tipado: `App\Services\Api\V1\Audit\AuthOtpAuditRecorder`  
Escritura en controllers **después** del éxito del service (post-commit) o tras mapear la excepción.

### Eventos

| event_name | Cuándo |
|------------|--------|
| `api_v1.auth.login_code_requested` | Login request-code (real o decoy) |
| `api_v1.auth.login_code_resent` | Login resend-code |
| `api_v1.auth.login_verified` | Login verify (éxito o rechazo relevante) |
| `api_v1.auth.registration_code_requested` | `POST auth/register` |
| `api_v1.auth.registration_code_resent` | Register resend |
| `api_v1.auth.registration_completed` | Register verify (incluye `LOGIN_REQUIRED` → outcome `uncertain`) |
| `api_v1.otp.step_up_requested` | Step-up request results/invoices (`purpose` en metadata) |
| `api_v1.otp.step_up_verified` | Step-up verify + grant interno |

**No hay** `step_up_resent`: el “resend” real es otro `request` tras cooldown.

### Matriz ruta → evento

| Ruta | Evento(s) |
|------|-----------|
| `POST …/login/request-code` | `login_code_requested` |
| `POST …/login/resend-code` | `login_code_resent` |
| `POST …/login/verify-code` | `login_verified` |
| `POST …/auth/register` | `registration_code_requested` |
| `POST …/register/resend-code` | `registration_code_resent` |
| `POST …/register/verify-code` | `registration_completed` |
| `POST …/results/step-up/request` | `step_up_requested` (purpose=results) |
| `POST …/results/step-up/verify` | `step_up_verified` |
| `POST …/invoices/…/step-up/request` | `step_up_requested` (purpose=invoices) |
| `POST …/invoices/…/step-up/verify` | `step_up_verified` |

### Actores

| Flujo | Actor |
|-------|-------|
| Login / register (pre-auth) | `public` + HMAC `audit\|v1\|actor\|login` o `…\|register` |
| Login verify éxito / register completed | `customer:{id}` cuando user+customer confirmados |
| Step-up | `customer` + PAT id si existe; nunca bearer |

### Recursos / IDs

- `challenge_row_id` = `otp_challenges.id` interno (nunca `public_id`).
- Step-up: `resource_type` = `laboratory_purchase` \| `invoice`; `resource_key` = id interno.
- `step_up_row_id` = `otp_step_up_grants.id` interno (nunca `grant_public_id`).
- Ownership 404: se audita el id **intentado** de la URL; no se afirma existencia de recursos ajenos.

### Metadata allowlist (Auth/OTP)

`delivery_channel`, `delivery_result_class`, `is_resend`, `is_decoy`, `challenge_row_id`, `session_issued`, `purpose`, `order_row_id`, `laboratory_purchase_row_id`, `invoice_row_id`, `step_up_row_id`.

Keys renombradas para no chocar con la redacción por nombre (`grant_*` → `step_up_row_id`).

### Flag OFF

Auth/OTP funciona igual; **cero** inserts en `api_v1_audit_events`.

### 5xx

Sin fallback global. Delivery/config usan outcome `failed` en el evento de la operación; no se guarda exception message/stack.

### Idempotencia

Primera ejecución → un evento semántico. Replay → cero eventos adicionales, cero SMS/challenge nuevos. Sin eventos `api_v1.idempotency.*` en este bloque.

---

## 12. Fuera de alcance (siguen pendientes)

- Secure links / apertura / downloads Bearer / PDF-XML.
- Checkout, payment links, carrito, citas, invoice-request, perfiles, contactos, direcciones.
- Eventos propios de idempotencia.
- Fallback transversal 5xx.
- Cleanup / UI admin.
- Paths legacy email OTP (flags OFF): no instrumentados.

---

## 13. Clases clave

| Pieza | Clase |
|-------|-------|
| Modelo | `App\Models\Api\V1\ApiV1AuditEvent` |
| Contexto | `App\Services\Api\V1\Audit\ApiV1AuditContext` |
| Actor | `App\Services\Api\V1\Audit\AuditActorResolver` |
| Metadata | `App\Services\Api\V1\Audit\AuditMetadataNormalizer` |
| Writer | `App\Services\Api\V1\Audit\AuditEventWriter` |
| Auth/OTP emitter | `App\Services\Api\V1\Audit\AuthOtpAuditRecorder` |
| Outcomes | `App\Services\Api\V1\Audit\AuditOutcome` |
| Middleware | `App\Http\Middleware\Api\V1\InitializeApiV1AuditContext` |
| Tests infra | `tests/Feature/Api/V1/Audit/AkubicaAuditInfrastructureP1Test.php` |
| Tests Auth/OTP | `tests/Feature/Api/V1/Audit/AkubicaAuditAuthOtpP2Test.php` |
