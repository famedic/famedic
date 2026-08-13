# TI-05 — Snapshot before staging hardening

Contexto: FAMEDIC → Akúbica · Asistente virtual Leo

- Fecha UTC-06:00: 2026-08-12T16:44:11-0600
- Base URL staging: `https://famedic-otp.on-forge.com`
- Repo local: `/home/laliyo/projects/Odessa-Famedic/famedic`
- Rama local: `feature/apis-akubica-billing-audit`
- HEAD local: `51f3be0ff9a40ac522ac8763e2e4ffed14604aed`
- Release Forge efectivo: NO VERIFICADO
- SHA efectivo staging: NO VERIFICADO

Este snapshot contiene solo valores sanitizados. No se leyeron `.env`, secretos, tokens, OTP, credenciales, DSN, cookies, URLs firmadas ni PII. Los headers públicos fueron revisados filtrando `Set-Cookie`.

## Controles

| Control | Valor efectivo observado | Estado | Fuente/procedimiento | Brecha |
|---|---|---|---|---|
| APP_ENV | NO VERIFICADO | NO VERIFICADO | No hay acceso seguro a `app()->environment()` de staging; `/up` público muestra página con título de staging, pero no confirma `APP_ENV`. | Requiere mecanismo seguro/Forge en TI-05. |
| APP_DEBUG | NO VERIFICADO | NO VERIFICADO | 404 API público devuelve envelope normal sin stack trace; eso no prueba el valor efectivo. | Confirmar y apagar si está true. |
| Bearer TTL | NO VERIFICADO | NO VERIFICADO | Código: default P0-A target 180 min si flag 3h activo; Sanctum default 1440 min. No se verificó env efectivo. | Confirmar valor efectivo staging. |
| Secure links results | NO VERIFICADO | NO VERIFICADO | Código: flag `OTP_P0A_SECURE_LINKS_RESULTS_ENABLED`, default false. | Confirmar flag efectivo staging. |
| Secure links invoices | NO VERIFICADO | NO VERIFICADO | Código: flag `OTP_P0A_SECURE_LINKS_INVOICES_ENABLED`, default false. | Confirmar flag efectivo staging. |
| Secure link TTL | NO VERIFICADO | NO VERIFICADO | Código: `otp.p0a.secure_links.ttl_minutes`, default 60. | Confirmar efectivo; política aprobada 60. |
| Secure link max opens | NO VERIFICADO | NO VERIFICADO | Código: `otp.p0a.secure_links.max_opens`, default 5. | Política aprobada requiere 3 para ligas nuevas. |
| Step-up results | NO VERIFICADO | NO VERIFICADO | Código: flags de request/verify y enforcement Bearer; no se verificó env efectivo. | Confirmar enabled/enforced staging. |
| Step-up invoices | NO VERIFICADO | NO VERIFICADO | Código: controller invoice step-up consulta `AkubicaStepUpOtpService::isInvoicesEnabled()`; enforcement Bearer separado. | Confirmar enabled y enforced por separado. |
| Idempotency enabled | NO VERIFICADO | NO VERIFICADO | Código: `api_v1.idempotency.enabled`, default false. | Confirmar efectivo staging. |
| Idempotency TTL | NO VERIFICADO | NO VERIFICADO | Código: `api_v1.idempotency.ttl_hours`, default 24h. | Confirmar efectivo staging. |
| Idempotency lease | NO VERIFICADO | NO VERIFICADO | Código: `api_v1.idempotency.processing_lease_seconds`, default 60s. | Confirmar efectivo staging. |
| Idempotency prune | NO VERIFICADO | NO VERIFICADO | Command `akubica:prune-idempotency` existe; scheduler solo si `api_v1.idempotency.prune.enabled` true, default false. | Confirmar flag/schedule efectivo staging. |
| Queue driver | NO VERIFICADO | NO VERIFICADO | Código: `queue.default` usa env con default `database`; no se verificó env efectivo. | Confirmar en staging. |
| Cache driver | NO VERIFICADO | NO VERIFICADO | Código: `cache.default` usa env con default `database`; no se verificó env efectivo. | Confirmar en staging. |
| CORS origins | `*` observado | FAIL | Preflight público con origin `https://example.invalid` devuelve `access-control-allow-origin: *`. | CORS más amplio de lo necesario. |
| CORS methods | Refleja método solicitado (`GET`, `POST`, `DELETE`) | FAIL | Preflight público aceptó métodos solicitados sobre rutas API. | Requiere allowlist/métodos mínimos si se aprueba origen. |
| CORS headers | Refleja headers solicitados, incluido `X-Anything` | FAIL | Preflight público devolvió `access-control-allow-headers` con headers solicitados. | Headers más amplios de lo necesario. |
| CORS credentials | No se observó `access-control-allow-credentials` | PASS parcial | Preflight público filtrado. | Confirmar config efectiva si hay credenciales cross-origin requeridas. |
| HTTPS | Responde HTTP/2 200 | PASS | `curl -D - https://famedic-otp.on-forge.com/`, cookies filtradas. | Ninguna observada. |
| HTTP→HTTPS | HTTP 301 a HTTPS | PASS | `curl --max-redirs 0 http://famedic-otp.on-forge.com/`, cookies filtradas. | Ninguna observada. |
| HSTS | Header no observado | FAIL | Headers HTTPS públicos no incluyen `Strict-Transport-Security`. | Habilitar HSTS si aplica por proxy/CDN. |
| TLS | TLS 1.2 y TLS 1.3 verificados | PASS parcial | `openssl s_client -tls1_2` y `-tls1_3` negocian cipher; TLS mínimo exacto no concluyente localmente. | Confirmar mínimo exacto en Forge/CDN. |
| Proxy/trusted proxies | NO VERIFICADO | NO VERIFICADO | No se encontró configuración explícita de trusted proxies/HSTS en código revisado. | Confirmar headers scheme/host percibidos por app. |
| PHP/FPM | NO VERIFICADO | NO VERIFICADO | `php` no está disponible en PATH local WSL; sin acceso FPM staging. | Confirmar en Forge/host. |
| Queue workers | NO VERIFICADO | NO VERIFICADO | No hay procesos PHP/queue visibles en WSL local; no es staging. | Confirmar en Forge/host. |
| Config cache | NO VERIFICADO | NO VERIFICADO | Local `bootstrap/cache/config.php` no existe; staging no verificable sin acceso host. | Confirmar en staging antes de cambios. |

