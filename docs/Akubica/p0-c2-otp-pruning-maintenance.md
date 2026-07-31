# P0-C2 — OTP pruning / maintenance

**Estado:** implementado en código (flag OFF por defecto).  
**Comando:** `php artisan akubica:prune-otp`  
**No ejecuta limpieza en production desde este documento.**

## Objetivo

Eliminar filas OTP **terminales** fuera de retención, conservando evidencia razonable para auditoría. No cambia autenticación, TTL, step-up ni secure-link behavior.

## Tablas

| Tabla | Retención default | Timestamp de retención | Nunca elimina |
|-------|-------------------|------------------------|---------------|
| `otp_secure_download_links` | 30 d | `COALESCE(consumed_at, revoked_at, expires_at)` | links activos |
| `otp_step_up_grants` | 30 d | `COALESCE(revoked_at, expires_at)` | grants activos; grants con link activo; huérfano PAT **solo activo** |
| `otp_delivery_operations` | 30 d | `updated_at` | `pending` |
| `otp_challenges` | 30 d | `COALESCE(consumed_at, invalidated_at, expires_at)` | pending; con `akubica_registration_intents`; con grants restantes |
| `otp_rate_limits` | 7 d | `updated_at` (+ ventana cerrada) | ventana vigente / `blocked_until` futuro |
| `personal_access_tokens` | — | — | **fuera de alcance** → Sanctum |
| `akubica_registration_intents` | — | — | **no hard-delete en P0-C2** |

Env:

```
OTP_P0A_CLEANUP_ENABLED=false
OTP_P0A_CLEANUP_CHALLENGES_RETENTION_DAYS=30
OTP_P0A_CLEANUP_DELIVERIES_RETENTION_DAYS=30
OTP_P0A_CLEANUP_RATE_LIMITS_RETENTION_DAYS=7
OTP_P0A_CLEANUP_GRANTS_RETENTION_DAYS=30
OTP_P0A_CLEANUP_SECURE_LINKS_RETENTION_DAYS=30
OTP_P0A_CLEANUP_DEFAULT_BATCH=1000
OTP_P0A_CLEANUP_SCHEDULE_TIME=03:00
```

Config: `config/otp.php` → `otp.p0a.cleanup.*`

## Delivery statuses terminales

`suppressed`, `sms_accepted`, `sms_temporary_failed`, `sms_permanent_failed`, `email_accepted`, `email_failed`.

## Orden de eliminación

1. secure links  
2. grants  
3. deliveries  
4. challenges  
5. rate limits  

Dry-run `type=all` simula el mismo orden (challenges ignoran grants que serían borrados).

## Comando

```bash
php artisan akubica:prune-otp
php artisan akubica:prune-otp --dry-run
php artisan akubica:prune-otp --force --batch=1000
php artisan akubica:prune-otp --force --type=secure-links
```

- Sin `--force` ⇒ dry-run (no borra).
- `--force` + `--dry-run` juntos ⇒ exit 1.
- `--type` inválido o `--batch` fuera de 1..10000 ⇒ exit 1.
- Errores de entidad ⇒ exit 1.
- No imprime OTP, teléfonos, emails, hashes ni tokens.

Servicio: `App\Services\Otp\AkubicaOtpPruningService`  
Resultado: `AkubicaOtpPruningResult` (conteos + skipped).

### Ejemplo dry-run (sanitizado)

```
akubica:prune-otp [DRY-RUN] type=all batch=1000
+------------------------+--------+
| Categoria              | Conteo |
+------------------------+--------+
| secure_download_links  | 12     |
| step_up_grants         | 8      |
| delivery_operations    | 40     |
| challenges             | 35     |
| rate_limits            | 3      |
+------------------------+--------+
Omitidos: challenges_registration_intent=2 challenges_remaining_grants=0 grants_active_secure_links=1
Duracion: 42 ms (bucket 0-100)
```

## Scheduler

En `routes/console.php`, **solo si** `otp.p0a.cleanup.enabled=true`:

```php
Schedule::command("akubica:prune-otp --force --batch={$batch}")
    ->dailyAt($scheduleTime) // default 03:00
    ->withoutOverlapping(120)
    ->name('akubica-prune-otp');
```

- **No** `onOneServer` (cache default puede no ser lock store compartido confirmado).
- Si Forge ya programa el comando, dejar el flag OFF en app **o** quitar la entrada Forge — no duplicar.
- Comando recomendado para Forge (si se gestiona fuera):  
  `php artisan akubica:prune-otp --force --batch=1000` diario 03:00.

## Sanctum

No eliminar PAT desde el servicio Akubica.

```bash
php artisan sanctum:prune-expired --hours=24
```

Disponible en Sanctum 4.2. **No programado** en este bloque. Cuidado: también considera `sanctum.expiration` global (1440).

## Métricas / logs

Un resumen por ejecución:

`akubica_otp_prune_completed` — `dry_run`, `batch`, `type`, `duration_bucket`, conteos, skipped agregados, `environment`.

Error: `akubica_otp_prune_failed` — `entity`, `exception_class`, mensaje sanitizado. Sin IDs sensibles.

## EXPLAIN propuesto (deliveries) — staging

Índice compuesto **no** añadido en P0-C2. Evaluar en staging:

```sql
EXPLAIN
SELECT id
FROM otp_delivery_operations
WHERE status IN (
  'suppressed','sms_accepted','sms_temporary_failed',
  'sms_permanent_failed','email_accepted','email_failed'
)
AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY id
LIMIT 1000;
```

Si aparece full scan costoso a volumen, proponer migración separada:

```sql
CREATE INDEX otp_delivery_operations_status_updated_index
  ON otp_delivery_operations (status, updated_at);
```

## Rollout staging

1. Deploy con `OTP_P0A_CLEANUP_ENABLED=false`.  
2. `php artisan akubica:prune-otp --dry-run`  
3. Revisar conteos / skipped.  
4. Activar flag solo si el schedule de app debe correr (o configurar Forge sin duplicar).  
5. `--force --batch=100` manual.  
6. Verificar tablas + log resumen.  
7. Repetir (idempotente).  
8. Schedule diario.  
9. Monitorear 1 semana.  
10. Rollback: flag OFF + retirar schedule.

## Rollback

- `OTP_P0A_CLEANUP_ENABLED=false` + `config:cache`.  
- Retirar job Forge si aplica.  
- No hay undelete: recuperación solo desde backup DB.

## Riesgos

- Borrado irreversible de evidencia OTP tras retención.  
- Challenges con intent se omiten indefinidamente hasta lifecycle aparte de intents.  
- CASCADE challenge→grants→links mitigado omitiendo challenges con grants restantes.  
- Doble schedule app+Forge.  
- `sanctum:prune-expired` puede afectar PAT no-Akubica.

## Recuperación ante borrado accidental

Restaurar desde backup/snapshot de BD en la ventana de retención operativa. No hay soft-delete.

## Tests

`tests/Feature/Api/V1/AkubicaOtpPruningP0c2Test.php`  
`tests/Unit/Otp/OtpP0aConfigTest.php` (claves cleanup)

## Nota versionado

Desde P0-D1, este archivo entra en la excepción de `.gitignore` (`!/docs/Akubica/p0-*.md`). No usar `git add -f`.
