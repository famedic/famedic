# P0-A5.1 — Diseño técnico: registro seguro Akubica

**Fecha:** 2026-07-27  
**Rama:** `feature/apis-akubica`  
**HEAD:** `b6f57c7` (P0-A4)  
**Tipo:** análisis / auditoría / diseño. **Sin implementación.**

Leyenda: **[HECHO]** · **[APROBADO]** · **[RECOMENDACIÓN]** · **[ABIERTA]**

---

## 0. Prerrequisitos

| Chequeo | Resultado |
|---|---|
| Rama | `feature/apis-akubica` sync `0 0` |
| P0-A4 | `b6f57c7` feat(akubica): add flag-gated OTP login flow |
| Código pendiente | ninguno |
| docs P0-A5 | solo `/docs/*` ignoradas |
| tmp/ | intacto |

---

## 1. Decisiones base **[APROBADO]**

1. Contacto verificado = **solo email**.
2. Teléfono = declarado, **no verificado**.
3. User **después** de OTP correcto.
4. Request inicial → **202 uniforme** (incluye colisiones → decoy).
5. Colisión email/phone/ambos → decoy; **no revelar** cuál.
6. Decoy: sin User / RegularAccount / Customer / token / delivery.
7. Token Sanctum **después** del commit de cuenta.
8. Sanctum `expiration=1440` min; JSON `expires_in` = segundos restantes.
9. TTL desafío registro = **10 minutos**.
10. Resend explícito solo con `challenge_id`.
11. Delivery nuevo **fuera de alcance**.
12. Flag register **OFF** por defecto; OFF = legacy idéntico.
13. **Sin** UNIQUE phone hasta auditoría + regla de negocio.

### Incompatibilidades con código actual **[HECHO]** (no cambiar silenciosamente)

| Aprobado | Código legacy hoy | Implicación |
|---|---|---|
| 202 uniforme + decoy | 200 + 409 EMAIL/PHONE explícitos | Solo con flag ON; OFF intacto |
| challenge_id + resend | sin challenge_id; re-POST `/register` | Nuevo contrato bajo flag |
| TTL 10 min en P0-A challenge | `otp.p0a.policy.ttl_minutes` default 5; legacy akubica 10 | Hace falta override por purpose/register |
| Verify sin email cliente | verify exige `email`+`code` | Cambio contract flag ON |
| Antiabuso | solo throttle 5/min | Depende `anti_abuse` |
| Token tras commit | token tras create (mismo request; `email_verified_at` fuera de TX register) | Hay que endurecer orden TX |

---

## 2. Modelo de identidad **[HECHO]**

### Tablas / columnas / índices

| Entidad | Soft delete | Unicidad relevante |
|---|---|---|
| `users` | **No** | `email` UNIQUE; `phone` **sin** UNIQUE (drop 2025-03-19); `phone_country` sin índice |
| `customers` | Sí | `user_id` FK nullable; morph `customerable`; `medical_attention_identifier` UNIQUE |
| `regular_accounts` | Sí | solo id/timestamps/deleted_at |
| Morph types | Regular / Odessa / Family / Certificate | comparten patrón Customer; **Family** puede no tener User |

**Email canónico:** `users.email`.  
**Teléfono canónico:** `users.phone` + `users.phone_country`.  
**Case email:** app hace `strtolower`; collation MySQL típica ci → unique case-insensitive en la práctica (confirmar staging).  
**Formato phone persistido:** `formatNational()` sin espacios (p.ej. MX `55########`). Lectura vía `RawPhoneNumberCast` + E164 en accesores.  
**NULL/vacío:** 1305 NULL + 162 `''` en local (ver evidencias).  
**Tipos de cuenta:** misma tabla `users` para login; tipificación en `customers.customerable_type`.  
**Observers User/Customer create:** no hay; ActiveCampaign observers no en User create.  
**Side effect CreateUserAction:** ReferralSignupNotification si hay referrer (Akubica no pasa referrer hoy).

Cadena Akubica: `User` ← `Customer.user_id` ← morph → `RegularAccount`.

---

## 3. Auditoría teléfono (local Docker)

Ver `docs/Akubica/evidencias/p0-a5-1-auditoria-telefono.md`.

Resumen local `mysql/famedic` APP_ENV=local:

- 9528 users; 8061 con teléfono no vacío.
- **3 grupos** duplicados literal / (country+phone) / dígitos → **6 filas**; todos `RegularAccount`, country MX.
- 14 customers soft-deleted; 4 users con phone y customer soft-deleted.
- **No** se propone UNIQUE todavía.

