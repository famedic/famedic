# P0-A — Login OTP por SMS (usuarios existentes)

## Objetivo

Permitir que un usuario ya registrado inicie sesión con OTP enviado por SMS (Vonage), reutilizando la infraestructura P0-A (`otp_challenges`, anti-abuso, delivery).

Registro y login permanecen separados: login **nunca** crea User/Customer.

## Feature flags

| Variable | Rol |
|----------|-----|
| `OTP_P0A_AKUBICA_LOGIN_ENABLED` | Activa path P0-A de login |
| `OTP_P0A_ANTI_ABUSE_ENABLED` | Obligatorio si login P0-A está ON |
| `OTP_P0A_SMS_DELIVERY_ENABLED` | Obligatorio para enviar SMS de login |
| `OTP_P0A_EMAIL_FALLBACK_ENABLED` | Solo aplica a **registro**; login **no** hace fallback email |
| `OTP_P0A_DELIVERY_DRIVER` | `vonage` en staging/prod; `fake` en tests |

Si `OTP_P0A_AKUBICA_LOGIN_ENABLED=false`:

- `POST /auth/login/request-code` y `verify-code` usan el flujo **legacy** (email + `otp_codes` + notificación email).
- `POST /auth/login/resend-code` → `503 FEATURE_DISABLED`.

## Contrato P0-A (flags ON)

### Solicitar OTP

`POST /api/v1/auth/login/request-code`

```json
{ "phone": "+520000000000" }
```

Respuesta `202`:

- `challenge_id`, `purpose=akubica_login`, `channel=sms`, `destination_masked`, `expires_at`, `resend_available_at`
- Sin OTP ni token

Teléfono inexistente / no elegible → misma forma `202` (señuelo; sin SMS).

### Verificar

`POST /api/v1/auth/login/verify-code`

```json
{ "challenge_id": "<uuid>", "code": "<OTP_FROM_SMS>" }
```

Éxito `200`: token Sanctum + `user` (id, email, name). No acepta `user_id`/`customer_id` del cliente.

### Reenviar

`POST /api/v1/auth/login/resend-code`

```json
{ "challenge_id": "<uuid>" }
```

Respeta cooldown / max attempts de la política P0-A. Solo SMS. Señuelos no envían SMS.

## Errores controlados

| Situación | HTTP | Código |
|-----------|------|--------|
| Fallo Vonage / delivery | 503 | `DELIVERY_FAILED` |
| Flags incompletos | 503 | `OTP_CONFIGURATION_INVALID` |
| OTP incorrecto | 422 | `INVALID_CODE` |
| Consumido | 422 | `CODE_ALREADY_USED` |
| Cooldown | 429 | `OTP_COOLDOWN` |

## Seguridad

- OTP solo como hash (`code_hash`)
- Propósito exclusivo `akubica_login` (cross-purpose rechazado)
- Anti-enumeración por teléfono
- Teléfono enmascarado en responses
- Sin PII/OTP/secretos en logs de aplicación del flujo P0-A
- Sin token si el SMS no se envió

## Despliegue

```bash
php artisan optimize:clear
php artisan config:cache
# si aplica: php artisan queue:restart
```

No activar en production sin checklist de Vonage + flags + monitoreo.
