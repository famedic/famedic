# EfevooPay Debug Artifact Policy

Do not commit EfevooPay debug artifacts, provider responses, curl captures, raw payloads, tokens, API headers, card data, or TOTP material.

Use only fictitious fixtures in tests and documentation. Local debug output must stay outside version control and must not include:

- API user, API key, TOTP secret, encryption key, merchant/client identifier, vector, fixed tokens, or authorization headers.
- PAN, CVV, CAV, track2, card tokens, client tokens, 3DS tokens, or provider raw responses.
- Curl commands or request/response captures that contain credentials or provider payloads.

For diagnostics, log only allowlisted identifiers such as customer id, payment attempt id, reference, transaction id, response code, status, operation, and exception class.

## Payment authentication attempt event retention

`payment_authentication_attempt_events` is append-only. The application must not update or delete events through models, query builders, or relations.

Future retention or historical cleanup must go through an explicit administrative service (for example `PaymentAuthenticationAttemptRetentionService`) with its own authorization, audit trail, and documented retention window. Do not implement ad-hoc deletes from controllers, models, or relations. Direct SQL remains a maintenance-only escape hatch and is outside the application contract.

## Payment authentication recovery context

`payment_authentication_recovery_contexts` stores an allowlisted checkout origin for 3DS card verification. It must not persist PAN, CVV, card tokens, client tokens, promo validation tokens, open return URLs, or another customer's identifiers.

A context is not a purchase. `card_verified` means the card was tokenized; `recovered` is reserved for a later confirmed payment.

### Legacy `return_url` fallback

`PaymentAuthenticationRecoveryLegacyReturnUrlParser` accepts an existing `return_url` only after it resolves to a named route in `PaymentAuthenticationRecoveryReturnRouteAllowlist`. The raw URL is never copied into `context_data`. Session key `3ds_return_url_{sessionId}` is read only for in-flight legacy 3DS sessions and is discarded if it fails the same allowlist.

Remove this fallback after:

1. Checkout clients send structured `origin` query params instead of `return_url`.
2. No active 3DS sessions still depend on `3ds_return_url_{sessionId}`.
3. One release has elapsed without production traffic using the open query parameter.

## Temporary sensitive card data (Phase 5B containment)

### Finding

During 3DS card-on-file, FAMEDIC temporarily held PAN and CVV in Laravel session (`3ds_card_data_{efevoo_3ds_session_id}`) without explicit purge after terminal outcomes. CVV could remain until global session expiry (default 120 minutes), which is not acceptable after authentication completes.

This is **containment**, not a definitive PCI solution. EfevooPay still requires PAN/CVV in `payments3DS_GetStatus` while the challenge is pending.

### Central service

All access goes through `PaymentAuthenticationSensitiveCardDataStore`:

- Store/read/purge only through this service (no scattered `Session::put/get` for card data).
- Payload includes TTL metadata (`stored_at`, `expires_at`, `customer_id`, `authentication_attempt_id`, `efevoo_3ds_session_id`).
- Default TTL: **5 minutes** (`EFEVOO_SENSITIVE_CARD_DATA_TTL_MINUTES`), independent of `SESSION_LIFETIME`.
- Feature flag: `EFEVOO_SENSITIVE_CARD_DATA_CONTAINMENT` (default true).

### Purge triggers

Purge runs on terminal or ambiguous outcomes, including:

- `authenticated` (before tokenization — CVV removed first)
- `completed`, `declined`, `cancelled`, `expired`, `failed`, `error`
- `tokenization_failed`, `provider_confirmation_pending`, network ambiguity after GetStatus
- Recovery: retry, different card, PayPal start, context expired, card verified
- Idempotent re-poll on terminal session

**Not purged:** confirmed `pending` within TTL (required for GetStatus polling).

### Operational purge command

```bash
php artisan efevoopay:purge-expired-card-session-data          # dry-run (default)
php artisan efevoopay:purge-expired-card-session-data --apply  # persist
```

- Scans `sessions.payload` for structural keys `3ds_card_data_*` only.
- Never prints payload, PAN, or CVV.
- Skips active challenges still inside TTL.
- Recommended frequency: **hourly, off-peak (02:00–05:00 America/Mexico_City)** — not scheduled in app yet.

**Limitation:** TTL expiry purges on next read/poll. Abandoned sessions without further requests may retain keys until GC command or session lottery — document for ops.

### Payload format (Laravel database driver)

`sessions.payload` = **base64( serialize(session_attributes) )** when `SESSION_ENCRYPT=false`.

When `SESSION_ENCRYPT=true`: **base64( encrypt( serialize(session_attributes) ) )**.

The purge command decodes via `LaravelDatabaseSessionPayloadCodec` (never prints values). Detection scans decoded payloads — not `LIKE '%3ds_card_data_%'` alone.

**Supported driver:** `database` only for `--apply`. Redis/file/cookie/array require separate strategies.

### Operational frequency (5 min TTL)

| Factor | Value |
|---|---|
| Sensitive TTL | 5 min |
| Recommended GC interval | **every 5 minutes** (max 10 min during validation) |
| Max residual window (abandoned) | ~11 min (TTL + interval + scheduler jitter) |
| Global session (`SESSION_LIFETIME`) | 120 min — unrelated to sensitive TTL |

