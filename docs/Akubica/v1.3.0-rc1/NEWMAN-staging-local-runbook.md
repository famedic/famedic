# Newman RC1 contra staging — runbook local (sin modificar scripts)

Documento de inspección. Describe cómo ejecutar `scripts/run-newman-rc1-staging.sh` desde tu máquina contra **staging**, sin commitear secretos.

---

## 1. Resumen ejecutivo

| Área | Dónde vive |
|------|------------|
| Secretos ATIX (HMAC) | **Forge staging** (servidor) + **terminal local** (Newman firma peticiones) — mismos pares sandbox |
| Teléfonos E.164 de prueba | **Solo terminal local** (`RC1_STAGING_*`) → Postman `phone` / `unknown_phone` |
| OTP para verify RC1 | **SMS** (login P0-A) o **`OTP_CODE` en terminal** — **no** en Git ni en JSON Postman |
| Fallback correo OTP login RC1 | **No existe en runtime** para login P0-A (`deliverLoginSafely` = solo SMS) |
| Reportes | `docs/Akubica/v1.3.0-rc1/newman-staging-*-sanitized.*` (raw se borra tras sanitizar) |

---

## 2. Forge staging — variables y finalidad

Configurar en el entorno del sitio **staging** (Forge → Environment). Tras cambios: `php artisan config:cache` en el servidor.

### 2.1 Entorno de aplicación

| Variable | Finalidad | En Forge | En Git |
|----------|-----------|----------|--------|
| `APP_ENV` | Debe ser **`staging`** para cabeceras OTP diagnóstico (`local`/`staging` only) y políticas de no-prod. | **Sí** | No (solo `.env.example` comentarios) |
| `APP_URL` | URL pública HTTPS del sitio (DLR Vonage opcional, enlaces). | **Sí** | No |

### 2.2 OTP P0-A — login contextual RC1 (colección Newman)

| Variable | Default código | Staging RC1 (recomendado QA) | Finalidad |
|----------|----------------|----------------------------|-----------|
| `OTP_P0A_AKUBICA_LOGIN_ENABLED` | `false` | **`true`** | Activa `AkubicaLoginOtpService` en `LoginController` (phone + `otp_challenges`). |
| `OTP_P0A_ANTI_ABUSE_ENABLED` | `false` | **`true`** | Obligatorio si login P0-A ON; si no → `OTP_CONFIGURATION_INVALID`. |
| `OTP_P0A_SMS_DELIVERY_ENABLED` | `false` | **`true`** | Permite llamar al proveedor SMS tras crear challenge. |
| `OTP_P0A_DELIVERY_DRIVER` | `null` | **`vonage`** | Selecciona proveedor en `AppServiceProvider` (`null` / `fake` / `vonage`). |
| `OTP_P0A_EMAIL_FALLBACK_ENABLED` | `false` | **`false`** para RC1 login | Solo afecta **registro** P0-A (`deliverRegisterSafely`); **login P0-A ignora fallback email**. |
| `OTP_P0A_REQUIRE_VERIFIED_PHONE` | `true` | **`true`** | Policy `otp.p0a.policy.require_verified_phone`; sin verificación → decoy (anti-enumeración). |

**Importante:** `OTP_P0A_EMAIL_FALLBACK_ENABLED` **no** envía OTP de **login** por correo. El código lo prohíbe explícitamente en `AkubicaSecureOtpDeliveryOrchestrator::deliverLoginSafely`.

Variables relacionadas (staging QA, no RC1 mínimo):

| Variable | Notas |
|----------|--------|
| `OTP_P0A_INFRASTRUCTURE_ENABLED` | Requerida para **registro** P0-A; login P0-A no la exige en `assertConfigurationReady`. |
| `OTP_P0A_TTL_MINUTES`, `OTP_P0A_COOLDOWN_SECONDS`, etc. | Política OTP; ver `config/otp.php`. |

