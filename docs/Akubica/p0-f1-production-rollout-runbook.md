# P0-F1 — Runbook de rollout gradual a producción (Famedic–Akubica)

**Tipo:** runbook operativo (documentación). **No modifica código ni toca producción por sí solo.**
**Rama de referencia:** `feature/apis-akubica`
**Contrato:** `/api/v1` · OpenAPI v1.2.1 (61 operaciones) · Postman `/postman/`
**Prerrequisitos de entrada (confirmados en P0-E1 / P0-E2):**

- QA staging aprobado con observaciones.
- Postman 01 / 02 / 18 / 19 ejecutados con éxito en staging.
- SMS real, registro/login OTP, step-up results/invoices, secure links PDF, 410 en segunda apertura, Bearer enforcement y cross-purpose validados.
- Sanctum `expires_in` ≈ 10800 observado en staging (flag 3h ON allí).
- Suite `tests/Feature/Api/V1` 546/546 ×2.
- **Production no ha sido tocada.**
- **Pruning dry-run pendiente → cleanup permanece OFF / Fase 8 BLOQUEADA.**

### Reglas de seguridad documental

- No registrar ni pegar: OTP, teléfonos, correos, Bearer, grants, secure URLs, `VONAGE_*`, hashes, IDs de pacientes.
- Evidencia: HTTP status, `error.code`, sí/no SMS/PDF, PASS/FAIL, métricas agregadas.
- Postman Production: secretos vacíos; `allow_production_writes=false` salvo autorización explícita por ventana.

### Fuentes canónicas de flags

| Fuente | Uso |
|--------|-----|
| `config/otp.php` → `otp.p0a.*` | Defaults reales de código |
| [`api-v1-feature-flags.md`](./api-v1-feature-flags.md) | Tabla flags / rollback |
| [`p0-e1-qa-staging-production-readiness.md`](./p0-e1-qa-staging-production-readiness.md) | Readiness + outline rollout |
| [`p0-e2-staging-qa-evidence.md`](./p0-e2-staging-qa-evidence.md) | Evidencia QA staging |
| [`p0-c2-otp-pruning-maintenance.md`](./p0-c2-otp-pruning-maintenance.md) | Cleanup (bloqueado aquí) |

---

## 0. Resumen de fases

| Fase | Objetivo | Flags de comportamiento nuevos |
|------|----------|--------------------------------|
| **0** Preflight | Checklist previo al deploy | Ninguno |
| **1** Deploy flags OFF | Código en prod; legacy intacto | Todos OFF (infra preparada; ver §3) |
| **2** Registro / login SMS | OTP SMS Akubica | register + login + SMS (+ anti-abuse) |
| **3** Results step-up + secure links | Challenge → grant → PDF | step-up results + secure links results |
| **4** Invoices step-up + secure links | Equivalente facturas | step-up invoices + secure links invoices |
| **5** Bearer enforcement results | Exige `X-Step-Up-Grant` | bearer results only |
| **6** Bearer enforcement invoices | Exige grant facturas | bearer invoices only |
| **7** Sanctum 3 h | PAT `expires_at` 180 min | sanctum 3h |
| **8** Cleanup | Prune OTP | **BLOQUEADA** (dry-run pendiente) |

**No activar** `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED` (master) en este rollout; preferir flags por recurso.

---

## 1. Preflight antes del deploy

Completar **antes** de cualquier cambio en el servidor de producción. Marcar ☐ → ☑.

### 1.1 Repositorio y release

| # | Check | Criterio |
|---|-------|----------|
| P01 | Rama aprobada | Release candidate acordado (p. ej. `feature/apis-akubica` o tag/merge a rama de deploy) |
| P02 | Commit aprobado | SHA de release documentado; incluye P0-C2, P0-D1, P0-E1/E2 y fixes de contrato |
| P03 | Working tree limpio en el artefacto a desplegar | Sin cambios locales no versionados en el paquete de release |
| P04 | `git diff --check` limpio | Sin whitespace errors en el commit de release |
| P05 | Suite Api/V1 verde | 546/546 (idealmente ×2) en el commit de release |
| P06 | OpenAPI / Postman alineados | v1.2.1 · 61 ops · guard Production presente |

### 1.2 Backup y datos