---

## 4. Normalización propuesta **[RECOMENDACIÓN]**

### `normalizeEmail(input) → string`

1. trim  
2. lowercase  
3. validar filtro email  
4. almacenar / antiabuso / subject = ese valor  
5. hash antiabuso = HMAC(purpose, subject_type=email, subject_key=normalized, context…)

### `normalizeMexicoPhone(input, country='MX') → {e164, national, country} | invalid`

1. trim; quitar espacios, guiones, paréntesis  
2. si empieza con `+`, parsear con libphone; country derivado  
3. MX: aceptar nacional 10 dígitos; `+52` + 10; **legacy `+521`** → normalizar a `+52` + 10 si dígitos encajan (**[ABIERTA]** regla exacta)  
4. persistir **national sin espacios** (compat CreateUserAction) + `phone_country` ISO-2  
5. identidad colisión = `(phone_country, national)`  
6. ambiguos / no parseables → 422 validación (no decoy)

### `buildPhoneIdentity` = `(country, national)`  
### `resolveRegistrationCollision(emailNorm, phoneId)` → `none | collide` (sin discriminar causa hacia el cliente)

| Entrada | Email norm | Tel norm (MX national) | Válido | Motivo |
|---|---|---|---|---|
| `Usuario.Ejemplo@EXAMPLE.COM` / `55 1234 5678` | `usuario.ejemplo@example.com` | `5512345678` | sí | |
| `usuario.ejemplo@example.com` / `+520000000000` | igual | `5512345678` | sí | |
| `usuario.ejemplo@example.com` / `+520000000000` | igual | `5512345678`? | **abierta** | legacy +521 |
| `usuario.ejemplo@example.com` / `(55)1234-5678` | igual | `5512345678` | sí | |
| `usuario.ejemplo@example.com` / `123` | — | — | no | corto |
| `bad` / `5512345678` | — | — | no | email |

### Matriz de colisiones (respuesta pública uniforme)

| Email | Teléfono | Interno | Público | Desafío real | Decoy |
|---|---|---|---|---:|---:|
| ambos nuevos | — | allow | 202 challenge | sí | no |
| email existe, tel nuevo | collide | 202 decoy | no | sí |
| email nuevo, tel existe | collide | 202 decoy | no | sí |
| ambos mismo user | collide | 202 decoy | no | sí |
| cada uno otro user | collide | 202 decoy | no | sí |
| email distinta capitalización | collide (ci) | 202 decoy | no | sí |
| tel formato equivalente | collide | 202 decoy | no | sí |
| concurrente en vuelo | 2º puede decoy o TX fail→decoy-equiv | 202/errores seguros | 1 real máx | según |
| soft-deleted customer, User vivo | email unique sigue vivo → collide | 202 decoy | no | sí |

---

## 5. Registro pendiente — alternativas

| | A meta cifrada en challenge | B tabla dedicada | C solo Cache | D híbrido challenge + payload cifrado |
|---|---|---|---|---|
| Confidencialidad | media-alta (Crypt) | alta | depende Redis | alta |
| Reinicio app | OK (MySQL) | OK | pierde | OK |
| Multi-nodo | OK | OK | Redis shared | OK |
| Misma TX create | sí | sí (FK) | **no** | sí |
| Atomicidad | buena | **mejor** | mala p/ real | **mejor** |
| Expiración/limpieza | TTL challenge + job | job dedicado | TTL cache | ambos |
| APP_KEY rotate | re-cifrar/ orphan | igual | n/a payload | igual |
| Tests | fácil | fácil | Carbon+cache | fácil |
| Pérdida Redis | n/a | n/a | **rompe pending+decoy** | decoy sí; pending no |

**[RECOMENDACIÓN] Alternativa D (práctica: B+ Crypt):**

- `otp_challenges` purpose=`akubica_register` (ciclo OTP + P0-A3).  
- Tabla `otp_registration_intents` (nombre TBD) 1:1 con challenge: payload **cifrado** (Crypt) + HMACs email/phone para observabilidad sin PII en clear en abuse events; plaintext normalizado solo en blob cifrado.  
- Decoy: **solo cache** (`AkubicaRegisterOtpDecoyStore`), sin intent ni challenge real.  
- Al vencer: challenge invalid/expired; intent irrecuperable (borrar job o ciphertext huérfano sin clave de lookup). Si el job no corre: ciphertext queda hasta retención max (p.ej. 24–48h) + GC; no usable sin challenge activo.

