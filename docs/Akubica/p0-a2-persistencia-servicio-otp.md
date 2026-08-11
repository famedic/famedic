# P0-A2 — Persistencia y servicio OTP (`otp_challenges`)

**Sprint:** FAMEDIC–LeoV / Akubica · bloque P0-A2  
**Fecha:** 2026-07-22  
**Estado:** infraestructura lista; **sin cableado a flujos de producción**  
**Referencias:** `docs/Akubica/analisis-sprint-p0-a-otp-sms.md`, `docs/Akubica/p0-a1-configuracion-operativa.md`

---

## 1. Inventario OTP legacy (qué queda intacto)

| Pieza | Rol actual | P0-A2 |
|-------|------------|-------|
| Tabla `otp_codes` | Auth Akubica (email) + lab results web | **No se migra ni se altera** |
| `otp_access_logs` | Auditoría de acceso lab | Intacta |
| `IssueAuthOtpAction` / `VerifyAuthOtpAction` | Request/verify login+register | Intactas |
| `LaboratoryResultsOtpController` | OTP resultados web | Intacta |
| Notificaciones / Vonage | Entrega SMS/email existente | Intactas |
| `config/otp.php` keys legacy (`digits`, `expiry`=10) | Defaults efectivos actuales | Intactas |
| Feature flags `otp.p0a.flags.*` (P0-A1) | Todos `false` | Sin cambio de defaults |

**Reutilizable como patrón (no como tabla):** hash `Hash::make`/`Hash::check`, invalidación de pendientes, cooldown/intentos, canal Vonage.

---

## 2. Decisión: tabla separada `otp_challenges`

**Decisión:** crear stack aislado; **no** extender semántica de `otp_codes`.

**Motivos:**
1. `otp_codes` está acoplada a lab web + auth Akubica email; cambiar esquema/semántica rompería flujos vivos.
2. P0-A necesita subject genérico (`user` / teléfono / email), context (`laboratory_purchase`, etc.), purposes SMS/step-up y métricas de envío (`send_count`, `last_sent_at`) sin forzar columnas ambiguas en legacy.
3. Cutover posterior vía flags P0-A1 (infra / SMS / antiabuso / step-up), no big-bang.

---

## 3. Ciclo de vida (estados derivados)

Estados **no** son columna; se derivan en `OtpChallenge::status()`:

| Precedencia | Estado | Condición |
|-------------|--------|-----------|
| 1 | `consumed` | `consumed_at` no null |
| 2 | `invalidated` | `invalidated_at` no null |
| 3 | `expired` | `expires_at <= now()` |
| 4 | `pending` | resto |

`blocked` (lockout 30 min DEC-002/003) queda **diferido a P0-A3**.

Transiciones típicas:
- `create` → pending (+ opcional invalidate previos del mismo scope con reason `superseded`)
- `verify` OK → consumed (update condicional anti double-consume)
- código inválido → `failed_attempts++`; al agotar → invalidated (`attempts_exhausted`)
- `invalidate(publicId, reason)` → invalidated
- expiración natural → expired (lectura)

---

## 4. Schema `otp_challenges`

Migración: `database/migrations/2026_07_22_180000_create_otp_challenges_table.php`

| Columna | Notas |
|---------|-------|
| `id` | PK interno |
| `public_id` | UUID único (API / cliente) |
| `user_id` | FK nullable → `users` (`nullOnDelete`) |
| `subject_type` / `subject_key` | Identidad lógica (p. ej. `user` + id) |
| `purpose` | string indexado (enum app) |
| `channel` | `sms` \| `email` |
| `destination_normalized` | PII; **hidden** en modelo |
| `destination_masked` | seguro para UI/logs |
| `code_hash` | **hidden**; nunca plaintext |
| `expires_at` | TTL (política P0-A default 5 min vía DTO/`ttlMinutes`) |
| `consumed_at` / `invalidated_at` / `invalidated_reason` | ciclo de vida |
| `failed_attempts` / `max_attempts` | default max 5 |
| `send_count` / `last_sent_at` | reenvíos (antiabuso P0-A3) |
| `context_type` / `context_id` | binding a recurso |
| `meta` | JSON opcional |
| timestamps | |

Índices compuestos: user+purpose+expires; subject+purpose+expires; purpose+context+expires.

---

## 5. Purposes y canales

**`P0aOtpPurpose`:** `akubica_login`, `akubica_register`, `step_up_results`, `step_up_invoices`.  
**`P0aOtpChannel`:** `sms`, `email`.

Verify exige purpose exacto; mismatch → `OtpChallengeMismatchException`. Opcionalmente valida `userId` y `contextType`/`contextId`.

---

## 6. Estrategia de hash