**Nunca en staging RC1 Newman:** `OTP_P0A_DELIVERY_DRIVER=fake` (no entrega SMS y **no** deja recuperar el código).

### 2.2.1 Revelación OTP login (solo staging, temporal RC1)

Mecanismo **administrativo** para QA cuando no hay SMS en terminal. **No** sustituye `OTP_CODE` en Newman; **no** aparece en APIs públicas ni en reportes sanitizados.

| Variable | Default | Staging RC1 (opcional) | Finalidad |
|----------|---------|------------------------|-----------|
| `OTP_STAGING_DIAGNOSTIC_CODE_REVEAL_ENABLED` | **`false`** | **`true`** solo durante validación Newman | Activa vault cifrado + botón en detalle del monitor OTP (requiere también `APP_ENV=staging`). |

| Permiso admin | Finalidad |
|---------------|-----------|
| `otp-movements.otp-reveal` | POST revelación un solo uso (`/admin/otp-movements-monitor/reveal-login-otp`). La migración lo asigna al rol **Administrador**; revocar a usuarios que no deban usarlo. |

**Uso:** tras `request-code`, abrir el movimiento en `/admin/otp-movements-monitor/{movementKey}` → panel **Revelar OTP (staging)** → copiar código **una vez** para verify o para `OTP_CODE` local.

**Deshabilitar después de Newman (Forge staging):**

1. `OTP_STAGING_DIAGNOSTIC_CODE_REVEAL_ENABLED=false` (o eliminar la línea).
2. `php artisan config:cache`.
3. Opcional: revocar `otp-movements.otp-reveal` a admins que no lo necesiten.

**Prohibido:** activar en production (el runtime responde 404 y no persiste plaintext). No documentar OTP en evidencia pública ni en JSON Newman.

### 2.3 Vonage (servidor staging)

Mapeo: `config/vonage.php` ← env.

| Variable | Finalidad | En Forge | En Git |
|----------|-----------|----------|--------|
| `VONAGE_KEY` | API key SMS | **Sí** | **Nunca** |
| `VONAGE_SECRET` | API secret SMS | **Sí** | **Nunca** |
| `VONAGE_SMS_FROM` | Remitente SMS | **Sí** | **Nunca** |

Opcionales (monitor admin / DLR, no Newman):

| Variable | Finalidad |
|----------|-----------|
| `VONAGE_SMS_DLR_ENABLED` | Receipts de entrega |
| `VONAGE_SMS_DLR_WEBHOOK_TOKEN` | Token webhook (≥32 chars) |
| `VONAGE_SMS_DLR_CALLBACK_BASE_URL` | Base HTTPS pública |
| `VONAGE_SMS_DIAGNOSTIC_ENABLED` | Prueba SMS desde admin OTP monitor |
| `VONAGE_SMS_DIAGNOSTIC_ALLOWED_DESTINATIONS` | Allowlist E.164 coma-separada |
| `VONAGE_SMS_DIAGNOSTIC_PRODUCTION_ENABLED` | Default `false`; bloquea diagnóstico en prod |

### 2.4 Correo (Mail)

Usado por **legacy** login/registro (`IssueAuthOtpAction`), **registro P0-A con fallback**, notificaciones — **no** por login P0-A SMS.

| Variable | Finalidad | En Forge |
|----------|-----------|----------|
| `MAIL_MAILER` | Driver (smtp, ses, etc.) | **Sí** |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | Transporte | **Sí** (secretos) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Remitente | **Sí** |
| `MAIL_SAFE_MODE` | Restringe destinatarios en no-prod (`.env.example`) | Recomendado staging |
| `MAIL_ALLOWED_RECIPIENTS` | Lista permitida si safe mode | Staging |
| `MAIL_ALLOWED_DOMAINS` | Dominios permitidos | Staging |
| `MAIL_BLOCK_DISALLOWED`, `MAIL_LOG_BLOCKED` | Bloqueo / log | Staging |

### 2.5 ATIX sandbox (servidor — validación HMAC entrante)

