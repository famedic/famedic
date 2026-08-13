# TI-05 — Snapshot after staging hardening

Contexto: FAMEDIC → Akúbica · Asistente virtual Leo

- ID: TI-05
- Ambiente: staging
- Base URL: `https://famedic-otp.on-forge.com`
- Release Forge: `75167493`
- SHA desplegado: `d7e61b49f2458213f80b6346a323a6b3aa54189f`
- Ruta current: `/home/forge/famedic-otp.on-forge.com/current`
- Release físico: `/home/forge/famedic-otp.on-forge.com/releases/75167493`
- Fecha UTC-06:00: `2026-08-13T10:18:29-0600`

## Procedimiento

Snapshot AFTER confirmado por consola de staging mediante whitelist de valores `config(...)` no sensibles. No se leyó ni expuso `.env` completo, secretos, credenciales, OTP, bearer tokens, cookies, DSN, URLs firmadas completas ni PII.

## Config efectiva AFTER

| Control | Valor AFTER | Objetivo | Resultado |
|---|---|---|---|
| APP_ENV | `staging` | `staging` | PASS |
| APP_DEBUG | `false` | `false` | PASS |
| Bearer 3h enabled | `true` | `true` | PASS |
| Bearer TTL target | `180` | `180` | PASS |
| Sanctum expiration global | `1440` | Sin cambio requerido | PASS |
| Secure links results enabled | `true` | `true` | PASS |
| Secure links invoices enabled | `true` | `true` | PASS |
| Secure link TTL | `60` | `60` | PASS |
| Secure link max opens | `3` | `3` | PASS |
| Step-up results enabled | `true` | `true` | PASS |
| Step-up invoices enabled | `true` | `true` | PASS |
| Bearer enforcement results | `true` | `true` | PASS |
| Bearer enforcement invoices | `true` | `true` | PASS |
| Bearer enforcement master | `false` | `false` / no ampliar alcance | PASS |
| Idempotency enabled | `true` | `true` | PASS |
| Idempotency TTL hours | `24` | `24` | PASS |
| Idempotency lease seconds | `60` | `60` | PASS |
| Idempotency prune enabled | `true` | `true` | PASS |
| Queue default | `database` | `database` | PASS |
| Cache default | `database` | `database` | PASS |
| Scheduler prune OTP | programado | programado | PASS |
| Scheduler prune idempotency | programado | programado | PASS |

## Comparativo before/after

| Control | Before | After | Objetivo | Resultado |
|---|---|---|---|---|
| APP_ENV | `staging` | `staging` | `staging` | PASS |
| APP_DEBUG | `true` | `false` | `false` | PASS |
| Bearer 3h enabled | `true` | `true` | `true` | PASS |
| Bearer TTL target | `180` | `180` | `180` | PASS |
| Secure links results | `false` | `true` | `true` | PASS |
| Secure links invoices | `false` | `true` | `true` | PASS |
| Secure link TTL | `60` | `60` | `60` | PASS |
| Secure link max opens | `5` | `3` | `3` | PASS |
| Step-up results | `true` | `true` | `true` | PASS |
| Step-up bearer results enforcement | `true` | `true` | `true` | PASS |
| Step-up invoices | `false` | `true` | `true` | PASS |
| Step-up invoice enforcement | `true` | `true` | `true` | PASS |
| Step-up bearer downloads master | `false` | `false` | `false` / no ampliar alcance | PASS |
| Idempotency enabled | `true` | `true` | `true` | PASS |
| Idempotency TTL hours | `24` | `24` | `24` | PASS |
| Idempotency lease seconds | `60` | `60` | `60` | PASS |
| Idempotency prune | `false` | `true` | `true` | PASS |
| Queue default | `database` | `database` | `database` | PASS |
| Cache default | `database` | `database` | `database` | PASS |

## Resultado P0

| Control | Estado final | Resultado |
|---|---|---|
| APP_DEBUG off | `app.debug=false` | PASS |
| Secure links 60/3 | TTL `60`, max opens `3` | PASS |
| Secure links results enabled | `true` | PASS |
| Secure links invoices enabled | `true` | PASS |
| Step-up results | `true` | PASS |
| Step-up invoices | `true` | PASS |
| Invoice enforcement | `true` | PASS |
| Idempotency enabled | `true` | PASS |
| Idempotency prune enabled | `true` | PASS |
| Scheduler prune OTP | programado | PASS |
| Scheduler prune idempotency | programado | PASS |

## Observaciones no bloqueantes

| Control | Estado | Observación |
|---|---|---|
| CORS | PENDIENTE P1 | Staging responde CORS amplio/wildcard; no existe allowlist aprobada. No cambiar en TI-05 para evitar inventar dominios o romper integración. |
| HSTS | PENDIENTE P1 | `Strict-Transport-Security` no fue observado; validar en la capa TLS/proxy correspondiente. |
| TLS | PASS CON OBSERVACIÓN | HTTPS y HTTP→HTTPS pasan; TLS 1.2 y 1.3 observados; mínimo exacto no completamente verificado. |
| FPM | NO VERIFICADO NO BLOQUEANTE | No afirmar configuración no observada. |
| Workers | NO VERIFICADO NO BLOQUEANTE | No afirmar configuración no observada. |

## Sanitización

Este documento no contiene `.env`, secretos, credenciales, OTP, bearer tokens, cookies, DSN, URLs firmadas completas ni PII.
