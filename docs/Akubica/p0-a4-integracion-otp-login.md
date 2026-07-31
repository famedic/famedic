# P0-A4 — Integración OTP en login Akubica

## Decisión: login passwordless (no password)

El API v1 Akubica ya usa login passwordless:

`POST /auth/login/request-code` → `POST /auth/login/verify-code` → Sanctum

P0-A4 **conserva esos endpoints** (+ `resend-code`). Con flags OFF el contrato legacy permanece igual.

## Matriz de flags

| `akubica_login_enabled` | `anti_abuse_enabled` | Comportamiento |
|---|---|---|
| false | * | Legacy intacto |
| true | true | Flujo P0-A4 completo |
| true | false | **503** `OTP_CONFIGURATION_INVALID` (mensaje genérico) |

Defaults: ambos `false`.

## Anti-enumeración — ciclo completo del decoy

### Oráculo anterior (eliminado)

Tras `request-code`, verify/resend de un `challenge_id` decoy devolvían `NO_ACTIVE_CODE` mientras un desafío real devolvía `INVALID_CODE` / `OTP_COOLDOWN` → enumeración.

### Homologación pública

| Paso | Real | Decoy emitido |
|---|---|---|
| request-code | 202 + forma OTP | 202 + misma forma |
| verify código incorrecto | 422 `INVALID_CODE` | 422 `INVALID_CODE` |
| resend inmediato | 429 `OTP_COOLDOWN` + Retry-After / `retry_after` / `available_at` (`…Z`) | Igual |
| resend post-cooldown | 202 + nuevo `challenge_id` | 202 + nuevo UUID decoy |
| verify id anterior | 422 `CODE_INVALIDATED` | 422 `CODE_INVALIDATED` |
| verify tras TTL | 422 `CODE_EXPIRED` | 422 `CODE_EXPIRED` |

### Distinción documentada

Un UUID **nunca emitido** por `request-code` puede seguir respondiendo `NO_ACTIVE_CODE`.  
La superficie de oráculo son los `challenge_id` **entregados** por request-code; esos decoys viven en cache efímera.

## Estado efímero (`AkubicaLoginOtpDecoyStore`)

- **Backend:** `Cache` (prefijo `otp:p0a4:login:decoy:{uuid}`)
- **TTL cache:** `expires_at + 3600s` gracia (crecimiento acotado; GC por TTL)
- **Datos:** `destination_masked`, `last_sent_at`, `expires_at`, `failed_attempts`, `max_attempts`, `invalidated_at`, `invalidated_reason`
- **Sin:** email completo, IP, OTP, `user_id`, buckets, `otp_challenges`, tokens
- **Producción:** cache compartido (Redis) entre nodos
- **Pérdida / indisponibilidad del cache:** los IDs decoy emitidos degradan a `NO_ACTIVE_CODE` (como UUID nunca emitido) — riesgo residual hasta que el atacante solo tenga IDs post-flush; mitigar con Redis estable

## `expires_in`

| Concepto | Valor | Unidad |
|---|---|---|
| `sanctum.expiration` | 1440 | minutos |
| JSON `expires_in` | ~86400 al emitir | **segundos restantes** |

## Consumo vs token

Consume atómico en `OtpChallengeService` (UPDATE condicional). `createToken` **después** del commit. Ventana documentada: consume sin token → nuevo request/resend. Prueba de doble verify: **secuencial**; concurrencia MySQL staging pendiente.

## Delivery

P0-A4 no envía Notification/Mail/SMS/Vonage.
