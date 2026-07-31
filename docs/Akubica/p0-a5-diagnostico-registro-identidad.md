# P0-A5 — Diagnóstico: registro e identidad/contacto Akubica

**Fecha:** 2026-07-27  
**Rama:** `feature/apis-akubica`  
**HEAD:** `b6f57c7` — `feat(akubica): add flag-gated OTP login flow` (P0-A4)  
**Alcance:** solo diagnóstico y diseño. Sin implementación.

> **Actualización P0-A5.1:** el diseño cerrado (anti-enumeración, atomicidad, intents cifrados, auditoría teléfono, contrato HTTP, flags y bloques 5.2–5.7) está en  
> `docs/Akubica/p0-a5-1-diseno-registro-seguro.md`  
> y evidencias de teléfono en `docs/Akubica/evidencias/p0-a5-1-auditoria-telefono.md`.  
> Este documento conserva el diagnóstico factual P0-A5; ante conflicto de diseño, prevalece P0-A5.1 + decisiones aprobadas del prompt.

---

## 1. Prerrequisitos verificados

| Chequeo | Resultado |
|---|---|
| Rama | `feature/apis-akubica` |
| Sync origin | `0 0` (alineado) |
| Commit P0-A4 | `b6f57c7` feat(akubica): add flag-gated OTP login flow |
| Workspace código | **limpio** (sin cambios pendientes) |
| docs/ | ignoradas (`/docs/*`); pueden existir docs P0-A4 locales |
| Commit / push en este diagnóstico | **no realizados** |

---

## 2. Separación de consumidores OTP (no mezclar)

| # | Flujo | Ubicación | Store | Propósito |
|---|---|---|---|---|
| 1 | **Registro Akubica API v1** | `RegisterController` | `otp_codes` | `akubica_register` |
| 2 | **Login Akubica API v1** | `LoginController` | legacy `otp_codes` o P0-A4 `otp_challenges` | `akubica_login` |
| 3 | OTP web laboratorio / resultados | controllers lab (fuera v1 auth) | `otp_codes` (histórico) | distinto |
| 4 | Verify phone web | `routes/auth.php` VerifyPhone* | flujo web | no API v1 |
| 5 | Register web / Odessa | `RegisteredUserController`, `OdessaRegisterController` | no Akubica OTP | aparte |
| 6 | Admin verify email/phone | `routes/admin.php` | admin | aparte |

Este diagnóstico se limita a **(1)**.

---

## 3. Rutas de registro API v1

Prefijo: `/api/v1`  
Middleware grupo: `force.json`, `api.token.guard`  
Auth: **pública** (sin Sanctum)

| Método | URL | Nombre | Throttle |
|---|---|---|---|
| POST | `/api/v1/auth/register` | `api.v1.auth.register` | `throttle:akubica-otp` (5/min por `ip\|email`) |
| POST | `/api/v1/auth/register/verify-code` | `api.v1.auth.register.verify-code` | mismo |

**No existe** `register/resend-code`. El reenvío es volver a llamar `POST /register` (invalida OTP pending previo del mismo email+purpose).

Fuente: `routes/api/v1.php`.

---

## 4. Flujo completo actual

```
Cliente POST /auth/register {email, phone, full_name, phone_country?}
  → validación RegisterRequest
  → 409 si email existe (User.email)
  → 409 si teléfono existe (app-level: phone nacional + phone_country)
  → IssueAuthOtpAction(purpose=akubica_register, payload={email,phone,full_name,phone_country}, notifiable=email string)
      → marca OTP pending previos como expired
      → crea otp_codes (code hashed, payload JSON, channel=email, ip_address plaintext)
      → Notification on-demand mail (AkubicaOtpNotification)
  → 200 { verification_sent, expires_in (segundos TTL), channel }

Cliente POST /auth/register/verify-code {email, code}
  → VerifyAuthOtpAction(email, code, akubica_register)
      → consume OTP (status=verified, used_at)
  → relee payload del OTP
  → 409 si email/teléfono ya existen (carrera)
  → RegisterAkubicaCustomerAction(payload)
      → RegisterRegularCustomerAction (User + RegularAccount + Customer) en TX
      → forceFill email_verified_at
      → password aleatorio 32 chars (no expuesto)
  → IssueAkubicaTokenAction → Sanctum
  → 200 { token, token_type, expires_in (segundos restantes), expires_at, user }
```