### Inventario campos pendientes

| Campo | Necesario | Sensible | Forma | Cifrado/hash | Retención |
|---|---:|---:|---|---|---|
| email | sí (create + unique) | sí | norm lowercase | Crypt en blob; HMAC aparte | TTL+gracia |
| phone | sí (create) | sí | national | Crypt; HMAC | idem |
| phone_country | sí | bajo | ISO-2 | Crypt | idem |
| full_name | sí → `users.name` | medio | trim | Crypt | idem |
| password | no persistir | — | generar en create | — | — |
| OTP | no | — | solo code_hash en challenge | — | — |
| IP | no en intent | — | abuse HMAC only | — | — |

Reglas: no OTP plano; no IP completa; no email/tel en abuse events; no PII en logs; `challenge_id` UUID; verify/resend **solo** `challenge_id` (+ code); no confiar body de identidad.

---

## 6. Decoy de registro

**[RECOMENDACIÓN]** `AkubicaRegisterOtpDecoyStore` **separado** (prefijo cache distinto). Extraer trait/abstract `OtpDecoyCycleStore` **solo si** se puede sin tocar comportamiento P0-A4 (login store intacto).

Ciclo público (real no-colisión vs decoy colisión / inexistente “ocupado”):

| Paso | HTTP | code | Forma |
|---|---|---|---|
| request | 202 | success data | requires_otp, challenge_id UUID, purpose, channel, destination_masked, expires_at Z, resend_available_at Z |
| verify malo | 422 | INVALID_CODE | error.code/message |
| resend inmediato | 429 | OTP_COOLDOWN | Retry-After, retry_after int, available_at Z |
| resend post-cooldown | 202 | success | nuevo challenge_id |
| verify reemplazado | 422 | CODE_INVALIDATED | |
| expirado | 422 | CODE_EXPIRED | |
| max attempts | 429 | OTP_MAX_ATTEMPTS | Retry-After… |
| usado (real) | 422 | CODE_ALREADY_USED | decoy nunca emite token; “éxito” imposible |
| UUID nunca emitido | 422 | NO_ACTIVE_CODE | **distinto** a decoy emitido (igual P0-A4) |

Decoy: sin otp_challenge user-bound, sin cuenta, sin token, sin delivery, sin buckets PII.  
**Riesgo Redis down:** decoys emitidos → degradan a NO_ACTIVE_CODE (oráculo residual). Mitigar Redis compartido HA. Pending real **no** depende de Redis (MySQL).

---

## 7. Atomicidad verify→creación

Orden objetivo **[APROBADO+RECOMENDACIÓN]**:

```
BEGIN
  lock challenge FOR UPDATE
  validar purpose/estado/expiry/intentos + Hash::check
  decrypt intent; renormalizar
  re-check colisiones email/phone
  si colisión → no crear; invalidar/consumir? → respuesta decoy-equivalente segura (sin filtrar)
  CreateUser + RegularAccount + Customer (+ email_verified_at) EN LA MISMA TX
  UPDATE challenge SET consumed_at WHERE … IS NULL  (condicional)
  si updated !== 1 → rollback
COMMIT
IssueAkubicaTokenAction  // fuera
```

### Respuestas puntuales

1. **¿Misma conexión MySQL?** **[HECHO]** Sí (default `mysql`); nested TX = savepoints.  
2. **¿lockForUpdate?** Sí en `OtpChallengeService::verify` hoy.  
3. **¿Validar sin consumir?** **No** API pública hoy: `verify()` consume en la misma TX interna y **commitea** al retornar.  
4. **¿Nueva operación?** **Sí** — p.ej. `verifyRegisterAndProvision(...)` / callback atómico; no encadenar `verify()` + create fuera.  
5. **Eventos pre-commit:** no observers User/Customer; Referral N/A.  
6. **Jobs en rollback:** evitar `notify`/queue dentro de TX; token fuera.  
7. **afterCommit:** notificaciones futuras, métricas, delivery futuro.  
8. **Doble verify:** lock + consume condicional + email UNIQUE → un User.  
9. **Sin UNIQUE phone:** **no** hay garantía DB cero duplicados phone; solo re-check en TX (TOCTOU reducido, no eliminado entre procesos sin lock de filas phone). **Riesgo bloqueante para ACTIVACIÓN**, no para código flag-OFF.  
10. **UNIQUE email** protege doble cuenta mismo correo.  
11. **updated !== 1:** rollback cuenta; respuesta CODE_ALREADY_USED / genérica; sin token.

