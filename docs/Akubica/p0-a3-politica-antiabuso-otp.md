# P0-A3 — Política antiabuso OTP (infraestructura)

## Objetivo

Capa de antiabuso reutilizable alrededor de `OtpChallengeService` (P0-A2), preparada
para futuros flujos `akubica_login`, `akubica_register`, `step_up_results` y
`step_up_invoices`, **sin wiring productivo**.

## Arquitectura

| Componente | Responsabilidad |
|---|---|
| `OtpChallengeService` | Crear / verificar / invalidar desafíos (P0-A2) |
| `OtpAbusePolicy` | Orquestar authorize → create → commit → delivery-record |
| `OtpRateLimitService` | Cooldown, límites identidad/IP, bloqueo, auditoría |
| `OtpAbuseKeyHasher` | HMAC-SHA256 determinista de identidad e IP |
| `OtpRequestContext` | DTO de contexto (sin depender de `Request`) |
| `OtpRateLimitDecision` | Contrato de dominio para futuro HTTP 429 |

## Identidad canónica

Material (antes de HMAC):

`identity|v1|{purpose}|{userId}|{subjectType}|{subjectKey_normalizado}|{contextType}|{contextId}`

- `subjectKey` en minúsculas y trim.
- Propósitos aislados (login no bloquea step-up salvo reglas futuras globales).
- **Inclusión de `contextType`/`contextId`: intencional** para step-up por recurso
  (p. ej. compra 10 vs compra 11 no comparten el mismo bucket de cooldown).
- **Riesgo de evasión:** un cliente podría rotar `contextId` para obtener buckets
  frescos con el mismo usuario+purpose. El wiring productivo futuro debe valorar
  un techo **global adicional** por identidad canónica sin contexto
  (`user/subject + purpose`) además del bucket por recurso.
- Mientras no exista ese techo global, step-up por recurso permanece aislado a propósito.

## Tratamiento de IP

1. Normalizar con `inet_pton` / `inet_ntop` (IPv4 e IPv6; se elimina zone id `%iface`).
2. HMAC-SHA256 con clave de aplicación (`app.key` decodificada si es `base64:`).
3. **No** se persiste IP en claro en DB, eventos, excepciones ni logs de dominio.
4. Si IP ausente/inválida: **no** se crea bucket IP global; solo aplica límite por identidad.

## Límites y ventanas (defaults)

| Parámetro | Default | Fuente |
|---|---|---|
| Cooldown | 60 s | `otp.p0a.policy.cooldown_seconds` |
| max_resends (adicionales) | 3 | `otp.p0a.policy.max_resends` |
| Techo identidad | 4 | `otp.p0a.anti_abuse.identity_max_requests` (efectivo = min(techo, 1+max_resends)) |
| Techo IP | 20 | `otp.p0a.anti_abuse.ip_max_requests` |
| Ventana | 30 min | `otp.p0a.anti_abuse.rate_limit_window_minutes` |
| Bloqueo | 30 min | `otp.p0a.policy.block_minutes` |
| max_attempts verificación | 5 | `otp.p0a.policy.max_attempts` / desafío |

## Códigos de dominio (futuro 429)

- `OTP_COOLDOWN`
- `OTP_RATE_LIMITED`
- `OTP_BLOCKED`
- `OTP_MAX_ATTEMPTS`

Excepciones: `OtpRateLimitExceededException`, `OtpTemporarilyBlockedException`
(llevan `OtpRateLimitDecision`).

## Persistencia

- `otp_rate_limits`: contadores/bloqueos atómicos por `(bucket_type, bucket_key_hash, purpose)`.
- `otp_abuse_events`: auditoría append-only (hashes, decisión, retry_after; sin OTP/IP/destino).

## Atomicidad y concurrencia

`OtpAbusePolicy::issue` abre una transacción (hasta 5 intentos ante deadlock MySQL) que:

1. `evaluateIssueLocked` — adquiere buckets con orden fijo **identity → IP**,
2. crea el desafío,
3. `commitAllowedIssueLocked`,
4. `recordDeliveryAttempt` (**misma** transacción; sin estado parcial si falla después).

### Fila inexistente en `otp_rate_limits`

No se depende de “unique key sola”. Inicialización:

1. `insertOrIgnore` del bucket `(bucket_type, bucket_key_hash, purpose)`;
2. `SELECT ... FOR UPDATE` de esa fila;
3. fallback raro: `create` + captura **solo** `UniqueConstraintViolationException`,
   luego relectura bloqueada;
4. cualquier otro error SQL / deadlock **no** se traga: propaga para reintento
   de `DB::transaction` o fallo explícito de dominio (`OTP_RATE_LIMIT_INIT_FAILED`).

Efecto esperado bajo MySQL InnoDB:

- una sola solicitud gana el insert / mantiene el row lock durante create+commit;
- la competidora espera el lock y, al continuar, ve cooldown / rate-limit
  (decisión de dominio, nunca `QueryException` al cliente);
- un solo desafío activo; incrementos no se pierden; auditoría de deny se
  persiste **después** del commit de la TX de autorización.

### SQLite (tests locales)

`lockForUpdate` no ofrece el mismo aislamiento de fila. Los writers se serializan
a nivel de archivo; `insertOrIgnore` + unique siguen evitando buckets duplicados.
Las pruebas de concurrencia modelan el desenlace serializado (ganador/perdedor)
y la recuperación de unique; **la carrera real paralela debe validarse en staging MySQL**.

## Feature flag

`otp.p0a.flags.anti_abuse_enabled` = **false**.

- La capa de dominio **siempre aplica** si se invoca (no falla abierta).
- El wiring productivo futuro debe comprobar el flag **antes** de llamar a `OtpAbusePolicy`.
- Las pruebas invocan la política directamente.

## Retención

- `otp:purge-abuse-events` purga eventos más antiguos que `retention_days` (default 30).
- **No** está programado en producción en P0-A3.
- Riesgo diferido: crecimiento de `otp_abuse_events` hasta programar la purga.

## Integración futura (fuera de alcance)

Controllers/middleware traducirán `OtpRateLimitDecision` → HTTP 429.
No SMS, correo, Vonage ni notifications en este bloque.

## Riesgos pendientes

1. Programar purga en una fase operativa posterior.
2. Wiring productivo detrás del flag.
3. Traducción HTTP 429 + mensajes i18n.
4. Validar concurrencia real bajo MySQL en staging (dos primeras solicitudes
   paralelas identity+purpose e IP+purpose).
5. Evaluar techo global identidad+purpose sin contexto para mitigar evasión
   por rotación de `contextId` en step-up.
