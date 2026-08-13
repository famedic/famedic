# EUL-D08 — Regresión automatizada reproducible API V1

## Estado

**APROBADO**

## Identidad de la ejecución

| Campo | Valor |
|---|---|
| Rama | `feature/apis-akubica-billing-audit` |
| Commit | `d7e61b49f2458213f80b6346a323a6b3aa54189f` |
| Release de staging relacionada | `75167493` |
| Inicio UTC | `2026-08-11T15:25:30.925349539Z` |
| Fin UTC | `2026-08-11T15:26:05.530749875Z` |
| PHP | `8.3.31` |
| Laravel | `11.46.1` |
| Pest | `2.36.0` |
| PHPUnit | `10.5.36` |
| Imagen | `famedic-app:latest` |
| Image ID | `sha256:96f7eecace3956ce8f977bee3d24ef3343e9992dadf8d76939467a498b4edc0d` |
| OpenAPI relacionado | `3.1.0`, `info.version: 1.2.1`, 61 operaciones |

La release se registra como referencia de staging previamente comprobada. Esta corrida valida el código aislado del mismo SHA; no demuestra por sí sola el proceso FPM ni los workers efectivos de staging.

## Alcance

- 35 archivos Feature de API V1.
- 10 archivos Unit de OTP.
- 45 archivos de prueba en total, ordenados determinísticamente para la ejecución.
- 740 declaraciones de prueba contadas estáticamente.
- 755 casos descubiertos oficialmente por Pest/PHPUnit.
- Se incluyeron payment-link, appointments y appointment-concierge para preservar la integridad de la regresión completa, aunque están fuera del alcance inicial de Leo.

El conteo de 740 declaraciones es estático y no equivale al número de casos ejecutados; datasets y expansiones del framework produjeron 755 casos descubiertos.

## Resultado

| Métrica | Resultado |
|---|---:|
| Total | 755 |
| Passed | 755 |
| Failed | 0 |
| Errors | 0 |
| Skipped/incomplete | 0 |
| Duración JUnit | 33.761796 segundos |
| Exit code | 0 |
| OOMKilled | `false` |

El JUnit fue analizado como XML válido con raíz `testsuites`. Los 755 casos finalizaron satisfactoriamente.

## Aislamiento

- Ambiente explícito `testing`.
- Base SQLite temporal y aislada dentro del sandbox de la corrida.
- Contenedor con `NetworkMode=none`; sin acceso a MySQL, staging o producción.
- Queue configurada con driver `null`.
- Scout configurado con driver `null`.
- Entrega OTP fake y SMS deshabilitado.
- PHP CLI con `memory_limit=1G`.
- Límite del contenedor: 2 GiB de RAM.
- Límite total RAM+swap: 3 GiB, equivalente a hasta 1 GiB adicional de swap.
- Límite de CPU: 4 CPU.
- El checkout original no fue montado en el contenedor.

## Comando lógico reproducible

```bash
/usr/local/bin/php -d memory_limit=1G vendor/bin/pest \
  tests/Feature/Api/V1 tests/Unit/Otp \
  --colors=never \
  --display-incomplete \
  --display-skipped \
  --do-not-cache-result \
  --log-junit <ruta-privada-junit>
```

La ejecución real recibió una lista determinista, ordenada lexicográficamente, de los 45 archivos PHP de ambos directorios. No se utilizó paralelismo.

## Intento anterior

Un intento previo con `memory_limit=128M` terminó con agotamiento de memoria PHP y JUnit vacío después de una ejecución parcial. No se contabiliza como regresión funcional. La segunda corrida utilizó un límite controlado de 1 GiB y concluyó con exit code 0, sin OOM.

## Limitaciones

- Queue `null` no valida ejecución, handlers ni retry de jobs.
- Scout `null` no valida Algolia.
- `NetworkMode=none` impide y, por tanto, no valida integraciones externas.
- La corrida valida el backend aislado del commit indicado, no el proceso FPM ni los workers efectivos de staging.
- Las advertencias derivadas de la ausencia deliberada de `.env` no constituyeron fallos de prueba.
- Los defaults versionados y este resultado aislado no demuestran la configuración runtime efectiva de staging.

## Integridad y custodia

| Artefacto privado | SHA-256 |
|---|---|
| Log crudo | `ccacdd3da65353efd772b6ace7a85532469a248fc62b42b919afc44487e10e8f` |
| JUnit crudo | `3fa7884b31e72e2acce48692c236768214574e76f789e19139d7b154d045708b` |

El log y el JUnit permanecen bajo custodia interna y no forman parte de este reporte. La revisión de sensibilidad fue satisfactoria para esta publicación: no se trasladaron payloads, datos de factories, credenciales, tokens, OTP, cookies, DSN, correos, teléfonos, URLs firmadas, rutas personales ni PII.

El SHA-256 de este reporte se conserva únicamente en el manifiesto privado `eul-d08-api-v1-d7e61b4-20260811T091931-0600.internal.sha256`. No se incrusta aquí para evitar una autorreferencia inconsistente.