| # | Check | Criterio |
|---|-------|----------|
| P07 | Backup DB | Snapshot/backup reciente verificado (restore conocido) |
| P08 | Backup / plan de rollback de código | Deploy anterior reversible (Forge release / symlink) |
| P09 | Migraciones pendientes revisadas | `php artisan migrate:status` en staging equivalente; conocer qué aplicará `--force` en prod |
| P10 | **No** planear `migrate:rollback` a ciegas | `down()` de migraciones OTP puede ser no-op |

### 1.3 Infraestructura y configuración (nombres solo; sin valores secretos)

| # | Check | Criterio |
|---|-------|----------|
| P11 | Variables requeridas presentes en Forge/env prod | Flags §3 + `VONAGE_*` (solo “set/not set”) + Redis + mail si aplica |
| P12 | `APP_URL` | Coincide con dominio público HTTPS de producción |
| P13 | Redis | Conexión OK; usada por reservas delivery (`otp.p0a.delivery.redis_*`) |
| P14 | Queue workers | Al menos un worker activo para colas de la app; capacidad conocida |
| P15 | Storage PDFs | Disco/path de resultados y facturas accesible; permisos OK |
| P16 | Vonage configurado | `VONAGE_KEY` / `VONAGE_SECRET` / `VONAGE_SMS_FROM` presentes (no imprimir) |
| P17 | Driver SMS preparado | `OTP_P0A_DELIVERY_DRIVER=vonage` en env **antes** de Fase 2 (envío sigue gated por `SMS_DELIVERY_ENABLED`) |
| P18 | Postman Production | Secrets vacíos en git; environment Production seleccionado solo con autorización |
| P19 | `allow_production_writes=false` | Default; no poner `true` salvo ventana autorizada |
| P20 | Scheduler | Cron Forge → `schedule:run` operativo |
| P21 | Cleanup | `OTP_P0A_CLEANUP_ENABLED=false`; schedule prune **ausente o inerte** |

### 1.4 Personas, comunicación y abort

| # | Check | Criterio |
|---|-------|----------|
| P22 | Responsable técnico | Nombre + canal on-call durante la ventana |
| P23 | Responsable de negocio | Aprobación go/no-go por fase |
| P24 | Plan de comunicación | Integradores / ops / soporte avisados del orden de fases |
| P25 | Ventana de mantenimiento | Horario acordado; duración estimada Fase 1; contactos |
| P26 | Criterios de abortar (preflight) | Ver §14; si preflight falla → **NO desplegar** |

**Abortar preflight si:** backup no verificado, migraciones desconocidas, Redis/workers/storage/Vonage no listos, secretos en Postman git, cleanup ON por error, o responsable ausente.

---

## 2. Defaults reales de código (confirmados)

Fuente: `config/otp.php` (`otp.p0a.flags` / `delivery` / `cleanup` / `sanctum`).

| Variable | Default código |
|----------|----------------|
| `OTP_P0A_INFRASTRUCTURE_ENABLED` | `false` |
| `OTP_P0A_AKUBICA_REGISTER_ENABLED` | `false` |
| `OTP_P0A_AKUBICA_LOGIN_ENABLED` | `false` |
| `OTP_P0A_SMS_DELIVERY_ENABLED` | `false` |
| `OTP_P0A_DELIVERY_DRIVER` | `null` |
| `OTP_P0A_ANTI_ABUSE_ENABLED` | `false` |
| `OTP_P0A_EMAIL_FALLBACK_ENABLED` | `false` |
| `OTP_P0A_STEP_UP_RESULTS_ENABLED` | `false` |
| `OTP_P0A_STEP_UP_INVOICES_ENABLED` | `false` |
| `OTP_P0A_SECURE_LINKS_RESULTS_ENABLED` | `false` |
| `OTP_P0A_SECURE_LINKS_INVOICES_ENABLED` | `false` |
| `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED` | `false` |
| `OTP_P0A_STEP_UP_BEARER_RESULTS_ENABLED` | `false` |
| `OTP_P0A_STEP_UP_BEARER_INVOICES_ENABLED` | `false` |
| `OTP_P0A_SANCTUM_3H_ENABLED` | `false` |
| `OTP_P0A_SANCTUM_TOKEN_TTL_MINUTES` | `180` (numérico; inerte si flag 3h OFF) |
| `OTP_P0A_CLEANUP_ENABLED` | `false` |
| `OTP_P0A_SECURE_LINK_TTL_MINUTES` | `60` (código; staging QA usó **5**) |
| `OTP_P0A_SECURE_LINK_MAX_OPENS` | `5` (código; staging QA usó **1**) |