**Usuario NO se crea en el request inicial.** Solo tras OTP correcto.

**Teléfono NO se verifica por OTP.** Solo se captura en payload y se persiste al crear User. `phone_verified_at` no se setea en este flujo.

**Identidad verificada (de facto):** posesión del correo (OTP email). Nombre y teléfono se confían del payload ligado al OTP de ese email.

---

## 5. Controllers, requests, actions, services

| Pieza | Archivo |
|---|---|
| Controller | `app/Http/Controllers/Api/V1/Auth/RegisterController.php` |
| Request store | `app/Http/Requests/Api/V1/Auth/RegisterRequest.php` |
| Request verify | `app/Http/Requests/Api/V1/Auth/RegisterVerifyCodeRequest.php` |
| Issue OTP | `app/Actions/Api/V1/Auth/IssueAuthOtpAction.php` |
| Verify OTP | `app/Actions/Api/V1/Auth/VerifyAuthOtpAction.php` |
| Register user | `app/Actions/Api/V1/Auth/RegisterAkubicaCustomerAction.php` |
| Regular customer | `app/Actions/Register/RegisterRegularCustomerAction.php` |
| Create user | `app/Actions/Users/CreateUserAction.php` |
| Create customer | `app/Actions/Customers/CreateRegularAccountCustomerAction.php` |
| Token | `app/Actions/Api/V1/Auth/IssueAkubicaTokenAction.php` |
| Notification | `app/Notifications/Api/V1/Auth/AkubicaOtpNotification.php` |
| Exception OTP | `app/Exceptions/Api/V1/Auth/AuthOtpVerificationException.php` |

No hay service layer dedicado de registro distinto de estas actions.

---

## 6. Modelos y tablas

| Entidad | Rol |
|---|---|
| `otp_codes` | OTP registro (email, purpose, payload, code hash, attempts, ip_address, …) |
| `users` | creado en verify; `email` UNIQUE; `phone` **sin unique** (migración 2025_03_19) |
| `customers` | ligado a user + medical_attention_identifier |
| `regular_accounts` | morph/cuenta regular |
| `personal_access_tokens` | Sanctum tras verify |

Índice OTP: `(email, purpose, status)` — `otp_codes_email_purpose_status_index`.

---

## 7. Contratos HTTP actuales

### POST `/api/v1/auth/register`

**Request:** `email` required email; `phone` required; `full_name` min 3; `phone_country` optional size 2 (default MX). Phone validado con `Propaganistas\LaravelPhone`.

**Éxito 200:**
```json
{ "success": true, "data": { "verification_sent": true, "expires_in": 600, "channel": "email" } }
```
`expires_in` aquí = **TTL OTP en segundos** (`otp_ttl_minutes * 60`), no Sanctum.

**Errores:**

| Caso | HTTP | code |
|---|---|---|
| Validación | 422 | `VALIDATION_ERROR` |
| Email existe | 409 | `EMAIL_ALREADY_REGISTERED` |
| Teléfono existe | 409 | `PHONE_ALREADY_REGISTERED` |
| Fallo delivery mail | 503 | `DELIVERY_FAILED` |

### POST `/api/v1/auth/register/verify-code`

**Request:** `email` + `code` size 6. **No** acepta phone/full_name (vienen del payload OTP).

**Éxito 200:** token Sanctum + user `{id,email,name}`.  
`expires_in` = **segundos restantes** hasta `expires_at` (~86400 si Sanctum 1440 min).

**Errores OTP:** 422 `NO_ACTIVE_CODE` / `CODE_EXPIRED` / `INVALID_CODE` / `ATTEMPTS_EXHAUSTED`.  
**Colisión post-OTP:** 409 email/phone.  
**Payload corrupto / fallo create:** 500 `INTERNAL_ERROR` (OTP ya consumido).

---

## 8. Identidad y normalización

### Identidad canónica actual

- **Clave de OTP:** email (lowercased).
- **Unicidad User:** email (DB unique).
- **Unicidad teléfono:** solo chequeo de aplicación (`phone` nacional + `phone_country`); **sin índice unique en DB**.
- **Customer:** se crea 1:1 con User en verify.

### Email

- `strtolower` en controller.
- Validación Laravel `email`.
- Unique DB case-sensitivity depende de collation MySQL (típicamente case-insensitive en producción latin1/utf8_ci — confirmar staging).

