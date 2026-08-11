# P0-A1 — Configuración operativa (feature flags OTP / Sanctum / step-up)

**Sprint:** FAMEDIC–LeoV / Akubica · bloque P0-A1  
**Fecha:** 2026-07-22  
**Estado:** configuración lista; **comportamiento de producción sin cambios**

Referencia de análisis: `docs/Akubica/analisis-sprint-p0-a-otp-sms.md`

---

## 1. Qué se hizo

- Política P0-A y feature flags centralizados en `config/otp.php` → `otp.p0a.*`
- Metadatos de target Sanctum 3 h documentados en `config/akubica.php` (`token_ttl_minutes_p0a_target`)
- Variables documentadas en `.env.example` (sin secretos)
- **No** se duplicó Vonage en `config/services.php` (el paquete usa `config('vonage.*')`)
- **No** se modificó el valor efectivo de `config/sanctum.php` → sigue default **1440**

---

## 2. Tabla de variables

### Vonage (consumidas realmente)

| Variable | Uso | Default ejemplo |
|----------|-----|-----------------|
| `VONAGE_KEY` | `config('vonage.api_key')` | vacío |
| `VONAGE_SECRET` | `config('vonage.api_secret')` | vacío |
| `VONAGE_SMS_FROM` | `config('vonage.sms_from')` | vacío |

Otras vars del paquete (`VONAGE_APPLICATION_ID`, etc.) **no** se documentan: FAMEDIC no las consume en app.

### Sanctum / Akubica actuales

| Variable | Default efectivo | Notas |
|----------|------------------|-------|
| `SANCTUM_TOKEN_EXPIRATION` | `1440` | **No cambiar en P0-A1** |
| `AKUBICA_OTP_TTL_MINUTES` | `10` | Auth Akubica actual |
| `AKUBICA_OTP_LENGTH` | `6` | |
| `AKUBICA_OTP_MAX_ATTEMPTS` | `5` | |
| `AKUBICA_TOKEN_TTL_MINUTES` | `1440` | Respuesta API |
| `AKUBICA_TOKEN_TTL_MINUTES_P0A_TARGET` | `180` | Solo documentado |
| `AKUBICA_PAYMENT_LINK_DEFAULT_EXPIRES_MINUTES` | `60` | |

### Flags P0-A (todos `false` por defecto)

| Variable | Flag config |
|----------|-------------|
| `OTP_P0A_INFRASTRUCTURE_ENABLED` | `otp.p0a.flags.infrastructure_enabled` |
| `OTP_P0A_SMS_DELIVERY_ENABLED` | `…sms_delivery_enabled` |
| `OTP_P0A_EMAIL_FALLBACK_ENABLED` | `…email_fallback_enabled` |
| `OTP_P0A_ANTI_ABUSE_ENABLED` | `…anti_abuse_enabled` |
| `OTP_P0A_SANCTUM_3H_ENABLED` | `…sanctum_3h_enabled` |
| `OTP_P0A_STEP_UP_RESULTS_ENABLED` | `…step_up_results_enabled` |
| `OTP_P0A_STEP_UP_INVOICES_ENABLED` | `…step_up_invoices_enabled` |
| `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED` | `…step_up_bearer_downloads_enabled` |

### Política objetivo (inactiva hasta flags)

| Variable | Objetivo |
|----------|----------|
| `OTP_P0A_TTL_MINUTES` | 5 |
| `OTP_P0A_LENGTH` | 6 |
| `OTP_P0A_MAX_ATTEMPTS` | 5 |
| `OTP_P0A_COOLDOWN_SECONDS` | 60 |
| `OTP_P0A_RESEND_WINDOW_MINUTES` | 30 |
| `OTP_P0A_MAX_RESENDS` | 3 (adicionales; inicial no cuenta) |
| `OTP_P0A_BLOCK_MINUTES` | 30 |
| `OTP_P0A_REQUIRE_VERIFIED_PHONE` | true |
| `OTP_P0A_PRIMARY_CHANNEL` | `sms` |
| `OTP_P0A_FALLBACK_MODE` | `on_sms_failure` (`never` \| `on_sms_failure` \| `user_authorized`) |
| `OTP_P0A_AUDIT_ENABLED` | true |
| `OTP_P0A_STEP_UP_GRANT_TTL_MINUTES` | 10 |
| `OTP_P0A_SANCTUM_TARGET_EXPIRATION_MINUTES` | 180 |
| `OTP_P0A_SECURE_LINK_TTL_MINUTES` | 60 |
| `OTP_P0A_SECURE_LINK_MAX_OPENS` | 5 |

