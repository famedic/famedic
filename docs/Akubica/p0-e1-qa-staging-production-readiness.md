# P0-E1 — QA integral staging y preparación de salida a producción

**Rama:** `feature/apis-akubica`  
**HEAD al arrancar P0-E1:** `626ed7b`  
**Working tree al arrancar:** limpio (P0-C2 `d2faa84` + P0-D1 `626ed7b` versionados)  
**Cambio mínimo de higiene en esta revisión:** `phpunit.xml` fuerza `OTP_P0A_CLEANUP_ENABLED=false` (aislamiento de tests; no cambia runtime staging/prod).  
**Doc de matrices:** este archivo (untracked hasta autorización de commit).

## Resultados de ejecución automatizada (P0-E1)

| Suite | Run | Passed | Failed | Assertions | Duración |
|-------|-----|--------|--------|------------|----------|
| `tests/Feature/Api/V1` | 1 | **546** | 0 | 2226 | 545.78s |
| `tests/Feature/Api/V1` | 2 | **546** | 0 | 2226 | 490.69s |
| `tests/Unit/Otp` | 1 | **59** | 0 | 253 | 27.78s |
| `tools/p0d1_validate_docs.py` | — | OK | 0 errors | — | — |
| `git diff --check` | — | limpio | — | — | — |

Antes del fix `phpunit.xml`: 545/546 (falso positivo por `.env` local con cleanup ON al boot del schedule). Con `OTP_P0A_CLEANUP_ENABLED=false` en staging/prod el schedule **no** se registra (verificado vía CLI).

## Veredicto

**LISTO PARA PRODUCCIÓN CON ROLLOUT GRADUAL**

Pendiente operativo (no bloquea código): completar matrices §4–8 en staging con SMS real (Postman 01/02/18/19) y dry-run prune; rellenar valores efectivos §3.


## 1. Estado del repositorio

Completar en ejecución:

| Campo | Valor |
|-------|-------|
| Branch | `feature/apis-akubica` |
| HEAD | `626ed7b` (confirmado al arrancar P0-E1) |
| Working tree | limpio |
| `git diff --check` | limpio |

### Versionado confirmado

| Bloque | Commit | Evidencia |
|--------|--------|-----------|
| P0-C2 pruning | `d2faa84` | `feat(api): add OTP pruning maintenance` + `p0-c2-*.md` |
| P0-D1 docs/OpenAPI/Postman | `626ed7b` | `docs(api): align Akubica OpenAPI and Postman contracts` |
| OpenAPI | v1.2.0 | `docs/Akubica/akubica-openapi.yaml` |
| Postman guard | en colección + Production env | `allow_production_writes` + PRODUCTION GUARD |

---

## 2. Inventario de flags (staging / QA / prod inicial)

**Valores efectivos en staging:** rellenar con la sección 3 (tinker seguro). No inventar.