---

## 8. Matriz de fallos

| Punto de fallo | Desafío | Cuenta | Token | Recuperación |
|---|---|---|---|---|
| código incorrecto | attempts++ | no | no | reintentar / resend |
| expirado | expired | no | no | nuevo register |
| reemplazado | invalidated | no | no | usar nuevo id |
| intentos agotados | invalidated + 429 | no | no | esperar / nuevo |
| colisión inicial | decoy cache | no | no | login si aplica (sin decirlo) |
| colisión en TX | no consume / rollback | no | no | respuesta segura tipo verify fallido genérico o invalidar |
| fallo User | rollback | no | no | reintentar verify si challenge intacto |
| fallo RegularAccount/Customer | rollback | no | no | igual |
| fallo consume tras create | rollback | no | no | igual |
| deadlock/timeout | rollback | no | no | retry idempotente |
| commit fallido | no commit | no | no | retry |
| token post-commit falla | consumed | **sí** | no | **login P0-A4** |
| HTTP perdida tras token | consumed | sí | sí emitido | cliente re-login; no 2º user |
| 2 verifies simultáneos | 1 consume | 1 user | ≤1 | |
| Redis decoy perdido | — | — | — | NO_ACTIVE_CODE residual |
| delivery N/A | — | — | — | fase delivery |

---

## 9. Contrato HTTP propuesto

### Rutas **[RECOMENDACIÓN]**

| Flag OFF (legacy) | Flag ON |
|---|---|
| `POST /auth/register` | mismo path, contrato 202+challenge |
| `POST /auth/register/verify-code` | `challenge_id`+`code` (sin email) |
| — | `POST /auth/register/resend-code` (`challenge_id`) |

Alternativa evaluada: `/register/request-code` (simetría login). **Recomendación:** no renombrar `/register` para menos quiebre; documentar alias opcional si producto lo pide **[ABIERTA menor]**.

Middleware: `force.json`, `api.token.guard`, `throttle:akubica-otp` (+ antiabuso dominio).

**Verify/resend ignoran** email, phone, name, country, purpose, user_id.

**Token success:** igual legacy + `expires_in` segundos restantes.

---

## 10. Matriz de flags

| Flag | Default | Deps | OFF | ON |
|---|---:|---|---|---|
| infrastructure | false | — | sin wiring forzado | prereq blando |
| anti_abuse | false | — | legacy | **obligatorio** con register ON |
| akubica_login | false | anti_abuse | legacy login | P0-A4 |
| **akubica_register** | **false** | **anti_abuse** (+ cache shared p/ decoy; APP_KEY p/ Crypt) | **legacy exacto** | flujo nuevo |
| sms_delivery | false | — | n/a | fuera P0-A5 |
| email_fallback | false | — | n/a | fuera |
| sanctum_3h | false | — | 1440 | no tocar en A5 |

Config inválida (register ON, anti_abuse OFF) → 503 genérico `OTP_CONFIGURATION_INVALID`.

| Nivel | Requisitos |
|---|---|
| Código existe apagado | flag false; tests OFF |
| Tests ON en CI | anti_abuse + cache array/redis test + Crypt |
| Activar pacientes | anti_abuse + Redis HA + Crypt/APP_KEY estable + **regla phone/UNIQUE** + staging concurrency + sin delivery o delivery aprobado |

---

## 11. Bloques posteriores

| Bloque | Responsabilidad | Fuera |
|---|---|---|
| **5.2** config, normalize, flags, contracts | **HECHO** — `evidencias/p0-a5-2-config-contratos-normalizacion.md`; resend inerte 503 | delivery |
| **5.3** intents cifrados + retención | **HECHO (interno OFF)** — `evidencias/p0-a5-3-intents-cifrados-lifecycle.md`; sin endpoints | UNIQUE phone |
| **5.4** request/resend/decoy | **HECHO** — `evidencias/p0-a5-4-request-resend-decoy.md`; verify create diferido | verify create |
| **5.5** verify TX + create + token afterCommit | **HECHO** — `evidencias/p0-a5-5-verify-create-token.md`; delivery diferido | delivery |
| **5.6** staging MySQL/Redis concurrency | **PASS CON LIMITACIONES** — `evidencias/p0-a5-6-concurrencia-mysql-redis.md` (MySQL local 8.0; Redis ausente) | prod flag |
| **5.7** delivery + activación | solo con auth | — |