Valores inválidos de canal/fallback/números se **normalizan** a defaults seguros en `config/otp.php` (sin servicio aún).

---

## 3. Defaults efectivos vs objetivo

| Área | Efectivo hoy (sin flags) | Objetivo P0-A |
|------|--------------------------|---------------|
| Canal Akubica OTP | email | SMS (+ fallback controlado) |
| TTL OTP Akubica | 10 min (`akubica.otp_ttl_minutes`) | 5 min (`otp.p0a.policy`) |
| TTL OTP lab (`otp.expiry`) | 10 min | sin cambio en P0-A1 |
| Antiabuso completo | no | sí (flag) |
| Sanctum | 1440 min | 180 min (flag) |
| Step-up | off | on (flags) |

---

## 4. Flags apagados

Todos los de `otp.p0a.flags.*` están **desactivados**. Ningún controller/action los lee todavía (P0-A1 solo config).

---

## 5. Dependencias entre flags

```
infrastructure_enabled
  ├─ sms_delivery_enabled
  │    └─ email_fallback_enabled
  ├─ anti_abuse_enabled
  ├─ step_up_results_enabled ──┐
  ├─ step_up_invoices_enabled ─┼─► step_up_bearer_downloads_enabled
  └─ sanctum_3h_enabled (independiente, coordinar con LeoV)
```

Recomendación: no activar SMS/antiabuso/step-up sin `infrastructure_enabled`.

---

## 6. Orden previsto de activación

1. P0-A2 servicio OTP + `infrastructure_enabled` (staging)
2. P0-A3 `sms_delivery_enabled` → `email_fallback_enabled`
3. P0-A4 `anti_abuse_enabled`
4. P0-A5 contrato API
5. P0-A6 `sanctum_3h_enabled` (+ alinear `SANCTUM_TOKEN_EXPIRATION` / `AKUBICA_TOKEN_TTL_MINUTES`)
6. P0-A7 step-up results/invoices → bearer downloads
7. P0-A8 docs OpenAPI/Postman

---

## 7. Secretos

- **Nunca** committear `VONAGE_KEY` / `VONAGE_SECRET` ni OTP reales.
- `.env.example` solo plantillas comentadas.
- Logs futuros: sin plaintext OTP (política DEC-004).

---

## 8. Checklist staging

- [ ] `VONAGE_KEY`, `VONAGE_SECRET`, `VONAGE_SMS_FROM` presentes y válidos
- [ ] Saldo / capacidad Vonage
- [ ] TrustedProxies / IP real detrás de load balancer
- [ ] Flags siguen en `false` hasta el bloque correspondiente
- [ ] `SANCTUM_TOKEN_EXPIRATION` sigue en `1440` hasta P0-A6
- [ ] Smoke auth Akubica (email OTP) sin regresión

**Pendiente de validar en ambiente:** Vonage, TrustedProxies, saldo proveedor.

---

## 9. Rollback

Apagar el flag correspondiente en `.env` y `php artisan config:clear` (si hay config cache):

| Problema | Acción |
|----------|--------|
| SMS fallando | `OTP_P0A_SMS_DELIVERY_ENABLED=false` |
| Antiabuso agresivo | `OTP_P0A_ANTI_ABUSE_ENABLED=false` |
| Sesiones cortadas 3 h | `OTP_P0A_SANCTUM_3H_ENABLED=false` + restaurar `SANCTUM_TOKEN_EXPIRATION=1440` |
| Step-up rompe LeoV | flags step-up = `false` |

En P0-A1 no hay rollback funcional: no se activó nada.