| Variable | Default código | QA staging recomendado | Prod inicial recomendado | Dependencia | Impacto al apagar | Rollback |
|----------|----------------|------------------------|--------------------------|-------------|-------------------|----------|
| `OTP_P0A_INFRASTRUCTURE_ENABLED` | false | true | true (si P0-A ON) | — | Register P0-A cae a legacy | `false` |
| `OTP_P0A_ANTI_ABUSE_ENABLED` | false | true | true | Infra | `OTP_CONFIGURATION_INVALID` si login/register/step-up ON | `false` |
| `OTP_P0A_SMS_DELIVERY_ENABLED` | false | true | true tras Vonage OK | Driver | Sin SMS; delivery suppressed / failed | `false` |
| `OTP_P0A_DELIVERY_DRIVER` | null | `vonage` | `vonage` | Redis + `VONAGE_*` | Sin envío real | `null` |
| `OTP_P0A_AKUBICA_REGISTER_ENABLED` | false | true | Fase 2 | Infra+anti-abuse | Legacy email register | `false` |
| `OTP_P0A_AKUBICA_LOGIN_ENABLED` | false | true | Fase 2 | Anti-abuse (+SMS) | Legacy email login; resend 503 | `false` |
| `OTP_P0A_STEP_UP_RESULTS_ENABLED` | false | true | Fase 3 | Anti-abuse+SMS | Step-up results 503 | `false` |
| `OTP_P0A_STEP_UP_INVOICES_ENABLED` | false | true | Fase 4 | Anti-abuse+SMS | Step-up invoices 503 | `false` |
| `OTP_P0A_SECURE_LINKS_RESULTS_ENABLED` | false | true | Fase 3 | Step-up operativo | Secure links results 503 | `false` |
| `OTP_P0A_SECURE_LINKS_INVOICES_ENABLED` | false | true | Fase 4 | Step-up operativo | Secure links invoices 503 | `false` |
| `OTP_P0A_STEP_UP_BEARER_RESULTS_ENABLED` | false | true (QA enforcement) | Fase 5 | Grant | Bearer results sin grant OK | `false` |
| `OTP_P0A_STEP_UP_BEARER_INVOICES_ENABLED` | false | true (QA enforcement) | Fase 6 | Grant | Bearer invoices sin grant OK | `false` |
| `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED` | false | **false** (preferir flags específicos) | **false** inicial | Grant | Master OFF | `false` |
| `OTP_P0A_STEP_UP_BIND_SANCTUM_TOKEN` | true | true | true | — | Grants no ligados a PAT | mantener true |
| `OTP_P0A_STEP_UP_GRANT_TTL_MINUTES` | 10 | 10 | 10 | — | TTL distinto | subir/bajar número |
| `OTP_P0A_SECURE_LINK_TTL_MINUTES` | 60 | **5** | checklist (5–15) | Secure links | Links más largos | subir |
| `OTP_P0A_SECURE_LINK_MAX_OPENS` | 5 | **1** | checklist (1) | Secure links | Multi-open | subir |
| `OTP_P0A_SANCTUM_3H_ENABLED` | false | true (QA) | Fase 7 | — | Nuevos tokens legacy TTL | `false` |
| `OTP_P0A_SANCTUM_TOKEN_TTL_MINUTES` | 180 | 180 | 180 | Flag 3h | TTL anunciado distinto | N/A |
| `OTP_P0A_CLEANUP_ENABLED` | false | dry-run; luego true ops | Fase 8 | Ops | Schedule no corre | `false` |
| `OTP_P0A_CLEANUP_*_RETENTION_DAYS` | 30/30/7/30/30 | defaults | defaults | Cleanup | Retiene más/menos | ajustar días |
| `OTP_P0A_CLEANUP_DEFAULT_BATCH` | 1000 | 100–1000 | 1000 | Cleanup | Lotes distintos | bajar |
| `OTP_P0A_CLEANUP_SCHEDULE_TIME` | 03:00 | 03:00 | ops | Cleanup ON | Hora distinta | flag OFF |

**Obsoleta:** `OTP_P0A_SMS_DELIVERY_PROVIDER` — ignorada; no configurar.

---

## 3. Comandos seguros — configuración efectiva staging

Ejecutar en **staging** (SSH/Forge tinker). Salida esperada: booleanos, enteros, FQCN. **Nunca** imprimir `VONAGE_*`, tokens, teléfonos, emails, OTP.

```bash
php artisan tinker --execute="
\$c = config('otp.p0a');
\$flags = \$c['flags'] ?? [];
echo 'driver='.(\$c['delivery']['driver'] ?? 'null').PHP_EOL;
echo 'provider_class='.get_class(app(\\App\\Contracts\\Otp\\OtpDeliveryProvider::class)).PHP_EOL;
echo 'infrastructure='.json_encode(\$flags['infrastructure_enabled'] ?? null).PHP_EOL;
echo 'anti_abuse='.json_encode(\$flags['anti_abuse_enabled'] ?? null).PHP_EOL;
echo 'sms_delivery='.json_encode(\$flags['sms_delivery_enabled'] ?? null).PHP_EOL;
echo 'register='.json_encode(\$flags['akubica_register_enabled'] ?? null).PHP_EOL;
echo 'login='.json_encode(\$flags['akubica_login_enabled'] ?? null).PHP_EOL;
echo 'step_up_results='.json_encode(\$flags['step_up_results_enabled'] ?? null).PHP_EOL;
echo 'step_up_invoices='.json_encode(\$flags['step_up_invoices_enabled'] ?? null).PHP_EOL;
echo 'secure_links_results='.json_encode(\$flags['secure_links_results_enabled'] ?? null).PHP_EOL;
echo 'secure_links_invoices='.json_encode(\$flags['secure_links_invoices_enabled'] ?? null).PHP_EOL;
echo 'bearer_results='.json_encode(\$flags['step_up_bearer_results_enabled'] ?? null).PHP_EOL;
echo 'bearer_invoices='.json_encode(\$flags['step_up_bearer_invoices_enabled'] ?? null).PHP_EOL;
echo 'bearer_master='.json_encode(\$flags['step_up_bearer_downloads_enabled'] ?? null).PHP_EOL;
echo 'sanctum_3h='.json_encode(\$flags['sanctum_3h_enabled'] ?? null).PHP_EOL;
echo 'grant_ttl='.(\$c['step_up']['grant_ttl_minutes'] ?? '').PHP_EOL;
echo 'bind_pat='.json_encode(\$c['step_up']['bind_to_sanctum_token'] ?? null).PHP_EOL;
echo 'secure_ttl='.(\$c['secure_links']['ttl_minutes'] ?? '').PHP_EOL;
echo 'secure_max_opens='.(\$c['secure_links']['max_opens'] ?? '').PHP_EOL;
echo 'sanctum_ttl_min='.(\$c['sanctum']['target_expiration_minutes'] ?? '').PHP_EOL;
echo 'cleanup_enabled='.json_encode(\$c['cleanup']['enabled'] ?? null).PHP_EOL;
echo 'cleanup_challenges_days='.(\$c['cleanup']['challenges_retention_days'] ?? '').PHP_EOL;
echo 'vonage_key_set='.(trim((string)config('vonage.api_key'))!==''?'yes':'no').PHP_EOL;
echo 'vonage_secret_set='.(trim((string)config('vonage.api_secret'))!==''?'yes':'no').PHP_EOL;
echo 'vonage_from_set='.(trim((string)config('vonage.sms_from'))!==''?'yes':'no').PHP_EOL;
"

php artisan config:show otp.p0a.delivery.driver 2>/dev/null || true
php artisan about | head -40
# Workers (sin secretos): confirmar proceso queue activo en Forge/supervisor
php artisan queue:monitor 2>/dev/null || echo 'queue_monitor_n/a'
php artisan schedule:list 2>/dev/null | grep -i prune || echo 'prune_schedule_absent_or_flag_off'
```