## Secure links 60/3

| Control | Resultado |
|---|---|
| TTL efectivo actual | NO VERIFICADO |
| Max opens efectivo actual | NO VERIFICADO |
| Flags results/invoices | NO VERIFICADO |
| Configuración en código | `config/otp.php` usa `OTP_P0A_SECURE_LINK_TTL_MINUTES` default 60 y `OTP_P0A_SECURE_LINK_MAX_OPENS` default 5 |
| Aplica a nuevas ligas | Por implementación, TTL/max opens se toman al emitir la liga nueva |
| Ligas existentes | No se verificó DB; por diseño, valores ya persistidos no deberían cambiar retroactivamente |
| Estado contra política 60/3 | FAIL/NO VERIFICADO: TTL no confirmado; max opens efectivo no confirmado y default de código es 5, no 3 |

## Step-up facturas

| Control | Resultado |
|---|---|
| Enabled efectivo | NO VERIFICADO |
| Enforced efectivo | NO VERIFICADO |
| Evidencia de código | `OrderInvoicesStepUpController` usa `AkubicaStepUpOtpService::isInvoicesEnabled()`; enforcement Bearer se controla con flags separados en `config/otp.php` |
| Contrato OpenAPI v1.2.3 | Incluye operaciones de step-up facturas |
| Resultado | NO VERIFICADO; no asumir equivalencia entre enabled y enforced |

## Idempotency prune

| Control | Resultado |
|---|---|
| Command/job | `akubica:prune-idempotency` existe |
| Qué elimina | Registros de `api_v1_idempotency_records` con `expires_at < now()` |
| Modo por defecto | Dry-run salvo `--force` |
| Scheduler | Existe condicional en `routes/console.php`, solo si `api_v1.idempotency.prune.enabled` es true |
| Estado efectivo | NO VERIFICADO |
| Riesgo | Si el flag/scheduler no está activo, registros expirados pueden acumularse |

## CORS

| Control | Resultado |
|---|---|
| Origins | `*` observado con origin inválido |
| Methods | Método solicitado reflejado/permitido en preflight observado |
| Headers | Headers solicitados reflejados, incluido header sintético no aprobado |
| Credentials | No observado `access-control-allow-credentials` |
| Resultado | FAIL: CORS amplio |
| Cambio mínimo propuesto | No inventar dominios; definir allowlist aprobada de orígenes Akúbica/Leo y limitar métodos/headers a los necesarios |

## Hardening mínimo propuesto

| Prioridad | Control | Estado actual | Cambio requerido | Bloquea TI-05 |
|---|---|---|---|---|
| P0 | APP_DEBUG | NO VERIFICADO | Confirmar efectivo y dejar OFF si está true | Sí |
| P0 | Secure link max opens | NO VERIFICADO; default código 5 | Configurar 3 para nuevas ligas si efectivo no es 3 | Sí |
| P0 | Secure link TTL | NO VERIFICADO; default código 60 | Mantener/confirmar 60 | Sí |
| P0 | Step-up invoices | NO VERIFICADO | Confirmar enabled y enforced según política aprobada | Sí |
| P0 | Idempotency prune | NO VERIFICADO | Confirmar/habilitar schedule efectivo si aprobado | Sí |
| P1 | CORS | FAIL amplio | Aplicar allowlist cuando exista origen aprobado | No si no hay allowlist |
| P1 | HSTS | FAIL no observado | Habilitar HSTS en proxy/CDN si compatible | No |
| P1 | TLS mínimo | PASS parcial | Confirmar mínimo exacto TLS 1.2+ en Forge/CDN | No |
| P1 | FPM/workers/config cache | NO VERIFICADO | Confirmar en Forge/host sin reinicio | No |
| NO CAMBIAR | CORS allowlist | Sin dominios aprobados | No inventar orígenes | No |