`config/atix.php` lee:

| Variable | Finalidad | En Forge | En Git |
|----------|-----------|----------|--------|
| `ATIX_SANDBOX_INTEGRATION_KEY_ID` | Key id aceptada en `X-Integration-Key-Id` | **Sí** | **Nunca** |
| `ATIX_SANDBOX_HMAC_SECRET` | Secreto HMAC del par sandbox | **Sí** | **Nunca** |
| `ATIX_SANDBOX_ALLOWED_CHANNELS` | Default `whatsapp` | Opcional | No secretos |
| `ATIX_SANDBOX_ALLOWED_AUDIENCES` | Default `leo` | Opcional | No secretos |
| `ATIX_SANDBOX_*` (timeouts, nonce TTL, rate limit) | Operación sandbox | Opcional | No secretos |

El **mismo** par key/secret debe usarse en la **terminal local** para Newman (ver §3).

### 2.6 Diagnóstico OTP (opcional staging)

| Variable | Finalidad |
|----------|-----------|
| `OTP_DIAGNOSTIC_RESPONSE_HEADERS_ENABLED` | `true` solo staging |
| `OTP_DIAGNOSTIC_RESPONSE_TOKEN` | Secreto ≥32 chars; header `X-OTP-Diagnostic-Token` |

Cabeceras: outcome/stage/reason — **sin OTP**. Solo `APP_ENV` `local` o `staging`.

### 2.7 Redis / cola

Entrega OTP P0-A usa `OTP_P0A_DELIVERY_REDIS_*` (reservas). Staging debe tener Redis accesible como en QA (`OTP_P0A_DELIVERY_REDIS_CONNECTION`, default `default`).

---

## 3. Terminal local — variables Newman

El script **`scripts/run-newman-rc1-staging.sh`** exige (proceso del shell):

| Variable local | Mapeo Postman / uso | Obligatoria | En Git |
|----------------|---------------------|-------------|--------|
| `BASE_URL` | `base_url` | **Sí** | No |
| `ATIX_SANDBOX_INTEGRATION_KEY_ID` | `atix_integration_key_id` | **Sí** | **Nunca** |
| `ATIX_SANDBOX_HMAC_SECRET` | `atix_hmac_secret` | **Sí** | **Nunca** |
| `RC1_STAGING_PHONE` | `phone` (E.164, ej. `+52…`) | **Sí** | **Nunca** |
| `RC1_STAGING_UNKNOWN_PHONE` | `unknown_phone` (E.164) | **Sí** | **Nunca** |
| `OTP_CODE` | `otp_code` (6 dígitos, **solo** request 9 verify válido) | Recomendada | **Nunca** |
| `BEARER_TOKEN` | `access_token` | **No** (la colección captura tras verify 200) | **Nunca** |

**Carga opcional sin export manual:** archivo gitignored `.newman-rc1-staging.env.local` en la raíz del repo (listado en `.gitignore`) o `~/.config/famedic/newman-rc1-staging.env` — el script hace `source` si existe.

**Plantilla Postman sin secretos:** `docs/Akubica/v1.3.0-rc1/Famedic-Akubica-v1.3.0-rc1-staging.postman_environment.json` (valores vacíos; el runner inyecta vía `--env-var`).

### 3.1 Dos fases (recomendado staging)

Cada ejecución genera **UUID nuevos** (`conversation_id`, `channel_event_id`, `nonce`, `correlation_id`, `Idempotency-Key` por request). No reutilizar `synthetic-conv-rc1-staging`.

| Fase | Script | Carpetas Newman | Parada |
|------|--------|-----------------|--------|
| **A** | `bash scripts/run-newman-rc1-staging-phase-a.sh` | `00 - RC1 Fase A (staging OTP)` | Tras `request-code`: OTP en monitor admin |
| **B** | `OTP_CODE=****** bash scripts/run-newman-rc1-staging-phase-b.sh` | `02 - RC1 Fase B (Bearer y carrito)` | Requiere estado `docs/Akubica/v1.3.0-rc1/.rc1-staging-run-state.local.json` |