**Dependencias de assert (no omitir):**

- Register P0-A exige `INFRASTRUCTURE` + `ANTI_ABUSE`.
- Login P0-A exige `ANTI_ABUSE` (+ SMS para delivery real).
- Step-up operativo exige anti-abuse + SMS delivery en la práctica de QA.
- Si login/register/step-up ON y anti-abuse OFF → `OTP_CONFIGURATION_INVALID`.

Variable **inválida:** `OTP_P0A_SMS_DELIVERY_PROVIDER` (ignorada). Usar `OTP_P0A_DELIVERY_DRIVER`.

---

## 3. Estado inicial de producción (recomendado al cerrar Fase 1)

Todos los flags de **comportamiento nuevo** inician OFF. Infraestructura y driver pueden prepararse sin activar flujos.

```env
OTP_P0A_INFRASTRUCTURE_ENABLED=true
OTP_P0A_ANTI_ABUSE_ENABLED=false
OTP_P0A_AKUBICA_REGISTER_ENABLED=false
OTP_P0A_AKUBICA_LOGIN_ENABLED=false
OTP_P0A_SMS_DELIVERY_ENABLED=false
OTP_P0A_DELIVERY_DRIVER=vonage
OTP_P0A_EMAIL_FALLBACK_ENABLED=false

OTP_P0A_STEP_UP_RESULTS_ENABLED=false
OTP_P0A_STEP_UP_INVOICES_ENABLED=false
OTP_P0A_SECURE_LINKS_RESULTS_ENABLED=false
OTP_P0A_SECURE_LINKS_INVOICES_ENABLED=false

OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED=false
OTP_P0A_STEP_UP_BEARER_RESULTS_ENABLED=false
OTP_P0A_STEP_UP_BEARER_INVOICES_ENABLED=false

OTP_P0A_SANCTUM_3H_ENABLED=false
OTP_P0A_SANCTUM_TOKEN_TTL_MINUTES=180

OTP_P0A_CLEANUP_ENABLED=false
```

**Notas:**

- `INFRASTRUCTURE=true` + register/login `false` ⇒ flujos legacy de auth permanecen.
- `DELIVERY_DRIVER=vonage` **no envía** mientras `SMS_DELIVERY_ENABLED=false`.
- Secure links: acordar antes de Fase 3 valores de prod (recomendación alineada a staging QA: TTL `5`, max opens `1`) y setearlos **antes** de activar secure links; no dejar defaults de código `60`/`5` por omisión si la política es one-shot.
- Verificar estado efectivo post-deploy con tinker sanitizado (§12.2); no imprimir secretos.

---

## 4. Fase 1 — Deploy con flags OFF

### 4.1 Pasos

1. Confirmar preflight §1 completo.
2. **Backup** DB (+ nota de release anterior).
3. **Deploy** del commit aprobado (Forge / pipeline del proyecto).
4. `composer install --no-dev --optimize-autoloader` (o el equivalente del script Forge).
5. `npm ci && npm run build` **si** el release incluye assets front; omitir si el deploy de API no aplica.
6. `php artisan migrate --force`
7. `php artisan optimize:clear`
8. `php artisan config:cache`
9. `php artisan route:cache`
10. `php artisan view:cache`
11. `php artisan queue:restart`
12. Validar **workers** (procesos activos, cola no estancada).
13. Validar **scheduler** (`schedule:list`; **sin** `akubica:prune-otp` registrado si cleanup OFF).
14. Confirmar env §3 (flags comportamiento OFF, cleanup OFF).

### 4.2 Smoke tests legacy (sin activar P0-A)

| Área | Esperado |
|------|----------|
| Catálogo público | 200 |
| Auth existente (legacy) | Comportamiento pre-P0-A |
| Pedidos / resultados / facturas legacy | 200 / ownership intacto |
| Endpoints P0-A aún OFF | 503 / legacy según contrato; sin 5xx nuevos |
| Health / logs app | Sin excepciones nuevas post-deploy |

### 4.3 Criterio de avance → Fase 2

