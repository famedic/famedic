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

## Intervenciones externas agrupadas

| Bloque | Escenarios que desbloquea | Preparación que puede dejar lista Codex | Intervención de operador/tercero y canal seguro | Efectos previstos y autorización pendiente | Criterio de cierre y cleanup |
|---|---|---|---|---|---|
| B24 / A4 first-party | B24 y variantes A4 que consumen continuidad con sesión del titular | Plan, assertions, inventario namespace-scoped, precheck de SHA/host/flags y pruebas locales; no crea una sesión real ni simula autenticación | QA/Identidad debe habilitar únicamente la bandera ya documentada (`AKUBICA_B24_MEDICAL_CONTINUITY_ENABLED` cuando aplique), recargar configuración y hacer login del titular dedicado mediante el IdP/Forge; intercambio por canal seguro, nunca por chat | Sesión first-party, token/continuidad y auditoría; falta autorización operativa para sesión real y cualquier step-up | HTTP contractual con sesión real, TTL/uso único verificados, inventario conciliado, revocación/reset y ausencia de residuos |
| OTP / step-up | Escenarios que requieren OTP del titular, incluidas variantes B24/A4 | Casos negativos, plan, assertions, fixtures sintéticos y limpieza local; no emite OTP | Titular/QA debe proporcionar el canal controlado y ejecutar el OTP a través del mecanismo seguro existente; no compartir código ni secreto en chat | SMS/OTP y registros de auditoría; falta autorización explícita de la ventana OTP | OTP aceptado/rechazado según contrato, expiración o revocación diferenciada, auditoría sanitizada y cleanup |
| ATIX | Escenarios cuya regla de negocio llama ATIX | Fake local, contratos, timeouts, correlación y cleanup; no llama al proveedor | ATIX/Integraciones debe entregar sandbox, allowlist, credenciales y firma mediante vault/Forge, con ventana aprobada | Llamadas externas de sandbox y posibles registros del proveedor; falta autorización de integración | Correlación y respuesta contractual, idempotencia, ausencia de secretos en evidencia y revocación de accesos de integración |
| R05 | R05 y sólo los subcasos de citas expresamente autorizados | R04 family-only, selección explícita y guardas que impiden crear citas; no ejecuta R05 | QA/Product debe confirmar alcance de R05 y proporcionar el fixture/permiso de cita mediante el procedimiento soportado; no reutilizar el modo familiar | Creación/lectura/eliminación de cita; falta autorización del escenario R05 y sus datos dedicados | Assertion de R05, ownership/procedencia verificados, eliminación sólo de IDs creados y conciliación |
| R08 | R08, pendiente de decisión de alcance | Plan local, matriz, negativos y validación de selección; no se clasifica como excluido | Producto/QA debe fijar por canal de control el alcance aprobado, perfil, sesión/canal y retención de datos | Registros de conversación o flujo R08 según la decisión; falta decisión formal de alcance y autorización de datos | Decisión registrada, assertions aprobadas, evidencia sanitizada y cleanup/retención conforme a esa decisión |

Estas intervenciones son agrupadas: no se solicitan secretos por chat y ninguna
se ejecuta en esta entrega. La preparación local de R04/R01/R01b no crea citas,
pagos, facturas, compras ni llamadas a proveedores.