Dry-run may be scheduled immediately; `--apply` only after staging validation and ops approval.

Schedule template (commented in `routes/console.php`):

```php
Schedule::command('efevoopay:purge-expired-card-session-data')
    ->everyFiveMinutes()->withoutOverlapping(4)->onOneServer();
```

### Feature flag fail-closed

`EFEVOO_SENSITIVE_CARD_DATA_CONTAINMENT=false` **blocks new 3DS verifications** — it does not revert to storing CVV for 120 minutes. Terminal purge remains active regardless of flag.

### Nightwatch / observability (QA validation required)

Code review confirms: sensitive events use internal `session_id` (3DS session), not Laravel session ID; metrics have no per-card counts. **Validate in QA/production** that Nightwatch request capture excludes `payment-methods.store` body and session payloads.

### Provider dependency

Definitive fix requires EfevooPay to support one of:

- GetStatus/tokenize without CVV after challenge
- Client-side/hosted fields or ephemeral token
- Tokenize using order/3DS session id only

Do **not** use CVV encryption as a substitute for purge.

### Incident response

1. Disable new card attempts if leak suspected (`EFEVOO_SENSITIVE_CARD_DATA_CONTAINMENT=false` only for rollback — prefer fixing forward).
2. Run purge command with `--apply` in maintenance window.
3. Rotate compromised session storage / review DB backups containing `sessions` table.
4. Preserve `payment_authentication_attempt_events` (`sensitive_card_data_*`) for audit — they contain no PAN/CVV.

### Pending provider questions

See Phase 5A audit: GetStatus without CVV, tokenize with order id only, hosted fields, SAQ guidance.

## 3DS monetary operation alignment (Phase 5C)

### TokenCard / getTokenize — capas de evidencia (Postman oficial, revisión externa 2026-08)

| Capa | Contenido |
|---|---|
| **Contrato documentado** | `method: getTokenize`; cuerpo cifrado AES con `track2` (string) + `amount` (string); ejemplo `track2` = `PAN=YYMM` (p. ej. sufijo `=3005` ⇒ año `30`, mes `05`); GetLink/GetStatus usan `track`, `cvv`, `exp` en `MM/YY`; TokenCard **no documenta CVV**. |
| **Comportamiento observado de la API** | Respuesta incluye campo `codigo` (tipo JSON variable: entero `0`, string `"0"`, string `"00"`); mensajes de error del procesador (p. ej. texto que contiene “Bad Track Data”); HTTP 200 con cuerpo de negocio fallido; presencia de `token_usuario` en respuestas exitosas reales. |
| **Inferencias FAMEDIC** | Éxito TokenCard **solo** si `token_usuario` no vacío (comportamiento observado, no contrato); `provider_code_type` + `provider_code_string` preservan tipo sin coerción; `normalized_reason=invalid_track_data` ante mensajes “Bad Track Data”; validación local `PAN=YYMM` antes del cifrado; descriptores allowlisted en timeline admin (sin PAN/CVV/track2/token). |

FAMEDIC **no** trata HTTP 200 ni ningún `codigo` como éxito sin `token_usuario`.

Provisional contract (public EfevooPay documentation):

| Operation | Required fields | FAMEDIC entry point |
|---|---|---|
| `payments3DS_GetLink` | PAN, CVV, exp, amount, browser | `EfevooPayService::initiate3DS()` via `PaymentMethodController::store` |
| `payments3DS_GetStatus` | PAN, CVV, exp, `order_id` (same as GetLink) | `EfevooPayService::poll3DSAuthentication()` via `PaymentAuthentication3dsCompletionService::poll` |
| `getTokenize` / TokenCard | PAN, exp, amount — **no CVV** | `EfevooPayService::finalize3DSTokenization()` after authenticated |
| `getPayment` | token in track2, amount — **CVV empty** | `EfevooPayService::chargeCard()` |

### Verification amounts (unchanged behaviour)

| Config key | Default | Used in |
|---|---|---|
| `EFEVOO_THREE_DS_VERIFICATION_AMOUNT_CENTS` | 150 ($1.50 MXN) | GetLink (`PaymentAuthenticationEfevooPayAmounts::forGetLink`) |
| `EFEVOO_TOKENIZATION_VERIFICATION_AMOUNT_CENTS` | 150 ($1.50 MXN) | TokenCard (`PaymentAuthenticationEfevooPayAmounts::forTokenization`) |

Both default to legacy `test_amounts.default`. Do not change production amounts without operational review.

### Idempotency guards

- **GetLink:** claim on attempt `created → initiating` (max one external call per attempt).
- **GetStatus:** `Cache::lock('efevoo_3ds_getstatus_{session_id}')` + max polls (`EFEVOO_3DS_MAX_EXTERNAL_STATUS_POLLS`, default 60).
- **TokenCard:** DB `lockForUpdate` claim + `Cache::lock` in production service; timeout → `tokenization_confirmation_pending` (no auto-retry).

### Polling (frontend unchanged)

`ThreeDSRedirect.jsx`: delay **5s**, interval **5s**. Passive GET `/3ds-status` never initiates GetLink or TokenCard.

### Conservative duplicate signal

`PaymentAuthenticationEfevooPayOperationAnalyzer` may set `possible_duplicate_verification_operation` when call counts or correlation suggest two verification operations. This is **not** proof of double charge — only operational suspicion.