- Generación: `OtpCodeGenerator` → `SecureOtpCodeGenerator` (criptográficamente segura; length desde `otp.p0a.policy.length`, default 6).
- Persistencia: `Hash::make($plain)` / verify: `Hash::check` — **misma estrategia que legacy** `otp_codes`.
- Brute-force: mitigado por `failed_attempts`/`max_attempts` (bloqueo formal P0-A3).
- Plaintext **solo** en `OtpChallengeCreationResult::plainCode()` para adaptador de entrega futuro.
- `OtpChallengeCreationResult::toArray()` **no** expone el código.
- Mensajes de excepción **no** incluyen OTP en claro (cubierto por test Feature).

---

## 7. Contratos del servicio

Clase: `App\Services\Otp\OtpChallengeService` (binding `OtpCodeGenerator` en `AppServiceProvider`).

| Método | Contrato |
|--------|----------|
| `create(CreateOtpChallengeData): OtpChallengeCreationResult` | Transacción; genera código; hashea; opcional `invalidatePreviousActive` |
| `verify(publicId, code, purpose, ?userId, ?contextType, ?contextId): OtpChallenge` | Lock + consume atómico; excepciones tipadas |
| `invalidate(publicId, reason): void` | Marca invalidated |
| `findActive(publicId): ?OtpChallenge` | Pending no expirado |
| `statusByPublicId(publicId): string` | Estado derivado o not-found |
| `recordDeliveryAttempt(publicId): void` | Incrementa `send_count` / `last_sent_at` |

**Excepciones:** `OtpChallengeNotFoundException`, `OtpChallengeMismatchException`, `OtpChallengeExpiredException`, `OtpChallengeConsumedException`, `OtpChallengeInvalidatedException`, `OtpInvalidCodeException`.

DTO: `CreateOtpChallengeData`. Result: `OtpChallengeCreationResult` (challenge + plainCode).

**Fuera de alcance P0-A2:** HTTP controllers, routes, notifications, Sanctum wiring.

---

## 8. Privacidad

- `code_hash` y `destination_normalized` en `$hidden`.
- Preferir `destination_masked` en logs/UI.
- No persistir plaintext OTP.
- Excepciones sin código en mensaje.
- Auditoría de eventos OTP **diferida** (sin listeners/eventos obligatorios en este bloque).

---

## 9. Auditoría diferida

No se introduce tabla/evento de audit en P0-A2. Cuando `otp.p0a.policy.audit_enabled` se active en bloques posteriores, registrar issued/verified/failed/invalidated/resent sin PII sensible ni plaintext.

---

## 10. Purge (query sugerida; comando diferido)

Comando `otp:challenges-purge-expired` **omitido** a propósito (alcance). Query manual / futuro:

```sql
DELETE FROM otp_challenges
WHERE expires_at < NOW()
  AND (
    consumed_at IS NOT NULL
    OR invalidated_at IS NOT NULL
    OR expires_at < DATE_SUB(NOW(), INTERVAL :retention_days DAY)
  );
```

Retención sugerida: 7–30 días según política.

---

## 11. Compatibilidad

- Flags P0-A todas `false` → producción sin cambio de comportamiento.
- Suite API v1 no consume este servicio.
- `otp_codes` y Actions legacy intactos.
- Sanctum expiration efectiva sigue **1440**; target 3 h solo documentado/flag.
- Legacy `otp.expiry` = **10** (minutos) sin cambio; política P0-A TTL default 5 vía `otp.p0a.policy.ttl_minutes`.

---

## 12. Rollback

1. `php artisan migrate:rollback --step=1` (drop `otp_challenges`) — reversible mientras no haya cutover.
2. Quitar binding `OtpCodeGenerator` si se revierte código.
3. No hay datos de producción dependientes en P0-A2.
4. Flags siguen `false` → rollback de código no afecta auth/lab vivos.

Ciclo verificado en testing SQLite dedicado: migrate → rollback → migrate **OK**.

---

## 13. Piezas entregadas

| Pieza | Path |
|-------|------|
| Enums | `app/Enums/P0aOtpPurpose.php`, `P0aOtpChannel.php` |
| Migración | `2026_07_22_180000_create_otp_challenges_table.php` |
| Modelo + factory | `OtpChallenge`, `OtpChallengeFactory` |
| Contract + generator | `OtpCodeGenerator`, `SecureOtpCodeGenerator` |
| DTO / Result / Service | `app/Services/Otp/*` |
| Excepciones | `app/Exceptions/Otp/*` |
| Tests | `tests/Unit/Otp/*`, `tests/Feature/Otp/*` |
| Fake | `tests/Support/Otp/FakeOtpCodeGenerator.php` |

---

## 14. Siguiente bloque: P0-A3

Antiabuso operativo: cooldown 60 s, tope 3 reenvíos / ventana 30 min, bloqueo 30 min, estado `blocked`, counters por subject/destino, integración con `recordDeliveryAttempt` + flags `anti_abuse_enabled`. Sin cablear auth/lab aún salvo lo que el bloque defina.

---

## 15. No commit / no push

Trabajo en workspace local; **sin commit ni push** hasta autorización explícita.