Contratos ATIX (replay 409, firma inválida, etc.): carpeta `01` — incluida en `run-newman-rc1-staging.sh` sin Fase B si falta `OTP_CODE`.

---

## 4. OTP por correo — qué existe y qué no (RC1)

### 4.1 Colección RC1 (login contextual con teléfono)

Flujo Postman: lookup → `POST /auth/login/request-code` con **`phone`** + contexto → verify con `OTP_CODE`.

Con `OTP_P0A_AKUBICA_LOGIN_ENABLED=true`:

- Entrega: **`deliverLoginSafely` → solo SMS** (`allowEmailFallback: false`).
- **`OTP_P0A_EMAIL_FALLBACK_ENABLED` no aplica al login P0-A.**

**Conclusión:** para Newman RC1 el OTP **no** se obtiene por correo. Fuente operativa: **SMS al teléfono verificado** o pegar el código en `OTP_CODE` antes de la corrida (tras un `request-code` manual).

### 4.2 Fallback correo (solo otros flujos — no sustituyen RC1)

| Flujo | Condición | Notificación |
|-------|-----------|--------------|
| Registro P0-A | `OTP_P0A_EMAIL_FALLBACK_ENABLED=true` y reglas en `deliverRegisterSafely` | `AkubicaSecureRegisterOtpMailNotification` |
| Login **legacy** | `OTP_P0A_AKUBICA_LOGIN_ENABLED=false`, body con **email** | `IssueAuthOtpAction` + notificación legacy |

Esos flujos **no** coinciden con la colección RC1 (teléfono + contexto ATIX). No usar legacy solo para “evitar SMS” si staging tiene login P0-A ON.

### 4.3 Driver `fake`

`OTP_P0A_DELIVERY_DRIVER=fake` acepta envío en tests pero **no persiste ni expone** el código. **Inutilizable para Newman.**

---

## 5. Flujo de prueba paso a paso

### 5.1 Precondiciones Forge

1. `APP_ENV=staging`.
2. Login P0-A: `OTP_P0A_AKUBICA_LOGIN_ENABLED=true`, `OTP_P0A_ANTI_ABUSE_ENABLED=true`, `OTP_P0A_SMS_DELIVERY_ENABLED=true`, `OTP_P0A_DELIVERY_DRIVER=vonage`.
3. `VONAGE_KEY`, `VONAGE_SECRET`, `VONAGE_SMS_FROM` configurados.
4. `ATIX_SANDBOX_INTEGRATION_KEY_ID` + `ATIX_SANDBOX_HMAC_SECRET` en Forge (par sandbox).
5. `php artisan config:cache` en staging.

Verificación segura (SSH, sin imprimir secretos) — patrón en `docs/Akubica/p0-e1-qa-staging-production-readiness.md` §3:

```bash
php artisan tinker --execute="
echo 'env='.app()->environment().PHP_EOL;
echo 'login='.(config('otp.p0a.flags.akubica_login_enabled')?'1':'0').PHP_EOL;
echo 'sms='.(config('otp.p0a.flags.sms_delivery_enabled')?'1':'0').PHP_EOL;
echo 'driver='.(config('otp.p0a.delivery.driver')??'null').PHP_EOL;
"
```

### 5.2 Precondición teléfono elegible

Para `RC1_STAGING_PHONE`:

- Un solo `User` con ese E.164 (normalizado MX).
- `customer` asociado.
- `phone_verified_at` **no null** si `OTP_P0A_REQUIRE_VERIFIED_PHONE=true`.
- Usuario activo (según reglas de `AkubicaLoginOtpService::findEligibleUser`).

Si no: **decoy** — `request-code` puede responder **202** con `challenge_id` pero verify fallará; no hay OTP real.

### 5.3 Precondiciones locales

