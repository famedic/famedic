# API V1 — Feature flags y configuración P0

Fuente: `config/otp.php`, `config/akubica.php`, `.env.example`.  
**Todos los flags de comportamiento P0 default = OFF** salvo retenciones numéricas.

## Tabla de flags y variables

| Variable | Default | Efecto | Dependencia | Staging recomendado | Production recomendado | Rollback |
|----------|---------|--------|-------------|---------------------|------------------------|----------|
| `OTP_P0A_DELIVERY_DRIVER` | `null` | Driver SMS (`null`/`fake`/`vonage`) | Redis + `VONAGE_*` si `vonage` | `vonage` | `vonage` | `null` |
| `OTP_P0A_SMS_DELIVERY_ENABLED` | `false` | Permite envío SMS P0-A | Driver válido | `true` | `true` solo con Vonage OK | `false` |
| `OTP_P0A_INFRASTRUCTURE_ENABLED` | `false` | Infra OTP challenges | — | `true` | `true` si flujos P0-A ON | `false` |
| `OTP_P0A_ANTI_ABUSE_ENABLED` | `false` | Cooldown/intentos/bloqueo | Infra | `true` | `true` si login/register/step-up ON | `false` |
| `OTP_P0A_AKUBICA_REGISTER_ENABLED` | `false` | Registro P0-A SMS | Infra + anti-abuse | `true` | checklist | `false` → legacy email |
| `OTP_P0A_AKUBICA_LOGIN_ENABLED` | `false` | Login P0-A SMS | Anti-abuse (+ SMS para delivery) | `true` | checklist | `false` → legacy email |
| `OTP_P0A_EMAIL_FALLBACK_ENABLED` | `false` | Fallback email en **registro** | SMS delivery | opcional | checklist | `false` |
| `OTP_P0A_STEP_UP_RESULTS_ENABLED` | `false` | Step-up resultados | Anti-abuse + SMS | `true` | checklist | `false` → 503 |
| `OTP_P0A_STEP_UP_INVOICES_ENABLED` | `false` | Step-up facturas | Anti-abuse + SMS | `true` | checklist | `false` → 503 |
| `OTP_P0A_SECURE_LINKS_RESULTS_ENABLED` | `false` | Emisión/consumo links resultados | Step-up operativo | `true` | checklist | `false` → 503 |
| `OTP_P0A_SECURE_LINKS_INVOICES_ENABLED` | `false` | Emisión/consumo links facturas | Step-up operativo | `true` | checklist | `false` → 503 |
| `OTP_P0A_STEP_UP_BEARER_RESULTS_ENABLED` | `false` | Exige grant en download resultados | Grant emitido | gradual | gradual | `false` |
| `OTP_P0A_STEP_UP_BEARER_INVOICES_ENABLED` | `false` | Exige grant en download facturas | Grant emitido | gradual | gradual | `false` |
| `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED` | `false` | Master: results **e** invoices | Grant emitido | opcional | opcional | `false` |
| `OTP_P0A_SANCTUM_3H_ENABLED` | `false` | Persiste `expires_at` PAT Akubica | — | `true` | checklist | `false` (nuevos tokens) |
| `OTP_P0A_SANCTUM_TOKEN_TTL_MINUTES` | `180` | TTL minutos cuando 3h ON | Flag 3h | `180` | `180` | N/A (numérico) |
| `OTP_P0A_SANCTUM_TARGET_EXPIRATION_MINUTES` | `180` | Fallback histórico del TTL | Flag 3h | legacy alias | legacy alias | N/A |
| `OTP_P0A_CLEANUP_ENABLED` | `false` | Schedule `akubica:prune-otp` | Ops | dry-run → `true` | checklist | `false` + quitar schedule |
| `OTP_P0A_CLEANUP_CHALLENGES_RETENTION_DAYS` | `30` | Retención challenges terminales | Cleanup | `30` | `30` | subir días |
| `OTP_P0A_CLEANUP_DELIVERIES_RETENTION_DAYS` | `30` | Retención deliveries terminales | Cleanup | `30` | `30` | subir días |
| `OTP_P0A_CLEANUP_RATE_LIMITS_RETENTION_DAYS` | `7` | Retención rate limits | Cleanup | `7` | `7` | subir días |
| `OTP_P0A_CLEANUP_GRANTS_RETENTION_DAYS` | `30` | Retención grants terminales | Cleanup | `30` | `30` | subir días |
| `OTP_P0A_CLEANUP_SECURE_LINKS_RETENTION_DAYS` | `30` | Retención links terminales | Cleanup | `30` | `30` | subir días |
| `OTP_P0A_CLEANUP_DEFAULT_BATCH` | `1000` | Batch delete | Cleanup | `100`–`1000` | `1000` | bajar batch |
| `OTP_P0A_CLEANUP_SCHEDULE_TIME` | `03:00` | Hora diaria schedule | Cleanup ON | `03:00` | ops | flag OFF |
| `OTP_P0A_SECURE_LINK_TTL_MINUTES` | `60` | TTL link (código) | Secure links | **`5`** | checklist | subir TTL |
| `OTP_P0A_SECURE_LINK_MAX_OPENS` | `5` | Máx. aperturas | Secure links | **`1`** | checklist | subir |
| `OTP_P0A_STEP_UP_GRANT_TTL_MINUTES` | `10` | TTL grant | Step-up | `10` | `10` | subir |
| `AKUBICA_TOKEN_TTL_MINUTES` | `1440` | TTL anunciado legacy (flag 3h OFF) | — | default | default | N/A |
| `API_V1_IDEMPOTENCY_ENABLED` | `false` | Idempotency-Key en rutas write seleccionadas | — | checklist | checklist | `false` |
| `API_V1_AUDIT_ENABLED` | `false` | Persistencia auditoría append-only API v1 | Migración `api_v1_audit_events` | checklist | checklist | `false` |
| `API_V1_AUDIT_MAX_METADATA_BYTES` | `2048` | Tope JSON metadata auditoría | Flag audit | `2048` | `2048` | N/A |
| `API_V1_AUDIT_MAX_METADATA_DEPTH` | `2` | Profundidad máxima metadata | Flag audit | `2` | `2` | N/A |

## Variable inválida / obsoleta

| Variable | Estado |
|----------|--------|
| `OTP_P0A_SMS_DELIVERY_PROVIDER` | **Inválida.** Ignorada por application code. Usar `OTP_P0A_DELIVERY_DRIVER=vonage`. |

## Vonage (no es flag; secretos fuera de git)

```
VONAGE_KEY=
VONAGE_SECRET=
VONAGE_SMS_FROM=
```

Nunca documentar valores reales. Staging/prod: set vía secrets del entorno.

## Enforcement efectivo

- Results Bearer ON = `STEP_UP_BEARER_RESULTS` **OR** `STEP_UP_BEARER_DOWNLOADS`
- Invoices Bearer ON = `STEP_UP_BEARER_INVOICES` **OR** `STEP_UP_BEARER_DOWNLOADS`

## Pruning

No es endpoint HTTP. Ver [`p0-c2-otp-pruning-maintenance.md`](./p0-c2-otp-pruning-maintenance.md). Comando: `php artisan akubica:prune-otp`.