- Cero errores **5xx** nuevos atribuibles al deploy.
- Logs limpios (sin stack traces recurrentes).
- Métricas estables vs baseline pre-deploy.
- Rollback de código disponible.
- Cleanup sigue OFF.

### 4.4 Rollback Fase 1

- Revertir release Forge al deploy anterior **o** redeploy del SHA previo.
- No apagar infra sola si el problema es de migración/código; priorizar rollback de release.
- Si solo hay duda de flags: forzar §3 + `optimize:clear` + `config:cache` + `queue:restart`.

---

## 5. Fase 2 — Registro / login SMS

### 5.1 Activar únicamente

```env
OTP_P0A_INFRASTRUCTURE_ENABLED=true
OTP_P0A_ANTI_ABUSE_ENABLED=true
OTP_P0A_AKUBICA_REGISTER_ENABLED=true
OTP_P0A_AKUBICA_LOGIN_ENABLED=true
OTP_P0A_SMS_DELIVERY_ENABLED=true
OTP_P0A_DELIVERY_DRIVER=vonage
```

Mantener **OFF:** step-up, secure links, bearer enforcement, sanctum 3h, cleanup, email fallback (salvo decisión explícita aparte), master bearer.

Aplicar cambio con procedimiento §12.

### 5.2 Validación (teléfono de control; sin PII en evidencia)

| Caso | Esperado (resumen) |
|------|--------------------|
| Registro | Challenge + SMS accepted; verify OK |
| Login | Challenge + SMS; token emitido |
| Resend | Respetar cooldown / max resends |
| Cooldown | 429 o política documentada |
| OTP incorrecto | Error de dominio; sin filtrar existencia indebida |
| OTP correcto | 200 + token |
| Revoke | Token inválido tras revoke |
| Anti-enumeración | Respuestas alineadas a contrato (sin filtrar usuarios) |
| `expires_in` | **TTL legacy anunciado** (flag 3h aún OFF); no exigir ≈10800 |

Postman: solo con `allow_production_writes=true` **temporal** y autorización; revertir a `false` al cerrar ventana. Preferir script/ops interno si el guard bloquea.

### 5.3 Ventana mínima de observación

**≥ 30–60 minutos** en horario representativo (o tráfico mínimo acordado), antes de Fase 3.

### 5.4 Métricas a vigilar

| Métrica | Señal |
|---------|-------|
| SMS `accepted` | Volumen esperado del teléfono de control / early adopters |
| SMS `failed` / `suppressed` | Spike → pausar |
| Tasa de error HTTP auth OTP | Spike → pausar |
| Latencia request OTP / Vonage | Degradación sostenida → pausar |
| Duplicados de usuario / intents | Cualquier anomalía → **rollback inmediato** |
| 429 | Esperable bajo abuse; spike no atribuible a prueba → investigar |

### 5.5 Criterio de avance → Fase 3

Smoke §5.2 PASS; métricas dentro de umbrales §13; sin duplicación de usuarios; Vonage estable.

### 5.6 Rollback Fase 2

```env
OTP_P0A_AKUBICA_REGISTER_ENABLED=false
OTP_P0A_AKUBICA_LOGIN_ENABLED=false
OTP_P0A_SMS_DELIVERY_ENABLED=false
```

Opcional: `OTP_P0A_ANTI_ABUSE_ENABLED=false` si ningún otro flujo P0-A quedó ON.
Luego: `optimize:clear` → `config:cache` → `queue:restart`.
Auth vuelve a legacy email.

---

## 6. Fase 3 — Results step-up y secure links

### 6.1 Activar

```env
OTP_P0A_STEP_UP_RESULTS_ENABLED=true
OTP_P0A_SECURE_LINKS_RESULTS_ENABLED=true
```

Confirmar policy secure links (TTL / max opens) acordada.
Mantener **enforcement Bearer OFF** (`STEP_UP_BEARER_RESULTS/INVOICES/DOWNLOADS=false`).

### 6.2 Validar

| Caso | Esperado |
|------|----------|
| Challenge step-up results | 200 / challenge creado |
| OTP step-up | Grant emitido |
| Secure link | Emisión OK (no loguear URL) |
| PDF primera apertura | 200 |
| Segunda apertura | **410** (si max_opens=1) |
| Ownership incorrecto | **404** |
| Legacy Bearer download **sin** grant | **Sigue funcionando** (enforcement OFF) |