Tras `config:cache`, los valores deben coincidir con env de staging.

---

## 4–8. Matrices QA (staging / Postman)

Evidencia: solo HTTP status + error `code` + IDs opacos (`challenge_id`/`grant_id` enmascarados). Sin OTP, teléfonos, tokens, URLs completas.

### 4. Registro

| # | Caso | Esperado | Resultado | Evidencia |
|---|------|----------|-----------|-----------|
| R1 | Request OTP identidad nueva | 202 + challenge | | |
| R2 | SMS recibido | canal SMS | | |
| R3 | OTP incorrecto | 422 `INVALID_CODE` | | |
| R4 | OTP correcto | 200 token + user | | |
| R5 | User+Customer creados | sí | | |
| R6 | `expires_in`/`expires_at` | ≈10800 si 3h ON | | |
| R7 | Challenge consumido | 422 | | |
| R8 | Reutilizar challenge | 422 | | |
| R9 | Resend < cooldown | 429 | | |
| R10 | Resend ≥ cooldown | 202; código previo inválido | | |
| R11 | Teléfono existente → decoy | 202; sin duplicado User | | |

### 5. Login

| # | Caso | Esperado | Resultado | Evidencia |
|---|------|----------|-----------|-----------|
| L1 | Request user verificado | 202 + SMS | | |
| L2 | OTP incorrecto | 422 | | |
| L3 | OTP correcto | 200 token | | |
| L4 | Endpoint privado OK | 200 | | |
| L5 | Challenge consumido | 422 | | |
| L6 | Teléfono inexistente | 202 decoy; sin SMS | | |
| L7 | Teléfono no verificado | 202 decoy; sin SMS | | |
| L8 | Ambiguo (si aplica) | controlado / decoy | | |
| L9 | Revoke → privado | 401 | | |

### 6. Resultados

| # | Caso | Esperado | Resultado |
|---|------|----------|-----------|
| Res1–7 | login → step-up → grant | 202/422/200 | |
| Res8 | Emitir secure link | 201 | |
| Res9 | GET sin Bearer | 200 PDF | |
| Res10 | 2ª apertura (max_opens=1) | 410 `SECURE_LINK_CONSUMED` | |
| Res11 | Expirado | 410 `SECURE_LINK_EXPIRED` | |
| Res12 | Grant invoices | 422/403 `STEP_UP_GRANT_INVALID` | |
| Res13 | Grant otro order | invalid | |
| Res14 | Pedido ajeno | 404 | |
| Res15 | No listo | 409 `RESULT_NOT_READY` | |
| Res16a | Bearer sin header (enforcement ON) | 403 `STEP_UP_REQUIRED` | |
| Res16b | Grant válido | 200 | |
| Res16c | Grant exp/rev | 403 | |
| Res17 | Secure link sin Bearer/header | 200 (si no consumido) | |

### 7. Facturas

Equivalente Res* con purpose `step_up_invoices`, `INVOICE_NOT_FOUND`, `INVOICE_NOT_READY`, carpeta Postman **19**. Con enforcement OFF: Bearer legacy ownership-only = 200.

### 8. Cross-purpose / cross-resource

| Caso | Esperado |
|------|----------|
| Register challenge → login verify | 422 `INVALID_CODE` |
| Login challenge → register verify | 422 |
| Results challenge → invoice verify | 422 |
| Invoice challenge → results verify | 422 |
| Results grant → invoice download/link | invalid |
| Invoice grant → results download/link | invalid |
| Grant otro PAT | invalid (bind ON) |
| Grant otro user | 404/invalid |

