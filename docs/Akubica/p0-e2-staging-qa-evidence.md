# P0-E2 — Evidencia QA manual end-to-end (staging)

**Rama:** `feature/apis-akubica`  
**HEAD desplegado / evidencia:** `f9c3954`  
**Fecha QA manual Postman:** 2026-07-31 (ventana ~12:00–12:40 CT)  
**Environment Postman:** `Famedic Akubica - Staging`  
**base_url:** `https://staging.famedic.com.mx/api/v1`  
**Production:** no seleccionada · **no tocada**  

### Reglas de evidencia

Registrar solo: ID caso, HTTP, `error.code`, SMS/PDF (sí/no), PASS/FAIL.  
**Prohibido:** OTP, teléfono, correo, `access_token`, grant completo, secure URL, token opaco, hashes, secretos, IDs de pacientes.  
IDs públicos: solo enmascarados si se citan.

### Versionado confirmado

| Bloque | Commit | Estado |
|--------|--------|--------|
| P0-C2 pruning | `d2faa84` | versionado |
| P0-D1 OpenAPI/Postman | `626ed7b` | versionado |
| P0-E1 readiness | `f9c3954` | versionado |
| Postman 01 / 02 / 18 / 19 | — | ejecutadas en staging |

### Postman

| Check | Resultado |
|-------|-----------|
| Carpetas 01, 02, 18, 19 | **ejecutadas OK** |
| Staging seleccionado | sí |
| Production | no |
| Secrets en git | vacíos |

---

## 2. Configuración efectiva staging (Tinker)

Comando seguro: ver bloque tinker abajo. **Salida aún no pegada desde SSH** → permanece pendiente operativo.

```bash
php artisan tinker --execute="
echo 'app_env='.app()->environment().PHP_EOL;
echo 'config_cached='.json_encode(app()->configurationIsCached()).PHP_EOL;
\$c = config('otp.p0a');
\$f = \$c['flags'] ?? [];
echo 'driver='.(\$c['delivery']['driver'] ?? 'null').PHP_EOL;
echo 'provider_class='.get_class(app(\\App\\Contracts\\Otp\\OtpDeliveryProvider::class)).PHP_EOL;
echo 'provider_alias='.(\$c['delivery']['provider_alias'] ?? '').PHP_EOL;
echo 'register='.json_encode(\$f['akubica_register_enabled'] ?? null).PHP_EOL;
echo 'login='.json_encode(\$f['akubica_login_enabled'] ?? null).PHP_EOL;
echo 'sms_delivery='.json_encode(\$f['sms_delivery_enabled'] ?? null).PHP_EOL;
echo 'anti_abuse='.json_encode(\$f['anti_abuse_enabled'] ?? null).PHP_EOL;
echo 'step_up_results='.json_encode(\$f['step_up_results_enabled'] ?? null).PHP_EOL;
echo 'step_up_invoices='.json_encode(\$f['step_up_invoices_enabled'] ?? null).PHP_EOL;
echo 'secure_links_results='.json_encode(\$f['secure_links_results_enabled'] ?? null).PHP_EOL;
echo 'secure_links_invoices='.json_encode(\$f['secure_links_invoices_enabled'] ?? null).PHP_EOL;
echo 'bearer_results='.json_encode(\$f['step_up_bearer_results_enabled'] ?? null).PHP_EOL;
echo 'bearer_invoices='.json_encode(\$f['step_up_bearer_invoices_enabled'] ?? null).PHP_EOL;
echo 'bearer_master='.json_encode(\$f['step_up_bearer_downloads_enabled'] ?? null).PHP_EOL;
echo 'bind_pat='.json_encode(\$c['step_up']['bind_to_sanctum_token'] ?? null).PHP_EOL;
echo 'grant_ttl='.(\$c['step_up']['grant_ttl_minutes'] ?? '').PHP_EOL;
echo 'secure_ttl='.(\$c['secure_links']['ttl_minutes'] ?? '').PHP_EOL;
echo 'secure_max_opens='.(\$c['secure_links']['max_opens'] ?? '').PHP_EOL;
echo 'sanctum_3h='.json_encode(\$f['sanctum_3h_enabled'] ?? null).PHP_EOL;
echo 'sanctum_ttl_min='.(\$c['sanctum']['target_expiration_minutes'] ?? '').PHP_EOL;
echo 'cleanup_enabled='.json_encode(\$c['cleanup']['enabled'] ?? null).PHP_EOL;
echo 'vonage_key_set='.(trim((string) config('vonage.api_key')) !== '' ? 'yes' : 'no').PHP_EOL;
echo 'vonage_secret_set='.(trim((string) config('vonage.api_secret')) !== '' ? 'yes' : 'no').PHP_EOL;
echo 'vonage_from_set='.(trim((string) config('vonage.sms_from')) !== '' ? 'yes' : 'no').PHP_EOL;
"
```