```bash
cd /ruta/al/repo/famedic
# exportar variables §3 o usar .newman-rc1-staging.env.local
```

Comprobar que `BASE_URL` contiene `staging` (el script **bloquea** `famedic.com.mx/api/v1` sin `staging`).

### 5.4 Secuencia manual (entender la colección)

1. **Phone lookup** — `POST /integrations/atix/phone-lookups` (HMAC local). Esperado **202** reconocido / unknown.
2. **Request-code** — contextual; esperado **202** + `challenge_id` real (no decoy).
3. **OTP** — leer SMS en el dispositivo del teléfono verificado (ventana TTL `OTP_P0A_TTL_MINUTES`, default 5).
4. **`export OTP_CODE=<6 dígitos>`** en la misma terminal (solo sesión).
5. **Verify-code** — Newman item 9; esperado **200**, Bearer en colección.
6. **Membresía, carrito, beneficios, checkout** — items 10–19; requieren `access_token` (skip `[fixture]` si falta OTP).
7. **Newman completo:**

```bash
bash scripts/run-newman-rc1-staging.sh
```

8. **Reportes:** revisar exit code + archivos §6.
9. **Evidencia RC1:** actualizar narrativa en `docs/Akubica/v1.3.0-rc1/NEWMAN-staging-v1.3.0-rc1.md` (tabla por caso, clasificación), opcional `MANIFEST-v1.3.0-rc1.md` / `SHA256SUMS` si cambian artefactos sanitizados — **sin pegar secretos ni OTP**.

---

## 6. Reportes sanitizados

Tras cada corrida, el script:

1. Escribe JSON/JUnit **raw** temporales.
2. Ejecuta `scripts/sanitize-newman-report.py` y `scripts/sanitize-newman-junit.py`.
3. **Elimina** raw.

| Archivo | Contenido |
|---------|-----------|
| `docs/Akubica/v1.3.0-rc1/newman-staging-report-sanitized.json` | Newman JSON redactado (keys token/otp/phone/signature/secret/hmac/bearer) |
| `docs/Akubica/v1.3.0-rc1/newman-staging-summary.json` | Resumen por caso HTTP / fallos |
| `docs/Akubica/v1.3.0-rc1/newman-staging-junit-sanitized.xml` | JUnit sanitizado |

**Conservación:** commitear solo estos sanitizados si política del equipo lo permite; **nunca** raw ni `.newman-rc1-staging.env.local`.

---

## 7. Verificar que no se usa producción

| Control | Cómo |
|---------|------|
| URL | `BASE_URL` debe ser host **staging**; runner exit **3** si parece prod. |
| ATIX | Usar credenciales **sandbox** (`ATIX_SANDBOX_*`), no claves de producción ATIX (si existieran aparte). |
| Vonage | Cuenta/credenciales **staging** en Forge; no compartir con prod. |
| Postman | Colección RC1: campos `atix_hmac_secret`, `otp_code`, `token` vacíos en repo; inyección solo `--env-var`. |
| OTP fake/null en prod | Runtime lanza `OTP_CONFIGURATION_INVALID` en prod si driver `null`/`fake` con entrega esperada. |

---

## 8. Reglas de seguridad (confirmación por código)

| Regla | Confirmación |
|-------|----------------|
| OTP no en respuesta HTTP API | Challenge guarda `code_hash`; verify no devuelve código; tests RC1 aíslan esto. |
| OTP no en Git | `.gitignore` → `.newman-rc1-staging.env.local`; sanitizador redacta `otp`. |
| HMAC no en colección | JSON Postman: secretos vacíos; runner mapea env de proceso. |
| No OTP para no elegibles | Decoy sin envío real; `findEligibleUser` + `require_verified_phone`. |
| No pagos / payment links / activaciones | Colección RC1: tests `sin payment link`; solo `GET checkout/prepare` médico. |
| Fallback correo limitado | Login P0-A: sin email; fallback mail solo registro P0-A bajo flag. |
| No `fake` para Newman | Fake no expone código. |

