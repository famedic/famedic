# TI-08 - Regresion final

## Identidad

- Staging release: `75401580`
- Staging SHA requerido: `0b46a096a3902630e6ae0e3088c266c790d31b0f`
- SHA probado: `0b46a096a3902630e6ae0e3088c266c790d31b0f`
- Rama de referencia: `feature/apis-akubica-billing-audit`
- Fecha: 2026-08-13

## Aislamiento

- Worktree aislado: `/tmp/famedic-akubica-ti08`
- Ejecucion en contenedor `famedic-app`
- Red del contenedor: `none`
- Limites del contenedor: 2 GiB RAM, 3 GiB RAM+swap, 4 CPU
- Base SQLite local temporal dentro del worktree aislado
- No se ejecutaron deploys, migraciones de staging, seeders de staging, commits ni push

## Comando ejecutado

```bash
/usr/local/bin/php -d memory_limit=1G vendor/bin/pest \
  tests/Feature/Api/V1 tests/Unit/Otp \
  --colors=never \
  --display-incomplete \
  --display-skipped \
  --do-not-cache-result \
  --log-junit /artifacts/ti08-final-regression-junit.xml
```

## Resultado

- Exit code: `0`
- Tests: `767`
- Passed: `767`
- Failed: `0`
- Errors: `0`
- Skipped: `0`
- Assertions: `3969`
- Duracion registrada: `33s`

## Cobertura TI-06/P0 incluida

| Area | Suite | Tests | Resultado |
|---|---:|---:|---|
| Retry/read-back P0 | `AkubicaRetryReadBackP0Test` | 2 | PASS |
| Secure links resultados | `AkubicaResultsSecureLinksP0b2Test` | 33 | PASS |
| Secure links facturas | `AkubicaInvoicesStepUpSecureLinksP0b3Test` | 21 | PASS |
| Step-up resultados | `AkubicaStepUpResultsP0b1Test` | 20 | PASS |
| Idempotencia API V1 | `AkubicaIdempotencyP1Test` | 32 | PASS |

## Comparacion baseline

| Ejecucion | SHA | Tests | Resultado |
|---|---|---:|---|
| Baseline historico API V1 | `d7e61b49f2458213f80b6346a323a6b3aa54189f` | 755 | PASS |
| TI-08 staging final | `0b46a096a3902630e6ae0e3088c266c790d31b0f` | 767 | PASS |

El SHA baseline `d7e61b49f2458213f80b6346a323a6b3aa54189f` es ancestro local del SHA probado.

## Artefactos

| Artefacto | Ubicacion | SHA-256 |
|---|---|---|
| Log final | `docs/Akubica/evidencias/ti-08-final-regression-output.txt` | `9f3609fbb36505b2d0322702f44a73f5a1dc4b80f9b549158f99c3c136ed5e13` |
| JUnit privado | `/tmp/ti08-artifacts/ti08-final-regression-junit.xml` | `a0a6d8ac198ab45b2a5ab3d6d81cdbbf3d5c566610dabc20a88e77ce5d5c1ffd` |

El JUnit se conserva fuera del repositorio y no se incorpora como archivo adicional de PR.

## Sanitizacion

La evidencia publicada no contiene credenciales, tokens reales, OTP reales, cookies, DSN, `.env` real, URLs firmadas reales, correos reales, telefonos reales ni PII real. Las menciones a email, telefono, OTP y Bearer corresponden a nombres de pruebas o datos sinteticos de la suite.

## Limitaciones

- La corrida valida el backend aislado del SHA indicado; no valida FPM, workers ni configuracion runtime efectiva de staging.
- La red del contenedor estuvo deshabilitada, por lo que no valida integraciones externas reales.
- La base de datos fue SQLite local temporal, no staging.

## Veredicto

TI-08 LISTO PARA CIERRE