### Teléfono

- Default country `MX`.
- Si empieza con `+`, se deriva country vía PhoneNumber.
- Espacios eliminados; formato E.164 parcial aceptado en input.
- Persistencia: `formatNational()` sin espacios (CreateUserAction / phoneAlreadyRegistered).
- MX +52 → nacional tipo `5512345678`.

---

## 9. Matriz de colisiones

| Email | Teléfono | Situación actual | Riesgo | Decisión recomendada (abierta) |
|---|---|---|---|---|
| Nuevo | Nuevo | OTP enviado; User en verify | Bajo | Mantener: crear tras OTP |
| Existente | Nuevo | 409 EMAIL en register | Enumeración email | Producto: ¿409 explícito o mensaje genérico? |
| Nuevo | Existente | 409 PHONE en register | Enumeración phone; DB permite duplicado si race | Reforzar unique compuesto o política explícita |
| Mismo user (ambos) | — | 409 EMAIL (chequeo email primero) | UX “ya registrado” | ¿Redirigir a login? |
| Email user A / phone user B | — | 409 EMAIL o PHONE según orden | Confusión de propiedad | Definir prioridad y mensaje único seguro |
| Ambos nuevos, 2 clientes paralelos | — | Ambos reciben OTP; verify race | Doble create / unique email | Lock + TX única consume+create |

---

## 10. OTP actual del registro

| Atributo | Valor evidenciado |
|---|---|
| Tabla | `otp_codes` |
| Propósito | `akubica_register` |
| Sujeto | `email` columna |
| Contexto | `payload` JSON (datos registro) |
| Longitud | `config('akubica.otp_length')` default **6** |
| Generador | `random_int` en `IssueAuthOtpAction` (no `OtpCodeGenerator` P0-A) |
| Store | **Hash::make** (no plano) |
| TTL | `akubica.otp_ttl_minutes` default **10** |
| Cooldown dominio | **ninguno** (solo throttle HTTP 5/min) |
| Max intentos | default **5** |
| Max reenvíos | no contado; re-POST register invalida pending previos |
| Invalidación previo | sí (status → expired) |
| Consumo | `used_at` + status verified |
| Atomicidad verify | **sin** `lockForUpdate` |
| Canal | **email** only (`CHANNEL_EMAIL`) |
| IP | `request()->ip()` en claro en `otp_codes.ip_address` |
| Antiabuso P0-A3 | **no** cableado |
| Identidad inexistente | N/A en register (siempre “nuevo”); 409 si existe |

### vs infraestructura P0-A

| Componente | Reutilizable en P0-A5 | Nota |
|---|---|---|
| `P0aOtpPurpose::AkubicaRegister` | Sí | Ya existe en enum |
| `OtpChallenge` / `OtpChallengeService` | Sí | Reemplazar `otp_codes` |
| `OtpAbusePolicy` / rate limits | Sí | Cooldown/IP/identidad |
| `OtpCodeGenerator` | Sí | Unificar generación |
| `OtpExceptionHttpMapper` | Sí (ampliar) | Ya mapea códigos P0-A |
| `AkubicaLoginOtpService` / DecoyStore login | **No reutilizar a ciegas** | Superficie distinta (409 vs anti-enum login) |
| Flag `akubica_login_enabled` | Aislar | Register necesita flag propio |
| Legacy `IssueAuthOtpAction` | Conservar con flag OFF | Contrato actual |

---

## 11. Emisión de token

Tras verify + create user exitoso → `IssueAkubicaTokenAction` → `createToken('akubica', abilities)`.  
`config('sanctum.expiration')` = **1440 minutos**.  
JSON `expires_in` = **segundos restantes** (~86400).

Token **no** se emite en `POST /register` (solo OTP).

---

## 12. Efectos secundarios

| Efecto | Cuándo | Sync/cola | Idempotente | Si falla |
|---|---|---|---|---|
| Crear `otp_codes` | register | sync | re-issue expira previos | 503 si mail falla tras create (status failed) |
| Mail OTP | register | sync Notification | — | 503 DELIVERY_FAILED |
| Crear User | verify | sync TX | no | 500; OTP ya consumido |
| RegularAccount + Customer + medical id | verify | sync TX anidada | no | rollback user si TX externa ok… nested TX en RegisterRegularCustomer |
| `email_verified_at` | verify post-create | sync | sí | — |
| Password random | create | sync | — | no expuesto |
| Sanctum token | verify post-create | sync | nuevo token cada vez | user ya creado |
| `phone_verified_at` | **no** | — | — | teléfono no verificado |
| ActiveCampaign / Murguía / GDA / Odessa | **no observado** en este path | — | — | — |
| Roles Spatie | no en path Akubica register | — | — | — |