### 6.3 Observación

**≥ 30 minutos** con al menos un flujo controlado end-to-end.

### 6.4 Rollback Fase 3

```env
OTP_P0A_STEP_UP_RESULTS_ENABLED=false
OTP_P0A_SECURE_LINKS_RESULTS_ENABLED=false
```

+ §12. Links ya emitidos viven hasta TTL/consumo; no se “borran” al apagar el flag.

---

## 7. Fase 4 — Invoices step-up y secure links

### 7.1 Activar

```env
OTP_P0A_STEP_UP_INVOICES_ENABLED=true
OTP_P0A_SECURE_LINKS_INVOICES_ENABLED=true
```

Mantener bearer enforcement OFF.

### 7.2 Validar

Flujo equivalente a Fase 3 (challenge → OTP → grant → secure link → PDF → 410 → ownership 404 → Bearer legacy sin grant OK).

### 7.3 Rollback Fase 4

```env
OTP_P0A_STEP_UP_INVOICES_ENABLED=false
OTP_P0A_SECURE_LINKS_INVOICES_ENABLED=false
```

+ §12.

---

## 8. Fase 5 — Bearer enforcement results

### 8.1 Activar únicamente

```env
OTP_P0A_STEP_UP_BEARER_RESULTS_ENABLED=true
```

Mantener:

- `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED=false` (master)
- `OTP_P0A_STEP_UP_BEARER_INVOICES_ENABLED=false`

### 8.2 Validar

| Caso | Esperado |
|------|----------|
| Bearer download results **sin** `X-Step-Up-Grant` | **403** `STEP_UP_REQUIRED` |
| Grant válido + purpose/resource correctos | **200** |
| Grant cross-purpose | **403** |
| Secure link **sin** Bearer | **200** (path opaco; independiente del enforcement Bearer) |
| Clientes integrados | Actualizados para enviar header de grant |

### 8.3 Criterio de pausa (integradores)

**Pausar / no avanzar a Fase 6** si:

- Aparecen clientes productivos que descargan resultados por Bearer **sin** soporte de `X-Step-Up-Grant`.
- Suben 403 step-up de forma sostenida fuera de la prueba controlada.
- Negocio no ha comunicado el cambio a integradores.

### 8.4 Rollback Fase 5

```env
OTP_P0A_STEP_UP_BEARER_RESULTS_ENABLED=false
```

+ §12. Vuelve ownership-only en Bearer results.

---

## 9. Fase 6 — Bearer enforcement invoices

### 9.1 Activar

```env
OTP_P0A_STEP_UP_BEARER_INVOICES_ENABLED=true
```

Master sigue OFF.

### 9.2 Validar

Repetir matriz §8.2 para facturas. Misma política de pausa por clientes antiguos.

### 9.3 Rollback Fase 6

```env
OTP_P0A_STEP_UP_BEARER_INVOICES_ENABLED=false
```

+ §12.

---

## 10. Fase 7 — Sanctum 3 horas

### 10.1 Activar

```env
OTP_P0A_SANCTUM_3H_ENABLED=true
OTP_P0A_SANCTUM_TOKEN_TTL_MINUTES=180
```

### 10.2 Validar

| Caso | Esperado |
|------|----------|
| Nuevos tokens | `expires_in` ≈ **10800** |
| Persistencia | `expires_at` no null en PAT nuevo |
| Tokens anteriores | Conservan comportamiento previo (sin reescritura masiva) |
| Login nuevo | OK |
| Revoke | OK |
| Grants | Siguen ligados al PAT correcto (`bind_to_sanctum_token`) |

No esperar 3 h reales en prod: usar smoke de emisión + tests ya verdes del commit.

### 10.3 Rollback Fase 7

```env
OTP_P0A_SANCTUM_3H_ENABLED=false
```

+ §12.

**Importante:** apagar el flag **no elimina** `expires_at` ya persistido en tokens emitidos con 3h ON; esos PAT conservan su expiración. Solo afecta **nuevas** emisiones (vuelven al TTL legacy anunciado).

---

## 11. Fase 8 — Cleanup (**BLOQUEADA**)

**No activar** `OTP_P0A_CLEANUP_ENABLED` en este bloque P0-F1.