---

## 12. Pruebas propuestas (post-implementación)

Incluir matriz del prompt §14.  
**SQLite/CI:** flags OFF, decoy ciclo con Cache array, TX básica, expires_in, P0-A4 regresión.  
**MySQL/Redis real:** lockForUpdate races, UNIQUE email bajo load, decoy multi-nodo, pérdida Redis, phone re-check races.

---

## 13. Decisiones aún **[ABIERTA]**

| ID | Decisión | Opciones | Recomendación | Impacto | Bloq. impl | Bloq. activación |
|---|---|---|---|---|---:|---:|
| D1 | Unicidad teléfono | global / por country / no unique | global `(phone_country, phone)` tras limpieza | datos | no | **sí** |
| D2 | Teléfonos ya duplicados (3 grupos local) | merge / forzar cambio / allow share | resolver caso a caso pre-UNIQUE | ops | no | **sí** |
| D3 | Normalización `+521` | strip 1 / reject / map | map a +52+10 si válido | auth MX | no | sí si SMS futuro |
| D4 | Soft-delete customer + user vivo | decoy vs permitir | decoy (email unique) | UX | no | no |
| D5 | Nombre tabla intents | intents vs pending_registrations | `otp_registration_intents` | schema | no | no |
| D6 | Retención ciphertext huérfano | 24h / 48h / =TTL | 24h GC | storage | no | no |
| D7 | Pérdida Redis decoy | aceptar residual / fail-closed register | residual documentado + Redis HA | enum residual | no | sí prod |
| D8 | Path request-code vs `/register` | rename / keep | **keep `/register`** | cliente | no | no |
| D9 | Delivery | email sync legacy vs cola | fuera; tests fake | — | no | sí go-live OTP |
| D10 | Staging gate | checklist formal | obligatorio antes flag ON | | no | **sí** |
| D11 | Respuesta perdida post-token | solo login | login P0-A4 | UX | no | no |

---

## 14. Riesgos

**Bloqueantes implementación (código OFF):** ninguno de datos; sí diseño de API atómica nueva (no usar verify()+create).  
**Bloqueantes activación:** D1/D2 phone; Redis HA decoy; concurrency staging; política +521; delivery aprobado; anti_abuse ON.

---

## 15. Confirmaciones de esta fase (P0-A5.1)

Sin cambios de código · sin migraciones · sin commit/push · Sanctum 1440 · flags OFF · cero delivery · tmp intacto.

### Actualización P0-A5.2 (2026-07-27)

Implementación flag-gated OFF de config/contratos/normalización/colisiones internas.  
Detalle: `docs/Akubica/evidencias/p0-a5-2-config-contratos-normalizacion.md`.  
Sin intents, sin OTP nuevo, sin decoy registro, sin UNIQUE phone, sin activación, sin commit/push.

### Actualización P0-A5.3 (2026-07-27)

Persistencia interna `akubica_registration_intents` + Crypt + lifecycle + comando expire.  
Detalle: `docs/Akubica/evidencias/p0-a5-3-intents-cifrados-lifecycle.md`.  
Sin wiring HTTP, sin delivery, sin decoy, sin create cuenta/token, `isPatientReady` false, sin commit/push.

### Actualización P0-A5.4 (2026-07-27)

Wiring HTTP request/resend + decoy Cache bajo `akubica_register_enabled`.  
Detalle: `docs/Akubica/evidencias/p0-a5-4-request-resend-decoy.md`.  
Verify create diferido a 5.5; cero delivery; `isPatientReady` false; flags OFF por defecto.

### Actualización P0-A5.5 (2026-07-27)

Verify atómico + create User/RA/Customer + consume challenge/intent + token afterCommit.  
Detalle: `docs/Akubica/evidencias/p0-a5-5-verify-create-token.md`.  
Cero delivery; `isPatientReady` false; concurrencia staging diferida a 5.6; activación a 5.7.

### Actualización P0-A5.6 (2026-07-27)

Concurrencia MySQL local (Docker): verify paralelo 10×3 PASS; phone race D1 reproducida; Redis no disponible.  
Detalle: `docs/Akubica/evidencias/p0-a5-6-concurrencia-mysql-redis.md`.  
Clasificación PASS CON LIMITACIONES; sin cambios de código; sin commit/push. Resta **5.7**.