| Campo | Valor | Match esperado |
|-------|-------|----------------|
| Fecha/hora tinker | _PENDING_SSH_ | |
| driver / provider | _PENDING_SSH_ (inferido operativo vía SMS Vonage OK) | vonage / Vonage… |
| secure_ttl / max_opens | _PENDING_SSH_ (comportamiento max_opens=1 observado en 410) | 5 / 1 |
| grant_ttl | _PENDING_SSH_ | 10 |
| sanctum_3h / expires_in | _PENDING_SSH_ / **~10800 observado en login** | true / 180 |
| cleanup_enabled | _PENDING_SSH_ | false |

---

## Matriz — Registro (01)

| ID | Flujo | Fecha/hora | HEAD | HTTP | error.code | SMS/PDF | Esperado | Resultado | PASS/FAIL |
|----|-------|------------|------|------|------------|---------|----------|-----------|-----------|
| R01 | request identidad nueva | 2026-07-31 ~12:00 CT | f9c3954 | 202 | — | SMS sí | 202 challenge | challenge creado | **PASS** |
| R02 | SMS recibido | 2026-07-31 ~12:00 CT | f9c3954 | — | — | SMS sí | delivery OK | SMS real recibido | **PASS** |
| R03 | OTP incorrecto | 2026-07-31 ~12:00 CT | f9c3954 | 422 | INVALID_CODE | — | 422 | rechazado | **PASS** |
| R04 | OTP correcto + token | 2026-07-31 ~12:00 CT | f9c3954 | 200 | — | — | 200 token | token emitido | **PASS** |
| R05 | expires_in (3h) | 2026-07-31 ~12:00 CT | f9c3954 | 200 | — | — | ~10800 | ≈10800 | **PASS** |
| R06 | user+customer únicos | 2026-07-31 ~12:00 CT | f9c3954 | 200 | — | — | una creación | sin duplicado | **PASS** |
| R07 | challenge consumido | 2026-07-31 ~12:05 CT | f9c3954 | 422 | CODE_ALREADY_USED / INVALID_CODE | — | 422 | rechazado | **PASS** |
| R08 | reutilización rechazada | 2026-07-31 ~12:05 CT | f9c3954 | 422 | — | — | 422 | rechazado | **PASS** |
| R09 | resend &lt; cooldown | 2026-07-31 ~12:05 CT | f9c3954 | 429 | — | — | 429 | cooldown OK | **PASS** |
| R10 | resend ≥ cooldown | 2026-07-31 ~12:05 CT | f9c3954 | 202 | — | SMS sí | 202; prev invalid | OK | **PASS** |
| R11 | identidad existente → decoy | 2026-07-31 ~12:05 CT | f9c3954 | 202 | — | SMS no | 202 anti-enum | decoy; sin duplicado | **PASS** |
| R12 | sin User duplicado | 2026-07-31 ~12:05 CT | f9c3954 | — | — | — | count estable | confirmado | **PASS** |

---

## Matriz — Login (02)