Bloqueada hasta completar **todos** estos ítems (ver también [`p0-c2-otp-pruning-maintenance.md`](./p0-c2-otp-pruning-maintenance.md)):

| # | Gate | Estado en P0-F1 |
|---|------|-----------------|
| C01 | Acceso Tinker SSH prod/staging según política | Pendiente ops |
| C02 | Dry-run global `php artisan akubica:prune-otp --dry-run` | **Pendiente** |
| C03 | Dry-run por tipo (`--type=...`) | Pendiente |
| C04 | Revisión de conteos / skipped | Pendiente |
| C05 | Revisión de logs (`akubica_otp_prune_*`) sin PII | Pendiente |
| C06 | Confirmación scheduler (solo tras enable) | N/A mientras OFF |
| C07 | Backup previo a primer `--force` | Pendiente |
| C08 | Aprobación explícita negocio + técnico | Pendiente |

Mientras tanto:

```env
OTP_P0A_CLEANUP_ENABLED=false
```

Verificar tras cada deploy: `php artisan schedule:list` **no** lista prune activo (o confirmación de que el flag OFF no registra el schedule).

---

## 12. Comandos de cambio de flags (Forge / SSH)

Procedimiento estándar Laravel Forge para **cualquier** cambio de fase:

1. En el panel Forge (o secrets del entorno): editar solo las variables de la fase.
2. SSH al servidor de la release actual (o “Run Command” Forge):

```bash
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
php artisan schedule:list
```

3. Smoke test de la fase (sin volcar secretos).
4. Registrar evidencia §15.

### 12.1 Verificación rápida post-cambio (sanitizada)

```bash
php artisan tinker --execute="
echo 'app_env='.app()->environment().PHP_EOL;
echo 'config_cached='.json_encode(app()->configurationIsCached()).PHP_EOL;
\$c = config('otp.p0a');
\$f = \$c['flags'] ?? [];
echo 'driver='.(\$c['delivery']['driver'] ?? 'null').PHP_EOL;
echo 'infrastructure='.json_encode(\$f['infrastructure_enabled'] ?? null).PHP_EOL;
echo 'anti_abuse='.json_encode(\$f['anti_abuse_enabled'] ?? null).PHP_EOL;
echo 'sms_delivery='.json_encode(\$f['sms_delivery_enabled'] ?? null).PHP_EOL;
echo 'register='.json_encode(\$f['akubica_register_enabled'] ?? null).PHP_EOL;
echo 'login='.json_encode(\$f['akubica_login_enabled'] ?? null).PHP_EOL;
echo 'step_up_results='.json_encode(\$f['step_up_results_enabled'] ?? null).PHP_EOL;
echo 'step_up_invoices='.json_encode(\$f['step_up_invoices_enabled'] ?? null).PHP_EOL;
echo 'secure_links_results='.json_encode(\$f['secure_links_results_enabled'] ?? null).PHP_EOL;
echo 'secure_links_invoices='.json_encode(\$f['secure_links_invoices_enabled'] ?? null).PHP_EOL;
echo 'bearer_results='.json_encode(\$f['step_up_bearer_results_enabled'] ?? null).PHP_EOL;
echo 'bearer_invoices='.json_encode(\$f['step_up_bearer_invoices_enabled'] ?? null).PHP_EOL;
echo 'bearer_master='.json_encode(\$f['step_up_bearer_downloads_enabled'] ?? null).PHP_EOL;
echo 'sanctum_3h='.json_encode(\$f['sanctum_3h_enabled'] ?? null).PHP_EOL;
echo 'sanctum_ttl_min='.(\$c['sanctum']['target_expiration_minutes'] ?? '').PHP_EOL;
echo 'cleanup_enabled='.json_encode(\$c['cleanup']['enabled'] ?? null).PHP_EOL;
echo 'secure_ttl='.(\$c['secure_links']['ttl_minutes'] ?? '').PHP_EOL;
echo 'secure_max_opens='.(\$c['secure_links']['max_opens'] ?? '').PHP_EOL;
echo 'vonage_key_set='.(trim((string) config('vonage.api_key')) !== '' ? 'yes' : 'no').PHP_EOL;
echo 'vonage_secret_set='.(trim((string) config('vonage.api_secret')) !== '' ? 'yes' : 'no').PHP_EOL;
echo 'vonage_from_set='.(trim((string) config('vonage.sms_from')) !== '' ? 'yes' : 'no').PHP_EOL;
"
```

