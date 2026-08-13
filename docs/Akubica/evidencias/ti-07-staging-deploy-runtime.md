# TI-07 - Deploy unico staging y verificacion runtime

- ID: TI-07
- Ambiente: staging
- Base URL operativa: https://famedic-otp.on-forge.com
- Release Forge: 75401580
- SHA desplegado: 0b46a096a3902630e6ae0e3088c266c790d31b0f
- Branch: feature/apis-akubica-billing-audit
- Commit: docs: include Akubica baseline regression evidence
- Fecha: 2026-08-13 12:28:30 UTC-06:00

## Procedimiento

Se realizo un unico deploy de cierre a staging. Forge reporto `Deployment complete` y la identidad efectiva del release activo se verifico directamente por SSH.

La ruta `current` apunto a:

```text
/home/forge/famedic-otp.on-forge.com/releases/75401580
```

`git rev-parse HEAD` confirmo el SHA exacto:

```text
0b46a096a3902630e6ae0e3088c266c790d31b0f
```

No se requirio un segundo deploy. El valor `commit N/A` mostrado por la UI del build no se uso como identidad autoritativa; la evidencia autoritativa es el release activo verificado por SSH.

## Migracion

| Control | Expected | Observed | Resultado |
| --- | --- | --- | --- |
| Migration | `2026_08_11_120000_create_akubica_uat_fixture_manifests_table` | `2026_08_11_120000_create_akubica_uat_fixture_manifests_table` | PASS |
| Estado | `Ran` | `Ran` | PASS |

## Configuracion post-deploy

| Control | Expected | Observed | Resultado |
| --- | --- | --- | --- |
| `app.env` | `staging` | `staging` | PASS |
| `app.debug` | `false` | `false` | PASS |
| `otp.p0a.flags.secure_links_results_enabled` | `true` | `true` | PASS |
| `otp.p0a.flags.secure_links_invoices_enabled` | `true` | `true` | PASS |
| `otp.p0a.secure_links.ttl_minutes` | `60` | `60` | PASS |
| `otp.p0a.secure_links.max_opens` | `3` | `3` | PASS |
| `otp.p0a.flags.step_up_results_enabled` | `true` | `true` | PASS |
| `otp.p0a.flags.step_up_bearer_results_enabled` | `true` | `true` | PASS |
| `otp.p0a.flags.step_up_invoices_enabled` | `true` | `true` | PASS |
| `otp.p0a.flags.step_up_bearer_invoices_enabled` | `true` | `true` | PASS |
| `otp.p0a.flags.step_up_bearer_downloads_enabled` | `false` | `false` | PASS |
| `api_v1.idempotency.enabled` | `true` | `true` | PASS |
| `api_v1.idempotency.ttl_hours` | `24` | `24` | PASS |
| `api_v1.idempotency.processing_lease_seconds` | `60` | `60` | PASS |
| `api_v1.idempotency.prune.enabled` | `true` | `true` | PASS |
| `api_v1.idempotency.prune.schedule_time` | `04:00` | `04:00` | PASS |
| `queue.default` | `database` | `database` | PASS |
| `cache.default` | `database` | `database` | PASS |

Resultado: PASS. El hardening TI-05 sobrevivio al deploy.

## Scheduler

| Control | Expected | Observed | Resultado |
| --- | --- | --- | --- |
| OTP prune | `akubica:prune-otp` programado | `akubica:prune-otp` programado | PASS |
| Idempotency prune | `akubica:prune-idempotency` programado | `akubica:prune-idempotency` programado | PASS |

## Smoke runtime

| Request | Expected | Observed | Resultado |
| --- | --- | --- | --- |
| `GET https://famedic-otp.on-forge.com/up` | HTTP 200 | HTTP 200 | PASS |
| `GET /api/v1/catalog/laboratory-brands` | HTTP 200, JSON valido, `success=true` | HTTP 200, JSON valido, `success=true` | PASS |
| `GET /api/v1/catalog/laboratory-tests?brand=invalid-brand` | HTTP 422 | HTTP 422 | PASS |

Resultado esperado: 200 / 200 / 422.
Resultado observado: 200 / 200 / 422.

## Runtime

Valores no sensibles observados con `php artisan about`:

| Control | Observed | Resultado |
| --- | --- | --- |
| Application Name | `Famedic_Staging` | PASS |
| Laravel | `11.46.1` | PASS |
| PHP | `8.4.18` | PASS |
| Environment | `staging` | PASS |
| Debug Mode | `OFF` | PASS |
| Maintenance Mode | `OFF` | PASS |
| Timezone | `America/Mexico_City` | PASS |
| Config | `CACHED` | PASS |
| Events | `CACHED` | PASS |
| Routes | `CACHED` | PASS |
| Views | `CACHED` | PASS |
| Cache driver | `database` | PASS |
| Database driver | `mysql` | PASS |
| Queue driver | `database` | PASS |
| Session driver | `database` | PASS |

## Observaciones

### APP_URL

`php artisan about` mostro `URL: staging.famedic.com.mx`, mientras las evidencias TI usan `https://famedic-otp.on-forge.com`.

Clasificacion: PASS CON OBSERVACION / NO BLOQUEANTE.

El runtime sobre la URL operativa de staging respondio correctamente. Se conserva esta diferencia como observacion. No se realizo cambio de configuracion porque TI-07 no debe introducir configuracion adicional sin necesidad demostrada.

### Route sanity check

Se ejecuto:

```text
php artisan route:list --path=api/v1 | wc -l
```

Resultado observado: `65`.

Este valor sirve unicamente como sanity check de carga de rutas. No representa el numero contractual de operationIds. OpenAPI v1.2.3 sigue teniendo 61 operations / 61 operationIds segun TI-02/TI-03.

## Criterios de cierre

| Control | Resultado |
| --- | --- |
| Release nuevo activo | PASS |
| SHA candidato exacto | PASS |
| Branch correcto | PASS |
| Migracion aplicada | PASS |
| APP_DEBUG off | PASS |
| Secure links results/invoices enabled | PASS |
| Secure links 60/3 | PASS |
| Step-up invoices enabled/enforced | PASS |
| Idempotency enabled | PASS |
| Prune enabled/programado | PASS |
| `/up` 200 | PASS |
| Catalogo 200 | PASS |
| Validacion negativa 422 | PASS |
| Evidencia generada | PASS |
| Sanitizacion | PASS |

## Riesgos y observaciones residuales

- APP_URL observado diferente a la URL operativa usada en evidencias; no bloqueante.
- Route sanity check `65` es solo conteo de salida CLI y no se compara contra operationIds.
- CORS/HSTS/FPM/workers se mantienen como observaciones previas y no reabren TI-05.

## Resultado final

TI-07 LISTO PARA CIERRE.
