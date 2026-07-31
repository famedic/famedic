# P0-B2 — Secure links temporales para resultados

## Objetivo

Tras un grant step-up válido (`step_up_results`), el propietario puede emitir una
**liga opaca de corta duración** para descargar el PDF de resultados **sin Bearer**.

Fuera de alcance: facturas, enforcement en descarga Bearer, secure links invoices.

## Flujo

1. `POST /orders/{order_id}/results/step-up/request` (P0-B1)
2. `POST /orders/{order_id}/results/step-up/verify` → `grant_id`
3. `POST /orders/{order_id}/results/secure-link` `{ "grant_id": "..." }` → `{ url, expires_at, max_opens }`
4. `GET /secure-downloads/{token}` (sin Authorization) → PDF

## Contratos

### Emisión (auth:sanctum + customer)

- Ownership 404 `ORDER_NOT_FOUND` (inexistente = ajeno)
- Grant inválido (cualquier motivo) → `422 STEP_UP_GRANT_INVALID` (uniforme)
- Resultado no listo → `409 RESULT_NOT_READY`
- Flag OFF → `503 FEATURE_DISABLED`
- Body: solo `grant_id`; identity fields prohibited
- Respuesta **201**: `url`, `expires_at`, `max_opens` (sin `token_hash`, paths, grant internals)

### Descarga pública

| Caso | HTTP | Código |
|------|------|--------|
| Token desconocido | 404 | `SECURE_LINK_NOT_FOUND` |
| Expirada | 410 | `SECURE_LINK_EXPIRED` |
| Consumida / max opens | 410 | `SECURE_LINK_CONSUMED` |
| Revocada (link o grant) | 410 | `SECURE_LINK_REVOKED` |
| PDF no disponible | 409 | `RESULT_NOT_READY` |
| Storage fallo controlado | 503 | `DOCUMENT_STORAGE_UNAVAILABLE` |
| Flag OFF | 503 | `FEATURE_DISABLED` |

Headers PDF: `application/pdf`, `Cache-Control: private, no-store…`, filename sintético.

## Token opaco

- `bin2hex(random_bytes(32))` (64 hex)
- Persistido solo como `hash('sha256', plain)`
- Plain solo una vez en `url`
- Logs: `public_id` / clase de error — nunca el token completo

## TTL y aperturas

| Fuente | Default código | Objetivo staging P0-B2 |
|--------|----------------|------------------------|
| `OTP_P0A_SECURE_LINK_TTL_MINUTES` | **60** | **5** |
| `OTP_P0A_SECURE_LINK_MAX_OPENS` | **5** | **1** |

No se cambiaron defaults silenciosamente; staging debe setear overrides en env.

## Flags

| Flag | Rol |
|------|-----|
| `OTP_P0A_SECURE_LINKS_RESULTS_ENABLED` | Emisión + descarga pública |
| `OTP_P0A_STEP_UP_RESULTS_ENABLED` | Step-up (prereq operativo) |
| `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED` | **Sigue OFF** — Bearer no exige grant/link |

Flags OFF: Bearer download / listados / detalle intactos; secure endpoints → 503.

## Relación grant ↔ link (decisión)

| Evento | Efecto en liga ya emitida |
|--------|---------------------------|
| Grant **revocado** explícitamente | Liga `revoked_at` (cascada en `OtpStepUpGrantService::revoke`) |
| Grant **expira** después de emitir | Liga **sigue válida** hasta su propio `expires_at` |
| Logout / delete PAT | **No** invalida liga automáticamente (riesgo aceptado por TTL corto) |
| Descarga | Requiere grant **no revocado**; no exige grant no-expirado |

## Atomicidad

1. Validar link (revoked/expired/exhausted/grant revoked)
2. Resolver PDF en memoria (`Storage::get` / base64)
3. Si PDF no listo → 409 **sin** incrementar `open_count`
4. `lockForUpdate` + revalidar + `open_count++` (+ `consumed_at` si agota)
5. Responder PDF desde bytes en memoria

Con `max_opens=1`, dos consumes secuenciales/competidos: solo uno OK.

Si el cliente corta el stream HTTP tras el consume, la apertura ya contó (aceptable con TTL corto).

## Metadata resultados

Con flags ON, detalle añade (aditivo, sin quitar `download` bearer / `download_url`):

- `requires_step_up: true`
- `secure_link_supported: true`

## Despliegue gradual

1. Migrar `otp_secure_download_links`
2. Staging: `SECURE_LINKS_RESULTS=true`, TTL=5, max_opens=1, step-up ON
3. QA Postman carpeta 18
4. Prod: flag OFF hasta checklist; Bearer sin cambio

## Rollback

1. Flag OFF → endpoints 503; Bearer intacto
2. DROP manual de tabla + quitar fila `migrations` ( `down()` es no-op por schema drift)

## Riesgos

- Liga usable tras logout dentro del TTL
- URL completa en respuesta de emisión (tratar como secreto de corta vida)
- Docs bajo `/docs/*` gitignored