No imprimir valores de `VONAGE_*`, tokens ni teléfonos.

### 12.2 Postman Production

- Environment Production; secrets vacíos en archivos versionados.
- `allow_production_writes=false` por defecto.
- Solo elevar a `true` con autorización escrita de la ventana; bajar a `false` al terminar.

---

## 13. Monitoreo por fase

Umbrales orientativos; ajustar a baseline real de producción en la ventana.

| Métrica | Umbral (orientativo) | Acción |
|---------|----------------------|--------|
| HTTP 5xx (rutas `/api/v1`) | Cualquier aumento sostenido vs baseline (>5–10 min) | Rollback fase / código |
| HTTP 401 | Spike no explicado por tokens expirados de prueba | Investigar; pausar si auth rota |
| HTTP 403 step-up | Spike **fuera** de fases 5–6 o tras rollback incompleto | Pausar enforcement; avisar integradores |
| HTTP 409 | Spike registro (duplicados / conflictos) | Pausar Fase 2; investigar duplicados |
| HTTP 410 | Esperado en 2ª apertura secure link | Anomalía solo si 410 en 1ª apertura |
| HTTP 429 | Esperable bajo abuse; spike global no atribuible | Revisar anti-abuse / IP; no subir límites a ciegas |
| SMS accepted | Caída abrupta tras Fase 2+ | Rollback SMS / register-login |
| SMS failed | Tasa alta sostenida | Rollback SMS; revisar Vonage |
| SMS suppressed | Spike inesperado | Investigar config/driver |
| Latencia p95 auth/step-up | Degradación >2× baseline | Pausar fase actual |
| Errores Vonage | Códigos permanentes / auth provider | Apagar SMS; no reintentar a ciegas |
| Generación secure links | Fallos de emisión | Rollback secure links de ese recurso |
| Fallos storage PDF | Cualquier error de disco/S3 path | Rollback fase 3/4; no enforcement |
| Errores de queue | Jobs failed crecientes | `queue:restart`; pausar si no recupera |
| Duplicados usuario / intent | Cualquiera confirmado | Rollback inmediato Fase 2 |
| DB locks / timeouts | Sostenidos | Pausar; rollback si post-migrate |

---

## 14. Criterios de rollback inmediato

Ejecutar rollback de la **fase activa** (y anteriores si es necesario) sin esperar fin de ventana si:

| Condición | Acción típica |
|-----------|---------------|
| Aumento sostenido de 5xx | Rollback release o flags de la fase |
| OTP no entregado (Vonage/SMS) | Apagar SMS + register/login (Fase 2) |
| Duplicación de usuarios | Apagar register; investigar datos |
| Ownership incorrecto | Apagar step-up/secure/enforcement afectados |
| Descarga de recurso ajeno | **Emergencia:** apagar secure links + step-up + enforcement; escalar seguridad |
| Grants aceptados cross-purpose | Apagar enforcement; investigar bind purpose |
| Secure links reutilizables fuera de política | Apagar secure links; revisar TTL/max_opens |
| Queue acumulada sin drenaje | Pausar activaciones; recuperar workers |
| Error de storage | Apagar emisión secure links del recurso |
| Error de migración | Detener rollout; no seguir fases; plan restore/fix |

Orden preferente de mitigación: **flags OFF (§12)** → si no basta, **rollback de release** → restore DB solo con aprobación explícita.

---

## 15. Evidencia por fase (plantilla)

Completar una fila por fase. **Prohibido:** PII, secretos, OTP, tokens, grants, secure URLs.

| Fase | Fecha | Commit | Flags (solo nombres=on/off) | Smoke tests | Métricas | Resultado | Responsable |
|------|-------|--------|------------------------------|-------------|----------|-----------|-------------|
| 1 Deploy OFF | | | | | | PASS / FAIL / PAUSA | |
| 2 Register/Login SMS | | | | | | | |
| 3 Results step-up+links | | | | | | | |
| 4 Invoices step-up+links | | | | | | | |
| 5 Bearer results | | | | | | | |
| 6 Bearer invoices | | | | | | | |
| 7 Sanctum 3h | | | | | | | |
| 8 Cleanup | — | — | `CLEANUP=false` | **NO EJECUTAR** | — | BLOQUEADA | |