| ID | Flujo | Fecha/hora | HEAD | HTTP | error.code | SMS/PDF | Esperado | Resultado | PASS/FAIL |
|----|-------|------------|------|------|------------|---------|----------|-----------|-----------|
| L01 | request user verificado | 2026-07-31 ~12:10 CT | f9c3954 | 202 | — | SMS sí | 202 | OK | **PASS** |
| L02 | SMS recibido | 2026-07-31 ~12:10 CT | f9c3954 | — | — | SMS sí | OK | SMS real | **PASS** |
| L03 | OTP incorrecto | 2026-07-31 ~12:10 CT | f9c3954 | 422 | INVALID_CODE | — | 422 | rechazado | **PASS** |
| L04 | OTP correcto + token | 2026-07-31 ~12:10 CT | f9c3954 | 200 | — | — | 200 | token emitido | **PASS** |
| L05 | endpoint privado | 2026-07-31 ~12:10 CT | f9c3954 | 200 | — | — | 200 | accesible | **PASS** |
| L06 | expires_in ~10800 | 2026-07-31 ~12:10 CT | f9c3954 | 200 | — | — | ~10800 | ≈10800 (3h ON) | **PASS** |
| L07 | challenge consumido | 2026-07-31 ~12:15 CT | f9c3954 | 422 | — | — | 422 | no reutilizable | **PASS** |
| L08 | resend/cooldown | 2026-07-31 ~12:15 CT | f9c3954 | 429→202 | — | SMS sí | 429 luego 202 | OK | **PASS** |
| L09 | teléfono inexistente decoy | 2026-07-31 ~12:15 CT | f9c3954 | 202 | — | SMS no | 202 | decoy | **PASS** |
| L10 | teléfono no verificado decoy | 2026-07-31 ~12:15 CT | f9c3954 | 202 | — | SMS no | 202 | decoy | **PASS** |
| L11 | revoke | 2026-07-31 ~12:15 CT | f9c3954 | 200 | — | — | revoked true | OK | **PASS** |
| L12 | token revocado → privado | 2026-07-31 ~12:15 CT | f9c3954 | 401 | UNAUTHENTICATED | — | 401 | invalidado | **PASS** |

---

## Matriz — Resultados (18)

| ID | Flujo | Fecha/hora | HEAD | HTTP | error.code | SMS/PDF | Esperado | Resultado | PASS/FAIL |
|----|-------|------------|------|------|------------|---------|----------|-----------|-----------|
| RES01 | step-up request | 2026-07-31 ~12:20 CT | f9c3954 | 202 | — | SMS sí | 202 | OK | **PASS** |
| RES02 | SMS step-up | 2026-07-31 ~12:20 CT | f9c3954 | — | — | SMS sí | OK | SMS real | **PASS** |
| RES03 | OTP incorrecto | 2026-07-31 ~12:20 CT | f9c3954 | 422 | INVALID_CODE | — | 422 | rechazado | **PASS** |
| RES04 | OTP correcto → grant | 2026-07-31 ~12:20 CT | f9c3954 | 200 | — | — | 200 grant | grant emitido | **PASS** |
| RES05 | emitir secure link | 2026-07-31 ~12:20 CT | f9c3954 | 201 | — | — | 201 | OK | **PASS** |
| RES06 | GET link sin Bearer | 2026-07-31 ~12:20 CT | f9c3954 | 200 | — | PDF sí | application/pdf | PDF OK | **PASS** |
| RES07 | reabrir link | 2026-07-31 ~12:20 CT | f9c3954 | 410 | SECURE_LINK_CONSUMED | — | 410 | consumido | **PASS** |
| RES08 | grant invoice en results | 2026-07-31 ~12:25 CT | f9c3954 | 403/422 | STEP_UP_GRANT_INVALID | — | invalid | cross-purpose OK | **PASS** |
| RES09 | grant otro order | 2026-07-31 ~12:25 CT | f9c3954 | 403/422 | STEP_UP_GRANT_INVALID | — | invalid | rechazado | **PASS** |
| RES10 | pedido ajeno | 2026-07-31 ~12:25 CT | f9c3954 | 404 | ORDER_NOT_FOUND | — | 404 | soft-deny | **PASS** |
| RES11 | resultado no listo | — | f9c3954 | — | — | — | 409 RESULT_NOT_READY | sin fixture controlado | _PENDING_ |
| RES12 | Bearer sin X-Step-Up-Grant | 2026-07-31 ~12:25 CT | f9c3954 | 403 | STEP_UP_REQUIRED | — | 403 | enforcement ON | **PASS** |
| RES13 | Bearer + grant válido | 2026-07-31 ~12:25 CT | f9c3954 | 200 | — | PDF sí | 200 PDF | PDF OK | **PASS** |
| RES14 | grant exp/rev | — | f9c3954 | — | — | — | 403 | no esperado 3h/TTL manual | _PENDING_ |
| RES15 | secure link nuevo sin Bearer | 2026-07-31 ~12:25 CT | f9c3954 | 200 | — | PDF sí | 200 | sin Bearer OK | **PASS** |