---

## 13. Seguridad — hallazgos

### P0 bloqueante (para P0-A5)

1. **Enumeración explícita** email/teléfono vía 409 distintos en register (contrasta con login anti-enum).  
2. **OTP consumido antes de create user** sin compensación → verify exitoso OTP + 500 deja al usuario sin cuenta y sin OTP reusable.  
3. **Verify sin lock** → carrera de doble consumo/creación.  
4. **Teléfono sin unique DB** → duplicados posibles bajo carrera pese a chequeo app.  
5. **IP completa** en `otp_codes`.  
6. **Sin cooldown/antiabuso de dominio** (solo throttle 5/min).

### P1 importante

7. Teléfono no verificado por OTP pero se trata como dato de registro.  
8. `full_name` → solo `name`; sin split apellidos.  
9. Re-register como “resend” sin contrato de resend ni challenge_id.  
10. Logs con email en errores de delivery/register.

### P2 mejora

11. Throttle key `ip|email` no cubre abuso multi-email desde misma IP agresiva más allá de 5/min por clave.  
12. Mensaje `NO_ACTIVE_CODE` menciona “este correo” (ligera señal).  
13. Password aleatorio nunca usable por cliente (OK si passwordless).

---

## 14. Pruebas existentes y brechas

Fuente: `tests/Feature/Api/V1/AkubicaAuthTest.php` (únicas de registro API v1).

| Caso | Test | Cobertura | Brecha |
|---|---|---|---|
| Validación 422 | sí | básica | phone inválido E.164 parcial |
| Email duplicado 409 | sí | sí | — |
| Phone duplicado 409 | sí | sí | race DB |
| OTP creado + mail | sí | sí | payload phone assert parcial |
| Verify crea user+token | sí | sí | phone_verified_at, customer fields |
| OTP no reutilizable | sí | sí | — |
| Token → cart | sí | sí | — |
| OTP hashed | sí (login path) | hash | register path explícito |
| OTP incorrecto/expirado/intentos registro | **no** | — | P0 gap |
| DELIVERY_FAILED | **no** | — | |
| INTERNAL_ERROR post-consume | **no** | — | |
| Carrera concurrente | **no** | — | |
| Email+phone users distintos | **no** | — | |
| Re-POST register = resend | **no** | — | |
| Flags P0-A OFF | implícito (no hay flag register) | — | |

### Resultados ejecución (diagnóstico)

- `AkubicaAuthTest`: **17 passed / 69 assertions**, ~20.8s  
- `tests/Feature/Api/V1`: **344 passed / 1284 assertions**, ~282s  

---

## 15. Propuesta mínima P0-A5 (sin implementar)

### Matriz de flags

| Flag | Default | Comportamiento |
|---|---|---|
| `otp.p0a.flags.akubica_register_enabled` (nuevo) | **false** | OFF → registro legacy exacto |
| `otp.p0a.flags.anti_abuse_enabled` | false | Register ON exige anti_abuse ON (igual que login); si no → 503 config |
| `akubica_login_enabled` | false | **aislado**; no acoplar |

### Diseño sugerido

1. `AkubicaRegisterOtpService` (espejo de login, **no** compartir DecoyStore de login).  
2. Propósito `akubica_register`; subject email; destination email; `meta`/campos challenge con phone masked + hashes necesarios **sin** PII extra en abuse events.  
3. Pending registration = challenge activo + meta (phone normalizado hash?, full_name) — **sin User** hasta verify.  
4. Endpoints (conservar URLs o añadir resend):  
   - `POST /auth/register` → 202 + `challenge_id` cuando flag ON  
   - `POST /auth/register/verify-code` → challenge_id + code (dejar de confiar email del cliente como autoridad)  
   - `POST /auth/register/resend-code` (nuevo, flag ON)  