---

## 16. Go / No-Go

### 16.1 Decisión por fase

| Veredicto | Significado |
|-----------|-------------|
| **GO** | Avanzar a la siguiente fase |
| **GO CON OBSERVACIONES** | Avanzar con seguimiento explícito de ítems no bloqueantes |
| **NO-GO** | No avanzar; corregir o esperar |
| **ROLLBACK** | Revertir fase activa según §5–10 / §14 |

### 16.2 Clasificación de hallazgos

| Tipo | Ejemplos | Efecto |
|------|----------|--------|
| **Bloqueo técnico** | 5xx, migración fallida, storage down, SMS systematic fail, ownership roto, cross-purpose grant | NO-GO o ROLLBACK |
| **Bloqueo operativo** | Sin responsable, sin backup, integradores no avisados, Postman Production con writes sin autorización, cleanup ON por error | NO-GO |
| **Observación no bloqueante** | Latencia leve, 429 en prueba de abuse, doc tinker staging pendiente, dry-run prune pendiente (solo bloquea Fase 8) | GO CON OBSERVACIONES |

### 16.3 Checklist Go/No-Go global (inicio de rollout)

| # | Ítem | Tipo si falla |
|---|------|---------------|
| G01 | Commit/release aprobado + suite verde | Técnico |
| G02 | Preflight §1 completo | Operativo |
| G03 | Production aún no activó flags de comportamiento | Operativo |
| G04 | Cleanup OFF; dry-run pendiente reconocido | Operativo (Fase 8) |
| G05 | Vonage “set” sin exponer secretos | Técnico |
| G06 | Plan de comunicación a integradores (sobre todo Fases 5–6) | Operativo |
| G07 | Rollback de código y de flags ensayado en procedimiento | Operativo |
| G08 | QA staging P0-E2 aceptado | Técnico/negocio |

---

## 17. Mapa rápido flags × fase

| Flag | F1 | F2 | F3 | F4 | F5 | F6 | F7 | F8 |
|------|----|----|----|----|----|----|----|----|
| `INFRASTRUCTURE` | on | on | on | on | on | on | on | on |
| `ANTI_ABUSE` | off | **on** | on | on | on | on | on | on |
| `REGISTER` | off | **on** | on | on | on | on | on | on |
| `LOGIN` | off | **on** | on | on | on | on | on | on |
| `SMS_DELIVERY` | off | **on** | on | on | on | on | on | on |
| `DELIVERY_DRIVER=vonage` | prep | on | on | on | on | on | on | on |
| `STEP_UP_RESULTS` | off | off | **on** | on | on | on | on | on |
| `SECURE_LINKS_RESULTS` | off | off | **on** | on | on | on | on | on |
| `STEP_UP_INVOICES` | off | off | off | **on** | on | on | on | on |
| `SECURE_LINKS_INVOICES` | off | off | off | **on** | on | on | on | on |
| `BEARER_RESULTS` | off | off | off | off | **on** | on | on | on |
| `BEARER_INVOICES` | off | off | off | off | off | **on** | on | on |
| `BEARER_DOWNLOADS` (master) | off | off | off | off | off | off | off | off |
| `SANCTUM_3H` | off | off | off | off | off | off | **on** | on |
| `CLEANUP` | off | off | off | off | off | off | off | **bloqueado** |

---

## 18. Referencias

- [`api-v1-feature-flags.md`](./api-v1-feature-flags.md)
- [`p0-e1-qa-staging-production-readiness.md`](./p0-e1-qa-staging-production-readiness.md) §16–17
- [`p0-e2-staging-qa-evidence.md`](./p0-e2-staging-qa-evidence.md)
- [`p0-b4-bearer-step-up-enforcement.md`](./p0-b4-bearer-step-up-enforcement.md)
- [`p0-c1-sanctum-token-expiration.md`](./p0-c1-sanctum-token-expiration.md)
- [`p0-c2-otp-pruning-maintenance.md`](./p0-c2-otp-pruning-maintenance.md)
- [`postman/README.md`](../../postman/README.md) — Production guard

---

**Documento P0-F1:** runbook listo para ejecución operativa.
**No implica** autorización automática de deploy ni de Fase 8.