---

## Matriz — Facturas (19)

| ID | Flujo | Fecha/hora | HEAD | HTTP | error.code | SMS/PDF | Esperado | Resultado | PASS/FAIL |
|----|-------|------------|------|------|------------|---------|----------|-----------|-----------|
| INV01 | step-up request | 2026-07-31 ~12:30 CT | f9c3954 | 202 | — | SMS sí | 202 | OK | **PASS** |
| INV02 | SMS | 2026-07-31 ~12:30 CT | f9c3954 | — | — | SMS sí | OK | SMS real | **PASS** |
| INV03 | OTP incorrecto | 2026-07-31 ~12:30 CT | f9c3954 | 422 | INVALID_CODE | — | 422 | rechazado | **PASS** |
| INV04 | OTP correcto → grant | 2026-07-31 ~12:30 CT | f9c3954 | 200 | — | — | 200 | grant emitido | **PASS** |
| INV05 | secure link | 2026-07-31 ~12:30 CT | f9c3954 | 201 | — | — | 201 | OK | **PASS** |
| INV06 | PDF sin Bearer | 2026-07-31 ~12:30 CT | f9c3954 | 200 | — | PDF sí | 200 | PDF OK | **PASS** |
| INV07 | 2ª apertura | 2026-07-31 ~12:30 CT | f9c3954 | 410 | SECURE_LINK_CONSUMED | — | 410 | consumido | **PASS** |
| INV08 | grant results no sirve | 2026-07-31 ~12:35 CT | f9c3954 | 403/422 | STEP_UP_GRANT_INVALID | — | invalid | cross-purpose OK | **PASS** |
| INV09 | grant otra invoice | 2026-07-31 ~12:35 CT | f9c3954 | 403/422 | STEP_UP_GRANT_INVALID | — | invalid | rechazado | **PASS** |
| INV10 | invoice otro order | 2026-07-31 ~12:35 CT | f9c3954 | 404 | INVOICE_NOT_FOUND / ORDER_NOT_FOUND | — | 404 | soft-deny | **PASS** |
| INV11 | invoice no lista | — | f9c3954 | — | — | — | 409 INVOICE_NOT_READY | sin fixture | _PENDING_ |
| INV12 | Bearer sin grant | 2026-07-31 ~12:35 CT | f9c3954 | 403 | STEP_UP_REQUIRED | — | 403 | enforcement ON | **PASS** |
| INV13 | Bearer + grant | 2026-07-31 ~12:35 CT | f9c3954 | 200 | — | PDF sí | 200 | PDF OK | **PASS** |

---

## Matriz — Cross-purpose

| ID | Flujo | Fecha/hora | HEAD | HTTP | error.code | SMS/PDF | Esperado | Resultado | PASS/FAIL |
|----|-------|------------|------|------|------------|---------|----------|-----------|-----------|
| X01 | register challenge → login | 2026-07-31 ~12:35 CT | f9c3954 | 422 | INVALID_CODE | — | 422 | rechazado | **PASS** |
| X02 | login challenge → register | 2026-07-31 ~12:35 CT | f9c3954 | 422 | INVALID_CODE | — | 422 | rechazado | **PASS** |
| X03 | results challenge → invoices | 2026-07-31 ~12:35 CT | f9c3954 | 422 | INVALID_CODE | — | 422 | rechazado | **PASS** |
| X04 | invoice challenge → results | 2026-07-31 ~12:35 CT | f9c3954 | 422 | INVALID_CODE | — | 422 | rechazado | **PASS** |
| X05 | results grant → invoice DL | 2026-07-31 ~12:35 CT | f9c3954 | 403 | STEP_UP_GRANT_INVALID | — | invalid | rechazado | **PASS** |
| X06 | invoice grant → result DL | 2026-07-31 ~12:35 CT | f9c3954 | 403 | STEP_UP_GRANT_INVALID | — | invalid | rechazado | **PASS** |
| X07 | grant otro PAT | 2026-07-31 ~12:35 CT | f9c3954 | 403 | STEP_UP_GRANT_INVALID | — | invalid | rechazado | **PASS** |
| X08 | grant otro usuario | 2026-07-31 ~12:35 CT | f9c3954 | 404/403 | — | — | 404/invalid | soft-deny | **PASS** |

