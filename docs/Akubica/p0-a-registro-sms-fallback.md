# P0-A — Registro Akubica: SMS + fallback email

Documento operativo para activar el registro OTP P0-A con entrega SMS (Vonage)
y fallback controlado por correo. **No activa flags en ningún ambiente.**

## Matriz de flags

| Variable | Efecto |
|----------|--------|
| `OTP_P0A_AKUBICA_REGISTER_ENABLED` | Candidato a registro P0-A |
| `OTP_P0A_INFRASTRUCTURE_ENABLED` | Requerido. Si está `false`, **se ignora** REGISTER → legacy email |
| `OTP_P0A_ANTI_ABUSE_ENABLED` | Requerido por `assertConfigurationReady` (ya cableado) |
| `OTP_P0A_SMS_DELIVERY_ENABLED` | Activa entrega SMS (`registration.delivery_enabled`) |
| `OTP_P0A_EMAIL_FALLBACK_ENABLED` | Permite fallback mail tras fallo elegible |
| `OTP_P0A_DELIVERY_DRIVER` | `vonage` en staging/prod; `null`/`fake` **prohibidos en production** |
| `OTP_P0A_AKUBICA_LOGIN_ENABLED` | Independiente del registro; ver `p0-a-login-sms.md` |
| Sanctum 3h / step-up | Ver P0-C1 / P0-B* |

### Combinaciones

| REGISTER | INFRA | ANTI_ABUSE | SMS | Resultado |
|----------|-------|------------|-----|-----------|
| false | * | * | * | Legacy email (`otp_codes`) |
| true | false | * | * | Legacy (infra ignora register) |
| true | true | false | * | `503 OTP_CONFIGURATION_INVALID` |
| true | true | true | false | Challenge+intent; **sin** SMS/email |
| true | true | true | true | SMS (o fallback si aplica) |

## Variables obligatorias del proveedor SMS

```
OTP_P0A_SMS_DELIVERY_ENABLED=true
OTP_P0A_DELIVERY_DRIVER=vonage
VONAGE_KEY=          # secreto de entorno — no en git
VONAGE_SECRET=       # secreto de entorno — no en git
VONAGE_SMS_FROM=     # configurar en entorno
REDIS_*              # reservas de delivery
MAIL_*               # solo si EMAIL_FALLBACK=true
```

**Obsoleto:** no usar `OTP_P0A_SMS_DELIVERY_PROVIDER` (ignorada por el código).

## Estados de delivery (`otp_delivery_operations.status`)

| Estado | Significado |
|--------|-------------|
| `pending` | Operación creada |
| `sms_accepted` | SMS aceptado por proveedor |
| `sms_temporary_failed` | Fallo temporal (timeout/transport/429/5xx) |
| `sms_permanent_failed` | Fallo permanente / misconfigured |
| `email_accepted` | Fallback mail OK (`fallback_used=true`) |
| `email_failed` | Fallback mail falló |
| `suppressed` | Sin canal / challenge obsoleto |

Si delivery está ON y el outcome final es fallo (sin SMS ni email exitoso):
respuesta `503 DELIVERY_FAILED`, challenge+intent invalidados (`delivery_failed`).
No se deja un registro “pendiente” engañoso.

## Condiciones de fallback email

Solo si `OTP_P0A_EMAIL_FALLBACK_ENABLED=true` **y**:

1. teléfono E.164 vacío, **o**
2. fallo SMS **temporal** (`isFallbackEligible`: timeout, transport, rate limit, 5xx)

**No** hay fallback en: SMS aceptado, fallo permanente, provider misconfigured,
driver `null` suppressed.

No se envía SMS y correo a la vez si el SMS fue exitoso.

`OTP_P0A_PRIMARY_CHANNEL` / `OTP_P0A_FALLBACK_MODE` existen en config pero
**no gobiernan** el orchestrator actual (canal primario hardcodeado SMS).

## Activación en staging

1. Flags OFF; verificar legacy.
2. Redis + Vonage staging + mail real.
3. Encender: `INFRASTRUCTURE`, `ANTI_ABUSE`, `REGISTER`, `SMS_DELIVERY`,
   `DELIVERY_DRIVER=vonage`, opcional `EMAIL_FALLBACK`.
4. `php artisan config:cache` (Forge).
5. Probar register → SMS; forzar timeout → mail si fallback ON;
   fallo permanente → `DELIVERY_FAILED`.
6. Login P0-A es independiente (ver `p0-a-login-sms.md`).

## Rollback

```
OTP_P0A_AKUBICA_REGISTER_ENABLED=false
OTP_P0A_SMS_DELIVERY_ENABLED=false
OTP_P0A_EMAIL_FALLBACK_ENABLED=false
OTP_P0A_DELIVERY_DRIVER=null
# opcional apagar INFRASTRUCTURE / ANTI_ABUSE
```

Vuelve el registro legacy por correo. Sin deploy de código.

## Consultas de verificación (sin PII/secretos)

```sql
SELECT status, result_class, provider_alias, fallback_used, COUNT(*) AS n
FROM otp_delivery_operations
WHERE created_at > NOW() - INTERVAL 1 DAY
GROUP BY 1, 2, 3, 4;

SELECT status, invalidation_reason, COUNT(*) AS n
FROM akubica_registration_intents
WHERE updated_at > NOW() - INTERVAL 1 DAY
GROUP BY 1, 2;
```

```bash
php artisan tinker --execute="
echo 'register='.json_encode(config('otp.p0a.flags.akubica_register_enabled')).PHP_EOL;
echo 'infra='.json_encode(config('otp.p0a.flags.infrastructure_enabled')).PHP_EOL;
echo 'sms='.json_encode(config('otp.p0a.flags.sms_delivery_enabled')).PHP_EOL;
echo 'fallback='.json_encode(config('otp.p0a.flags.email_fallback_enabled')).PHP_EOL;
echo 'driver='.config('otp.p0a.delivery.driver').PHP_EOL;
echo 'vonage_key_set='.(trim((string)config('vonage.api_key'))!==''?'yes':'no').PHP_EOL;
"
```