5. Token solo tras: OTP OK + consume atómico + create User/Customer en **misma estrategia clara** (idealmente TX con compensación documentada).  
6. Duplicados: decidir producto (409 vs respuesta uniforme). Si se mantienen 409, documentar enumeración aceptada para registro.  
7. Delivery real: **fuera de P0-A5** (igual P0-A4); Fake generator en tests.  
8. Sanctum 1440 intacto.  
9. Compatibilidad P0-A4: propósitos/buckets aislados por purpose+context.

### Decoy / anti-enum registro

**No reutilizar automáticamente** `AkubicaLoginOtpDecoyStore`.

Razones:

- Login oculta existencia; registro hoy **revela** existencia con 409.  
- Registro protege **dos** identidades (email y phone) con mensajes distintos.  
- Decoy de “registro pendiente” vs “cuenta existente” son problemas distintos.

Opciones a decidir:

- **A)** Mantener 409 explícitos (UX clara; acepta enumeración en registro).  
- **B)** Respuesta uniforme 202 + ciclo decoy (como login) para no existentes y existentes — más complejo (decoy no debe permitir crear User).  
- **C)** Híbrido: 409 solo tras OTP (retrasar enumeración) — aún filtrable.

**Recomendación provisional:** empezar P0-A5 con **A** documentada + P0-A challenges/antiabuso, y tratar anti-enum registro como decisión de producto explícita antes de cablear decoys.

### Atomicidad propuesta

1. `lockForUpdate` + consume condicional (P0-A2).  
2. Dentro de TX (o saga documentada): create User+Customer; si falla tras consume → error recuperable con **nuevo** challenge (no reabrir OTP).  
3. Unique email DB como red de seguridad; evaluar unique `(phone, phone_country)` si producto lo exige.

### Contrato HTTP propuesto (flag ON, borrador)

**Inicio 202:** `requires_otp`, `challenge_id`, `purpose=akubica_register`, `channel`, `destination_masked`, `expires_at`, `resend_available_at`.  
**Verify 200:** igual contrato token actual.  
**Resend 202:** nuevo challenge_id.  
**429:** códigos P0-A3 vía mapper existente.

---

## 16. Decisiones abiertas (hecho vs recomendación)

| # | Pregunta | Hecho hoy | Recomendación | Impacto |
|---|---|---|---|---|
| 1 | ¿Verifica email, phone o ambos? | Solo email OTP | Mantener email en P0-A5; phone verify = fase posterior | Menos scope |
| 2 | ¿Canal inicial? | Email | Email; SMS tras flag delivery | Sin Vonage ahora |
| 3 | ¿User antes o después OTP? | Después | Mantener después | Evita basura |
| 4 | ¿Registro pendiente sin User? | `otp_codes.payload` | `otp_challenges` + meta | Alineado P0-A2 |
| 5 | ¿Contacto ya registrado? | 409 | Decisión producto A/B/C | Enumeración |
| 6 | ¿Login si ya existe? | No | Opcional 409 + hint login | UX |
| 7 | ¿Token al verify o login luego? | Token al verify | Mantener (compat cliente) | Menos fricción |
| 8 | ¿Datos en desafío? | email, phone, name, country | Igual + masked; sin IP raw | PII |
| 9 | ¿TTL? | 10 min legacy | Flag OFF 10; ON usar `otp.p0a.policy.ttl` (5) o alinear | Contrato expires |
| 10 | ¿Fallo create tras consume? | 500 + OTP muerto | TX/compensación + métrica; nuevo OTP | Fiabilidad |
| 11 | ¿Contrato cliente? | register + verify email/code | Añadir challenge_id / 202 | Breaking si flag ON |
| 12 | ¿Cache decoy registro? | N/A | Solo si se elige anti-enum B | Ops Redis |
| 13 | ¿Staging MySQL/Redis? | — | Validar unique races + abuse buckets | Go-live |

---

## 17. Riesgos pendientes post-diagnóstico

- Enumeración aceptada vs eliminada.  
- Unique phone en DB.  
- Ventana consume→create.  
- Delivery real diferido.  
- Separación estricta login/register flags.  
- No tocar frontend React/Inertia en P0-A5.

---

## 18. Confirmaciones

- Cero cambios de código en este diagnóstico.  
- Sanctum 1440 intacto.  
- Flags P0-A actuales apagados; no existe aún flag register.  
- OTP legacy registro intacto.  
- Sin delivery real ejecutado.  
- Sin commit / sin push.  
- `tmp/` no modificado.
