# IT-0061 — preparación local R04 y R01/R01b

Fecha: 2026-11-24. Sin acceso QA, sin credenciales remotas y sin deploy.

## Cambios

- `akubica:uat-fixtures-r04r05 --family-only` prepara/restablece sólo
  `FamilyAccount`; nunca crea ni elimina `LaboratoryAppointment`.
- `akubica:uat-fixtures-orders` añade preparación namespace-scoped para dos
  `LaboratoryPurchase`: `own` y `foreign`, con claves naturales deterministas,
  customer distinto, marcador dedicado, apply idempotente y reset por
  ownership. No crea items, facturas, pagos, compras ni llamadas externas.
- El namespace admitido para pedidos es exclusivamente
  `akubica-uat-batch2-a4-v1`; legacy se rechaza.

## Validación MySQL/Laravel

Arnés: MySQL sintético `127.0.0.1:13307`, esquema preparado por el bootstrap
RC2 y dependencias aisladas en el volumen Docker `it0061-b18-vendor`.

Comando focalizado:

```text
vendor/bin/pest --configuration=phpunit.rc2.mysql.window2.xml \
  tests/Feature/Console/AkubicaUatR04R05FixturesCommandTest.php \
  tests/Feature/Console/AkubicaUatOrdersFixturesCommandTest.php
```

Resultado: `exit 0`, 43 assertions; 1 PASS explícito y 8 warnings del arnés
por `file_get_contents` conocido. No hubo FAIL de negocio. La prueba de
endpoint R01 ejecutada de forma focalizada terminó `1 passed / 5 assertions`:
el titular ve su pedido y el pedido ajeno queda soft-denied (`403/404`).

Las comprobaciones cubren dry-run, apply idempotente, colisión de namespace,
ownership, reset, preservación de recursos no marcados, R04 family-only sin
citas y conservación de legacy. Custody/runner no emiten credenciales en esta
preparación; su integración QA queda para una ventana posterior.

## Estado de alcance

| Caso | Estado local | Preparación | Cleanup |
|---|---|---|---|
| R04 | READY_LOCAL | `--family-only`, familiares propios por customer | reset por namespace/marcador |
| R01 | READY_LOCAL | pedido propio dedicado, ownership del titular | reset por clave natural/ID |
| R01b | READY_LOCAL | pedido ajeno dedicado, customer distinto | mismo reset; lectura soft-denied |
| R05 | NO RUN | no incluido; el modo family-only evita crear citas | sin recursos R05 |

La ejecución QA requiere publicar/desplegar este candidato y generar un plan
con IDs del manifest/inventory, no IDs locales. B24/A4, OTP/step-up, ATIX,
R05 y R08 permanecen en la lista única de intervención externa ya documentada;
R08 no se marca fuera de alcance sin decisión aprobada.

Batch 2 continúa abierto y los PASS QA anteriores se conservan sin repetir.