---

## 9. Diagnóstico

### 9.1 Teléfono decoy

Síntomas: `request-code` **202** pero verify **422** / OTP inválido; sin SMS.

Causas típicas (`OtpDiagnosticContext` reason): `user_not_found`, `phone_not_verified`, `ambiguous_match`, `customer_missing`.

Acciones:

- Admin → **Monitor movimientos OTP** (`otp-movements.monitor`): flujo `akubica_login`, stage decoy.
- Staging: cabeceras diagnóstico si están habilitadas (§2.6): `X-OTP-Diagnostic-Reason`.
- Corregir fixture usuario/teléfono verificado — **no** desactivar anti-enumeración.

### 9.2 Proveedor aceptó SMS pero no entregó

Síntomas: monitor muestra entrega aceptada / `provider_accepted`; SMS no llega.

Acciones:

- Vonage dashboard / logs `vonage_sms_otp_delivery`.
- Admin: test SMS diagnóstico (`VONAGE_SMS_DIAGNOSTIC_*`) — **no** es el OTP de login.
- Revisar DLR si `VONAGE_SMS_DLR_ENABLED`.

### 9.3 No llega correo

Para **RC1 login P0-A:** **esperado** — no hay fallback email.

Si se probó **registro** con fallback: revisar `MAIL_*`, `MAIL_SAFE_MODE`, allowlist, colas (`queue:work`), logs de mail bloqueado.

### 9.4 Newman bloqueado al inicio

| Mensaje | Causa |
|---------|--------|
| `BLOCKED: BASE_URL empty` | Falta export local |
| `BLOCKED: missing env: …` | Falta ATIX o teléfonos RC1 |
| `BLOCKED: BASE_URL looks like production` | URL sin `staging` |

---

## 10. Criterios PASS (corrida válida RC1)

- Exit code Newman **0** (o política acordada con items `[fixture]` documentados).
- **0 fallos de script** Postman.
- ATIX 1–4 y replay 5a/6b: HTTP esperados (202/401/409 según caso).
- Verify 9: **200** con OTP real (o item marcado `[fixture]` si se omite OTP a propósito).
- 10–19: **200** o skip fixture por OTP/token — no 401 en cadena por OTP ausente mal clasificado.
- Reportes sanitizados generados §6.
- Colección verificada: `Famedic Akubica API v1.3.0-rc1`.

---

## 11. Entrega a Leo (evidencia)

Incluir **sin secretos**:

- Fecha y commit/hash del repo probado.
- Confirmación `APP_ENV=staging` y flags OTP (booleanos, sin valores).
- `newman-staging-summary.json` (stats + casos fallidos).
- Referencia a `newman-staging-report-sanitized.json` / JUnit sanitizado.
- Tabla caso → HTTP → resultado → clasificación (colección / fixture / auth / runtime).
- Confirmación E.164 usado (**enmascarado**, ej. últimos 4 dígitos).
- Nota: OTP obtenido por **SMS** (login P0-A); fallback correo **no aplica** a RC1.
- Pendientes explícitos (cupón sandbox, DLR, etc.).

---

## 12. Referencias en repo

| Recurso | Ruta |
|---------|------|
| Runner | `scripts/run-newman-rc1-staging.sh` |
| Colección | `postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json` |
| Entorno plantilla staging | `docs/Akubica/v1.3.0-rc1/Famedic-Akubica-v1.3.0-rc1-staging.postman_environment.json` |
| OTP config | `config/otp.php` |
| Driver SMS | `app/Providers/AppServiceProvider.php` |
| Login P0-A | `app/Services/Otp/AkubicaLoginOtpService.php` |
| QA staging flags | `docs/Akubica/p0-e1-qa-staging-production-readiness.md` |
| Evidencia previa | `docs/Akubica/v1.3.0-rc1/NEWMAN-staging-v1.3.0-rc1.md` |