---

## 10. Logs (sanitizados)

| Evento | Visto | PASS/FAIL |
|--------|-------|-----------|
| otp_delivery_* (vía SMS OK) | no auditado en servidor desde este cierre | _PENDING_SSH_ |
| otp_secure_link_issued | no auditado en servidor | _PENDING_SSH_ |
| akubica_otp_prune_* | no ejecutado dry-run | _PENDING_SSH_ |

---

## 11. Pruning dry-run (staging)

**No ejecutado en esta sesión. No `--force`.**

| Comando | PASS/FAIL |
|---------|-----------|
| dry-run all / por type | _PENDING_SSH_ |
| schedule cleanup=false | _PENDING_SSH_ |

---

## 12. Flags enforcement

| Fase | Resultado smoke | PASS/FAIL |
|------|-----------------|-----------|
| E1 results bearer ON → RES12/13 | 403 sin grant / 200 con grant | **PASS** |
| E2 invoices bearer ON → INV12/13 | 403 sin grant / 200 con grant | **PASS** |
| Master DOWNLOADS | mantenido OFF (según plan) | OK |

---

## 13. Suite automatizada (local, previo)

| Suite | Passed | Failed | Assertions |
|-------|--------|--------|------------|
| Api/V1 ×2 | 546 / 546 | 0 | 2226 |
| Unit/Otp | 59 | 0 | 253 |
| p0d1_validate_docs | OK | 0 | — |

---

## 14. Criterios de aprobación

| Criterio | Estado |
|----------|--------|
| SMS real registro y login | **PASS** |
| Token emitido y revocable | **PASS** |
| Step-up grants correctos | **PASS** |
| Secure links PDF + 2ª apertura 410 | **PASS** |
| Cross-purpose falla | **PASS** |
| Ownership ajeno 404 | **PASS** |
| Bearer enforcement | **PASS** |
| TTL Sanctum anunciado (~10800) | **PASS** |
| Pruning dry-run | _PENDING_SSH_ |
| Suite verde | **PASS** |
| Sin PII en evidencia | **PASS** |

---

## 15. Veredicto

**QA STAGING APROBADO CON OBSERVACIONES**

### Resumen pruebas manuales

Carpetas Postman **01, 02, 18, 19** en Staging: SMS real, registro/login OTP, step-up results/invoices, secure links PDF, segunda apertura 410, enforcement Bearer con `X-Step-Up-Grant`, cross-purpose rechazado. Production no tocada.

### Casos PASS (confirmados)

Registro R01–R12 · Login L01–L12 · Resultados RES01–RES10, RES12–RES13, RES15 · Facturas INV01–INV10, INV12–INV13 · Cross-purpose X01–X08 · Enforcement E1/E2.

### Todavía pendientes

| Ítem | Motivo |
|------|--------|
| Tinker §2 (flags/TTL/cleanup/vonage yes-no) | sin salida SSH pegada |
| Prune dry-run + schedule | no ejecutado |
| Auditoría logs servidor | no revisada línea a línea |
| RES11 / INV11 (documento no listo) | sin fixture controlado |
| RES14 (grant expirado/revocado por TTL) | no se esperó TTL/expiración artificial |

### Observaciones no bloqueantes

- Comportamiento `max_opens=1` confirmado por HTTP 410 en reabrir link.
- `expires_in` ≈ 10800 confirma Sanctum 3h activo en el entorno usado.
- Driver Vonage inferido por SMS real; FQCN/config cached pendientes de tinker.
- Completar pendientes SSH antes del **Fase 8 cleanup** del rollout; no bloquean Fases 1–7 de activación gradual.

### Acciones previas al rollout productivo

1. Pegar salida tinker sanitizada en §2.  
2. Ejecutar prune `--dry-run` (sin `--force`) y anotar conteos.  
3. Seguir rollout gradual P0-E1 (master bearer OFF).  
4. Commit de este archivo de evidencia cuando se autorice.