Postman: carpetas **01, 02, 18, 19**.

---

## 9. Expiración Sanctum

No esperar 3 h. Usar `tests/Feature/Api/V1/AkubicaSanctumTokenExpirationP0c1Test.php` + smoke staging:

| Caso | Esperado |
|------|----------|
| Login con 3h ON | `expires_in` ≈ 10800 |
| PAT `expires_at` no null | sí |
| Acceso antes de expirar | 200 |
| Expirado (test clock) | 401 |
| Nuevo token ≠ grant del PAT anterior | invalid |
| Secure link previo | solo su TTL |

---

## 10. Pruning OTP (staging)

```bash
php artisan list | grep prune-otp
php artisan akubica:prune-otp --dry-run
# NO --force en esta revisión salvo autorización expresa
php artisan schedule:list | grep -i prune || echo 'no_schedule'
```

Verificar: conteos, omitidos, sin PII en stdout, sin errores FK. Si `CLEANUP_ENABLED=false` → schedule ausente o inerte.

---

## 11–12. Postman / OpenAPI

Ver salida del validador `tools/p0d1_validate_docs.py` y comparación Laravel↔OpenAPI (61 ops, v1.2.0).

---

## 13. Suite automatizada

Registrar dos corridas `tests/Feature/Api/V1` + `tests/Unit/Otp` + validador + `git diff --check` en el reporte final de ejecución.

---

## 14. Observabilidad (sanitización)

| Flujo | Eventos | ¿PII en log app? |
|-------|---------|------------------|
| Delivery | `otp_delivery_attempted`, `*_suppressed`, `*_fallback` | Allowlist (sin phone/email/OTP) |
| Secure link | `otp_secure_link_issued`, `otp_secure_link_storage_failed` | `public_id` opaco |
| Pruning | `akubica_otp_prune_completed` / `_failed` | Agregados / mensaje sanitizado |
| Step-up / Bearer reject | Sin Log dedicado | Solo HTTP codes |
| Legacy OTP email | `akubica_otp_delivery_failed` | **Observación:** puede loguear `email` (path legacy) |

No deben aparecer en logs P0-A: OTP, teléfono completo, Bearer, grant plano, secure token, `token_hash`, path interno storage.

---

## 15. Checklist secretos producción (solo nombres)

- `VONAGE_KEY`, `VONAGE_SECRET`, `VONAGE_SMS_FROM`
- `OTP_P0A_DELIVERY_DRIVER=vonage`
- Flags OTP listados en §2 (activar por fases)
- `OTP_P0A_SANCTUM_3H_ENABLED` / `OTP_P0A_SANCTUM_TOKEN_TTL_MINUTES`
- `OTP_P0A_CLEANUP_*`
- `APP_URL`
- Filesystem/storage PDF resultados/facturas
- Redis (reservas delivery)
- `MAIL_*` solo si email fallback ON

---

## 16. Rollout production (gradual)

1. Deploy flags OFF → migrate → `optimize:clear` / `config:cache` → workers → smoke legacy  
2. Login/register SMS + Vonage  
3. Step-up + secure links **results** (TTL=5, max_opens=1)  
4. Invoices step-up + secure links  
5. Enforcement Bearer **results** (flag específico, no master)  
6. Enforcement Bearer **invoices**  
7. Sanctum 3h  
8. Cleanup tras dry-run revisado  

No activar `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED` de entrada.

---

## 17. Rollback por bloque

| Bloque | Acción | Nota |
|--------|--------|------|
| Delivery | `OTP_P0A_DELIVERY_DRIVER=null`, SMS_DELIVERY=false | Sin SMS |
| Login/Register | flags OFF | Legacy email |
| Step-up | flags OFF | 503 endpoints |
| Secure links | flags OFF | 503; links ya emitidos viven hasta TTL/consume |
| Enforcement | flags OFF | Bearer ownership-only |
| Sanctum 3h | flag OFF | **Nuevos** tokens; PAT con `expires_at` conservan expiración |
| Cleanup | flag OFF + quitar schedule | No undelete |

Apagar flags **no** elimina datos. `down()` de migraciones OTP puede ser no-op → no revertir a ciegas.

---

## 18–19. Checklist y veredicto

Completar tras QA staging + suite (tabla en reporte de ejecución). Veredicto candidato: **LISTO PARA PRODUCCIÓN CON ROLLOUT GRADUAL** si suite verde y staging SMS/step-up/enforcement OK; **BLOQUEADO** solo ante fallos propios de contrato o delivery crítico sin mitigación.
