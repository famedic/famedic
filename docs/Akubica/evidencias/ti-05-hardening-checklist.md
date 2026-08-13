# TI-05 — Hardening checklist

Contexto: FAMEDIC → Akúbica · Asistente virtual Leo

- Ambiente: staging
- Base URL: `https://famedic-otp.on-forge.com`
- Release Forge: `75167493`
- SHA desplegado: `d7e61b49f2458213f80b6346a323a6b3aa54189f`
- Fecha UTC-06:00: `2026-08-13T10:18:29-0600`

## Checklist

| Control | Clasificación | Evidencia/observación |
|---|---|---|
| APP_DEBUG off | PASS | AFTER: `app.debug=false`. |
| Secure links 60/3 | PASS | AFTER: TTL `60`, max opens `3`. |
| Secure links results enabled | PASS | AFTER: `secure_links_results_enabled=true`. |
| Secure links invoices enabled | PASS | AFTER: `secure_links_invoices_enabled=true`. |
| Step-up results | PASS | AFTER: `step_up_results_enabled=true`. |
| Step-up invoices | PASS | AFTER: `step_up_invoices_enabled=true`. |
| Invoice enforcement | PASS | AFTER: `step_up_bearer_invoices_enabled=true`; master remains `false`, sin ampliar alcance global. |
| Idempotency enabled | PASS | AFTER: `api_v1.idempotency.enabled=true`. |
| Idempotency prune programado | PASS | AFTER: prune enabled y `akubica:prune-idempotency` aparece programado. |
| Bearer TTL 180 | PASS | AFTER: bearer 3h enabled y target expiration `180`. |
| Queue database | PASS | AFTER: `queue.default=database`. |
| Cache database | PASS | AFTER: `cache.default=database`. |
| HTTPS | PASS | HTTPS responde correctamente. |
| HTTP→HTTPS | PASS | HTTP redirige a HTTPS. |
| TLS 1.2/1.3 | PASS CON OBSERVACIÓN | TLS 1.2 y 1.3 negociaron; mínimo exacto no quedó probado completamente. |
| HSTS | PENDIENTE P1 | `Strict-Transport-Security` no observado; validar en la capa TLS/proxy correspondiente. |
| CORS | PENDIENTE P1 | CORS sigue amplio/wildcard; no existe allowlist aprobada. No cambiar en TI-05. |
| FPM | NO VERIFICADO NO BLOQUEANTE | No se verificó configuración FPM; no bloquea TI-05. |
| Workers | NO VERIFICADO NO BLOQUEANTE | No se verificaron workers; no bloquea TI-05. |

## CORS

Estado: PENDIENTE P1.

Razón: staging responde CORS amplio/wildcard, pero no existe allowlist aprobada.

Decisión: NO CAMBIAR en TI-05 para evitar inventar dominios o romper integración.

## HSTS

Estado: PENDIENTE P1.

Observación: `Strict-Transport-Security` no fue observado.

Acción: validar en la capa TLS/proxy correspondiente. No inventar configuración.

## FPM / workers

Estado: NO VERIFICADO NO BLOQUEANTE.

No se afirma configuración FPM/workers no observada.

## TLS

| Control | Resultado |
|---|---|
| HTTPS | PASS |
| HTTP→HTTPS | PASS |
| TLS 1.2 | PASS observado |
| TLS 1.3 | PASS observado |
| Mínimo exacto | No completamente verificado |

Clasificación: PASS CON OBSERVACIÓN.

## Cierre

TI-05 puede cerrarse porque los controles P0 quedaron en PASS:

- `APP_DEBUG=false`
- secure links `60/3`
- secure links results/invoices enabled
- step-up invoices enabled/enforced
- idempotency enabled
- prune enabled y programado

CORS, HSTS, FPM y workers quedan documentados como P1/no verificados no bloqueantes.

## Sanitización

Este documento no contiene `.env`, secretos, credenciales, OTP, bearer tokens, cookies, DSN, URLs firmadas completas ni PII.
