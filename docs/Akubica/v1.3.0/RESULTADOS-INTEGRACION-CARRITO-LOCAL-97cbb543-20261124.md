# IT-0061 — Integración local de carrito de membresía

Estado: VALIDADO_LOCALMENTE_CON_LIMITACION_DE_INTEGRACION
Base: `97cbb543cc722c4be1b6ef0ac03d2c43a39f2341`
Candidate final: `33ae9318e858d0e5f0b405e25f11062bf29c02a0`
Entorno: MySQL sintético dedicado en `127.0.0.1:13307`; no QA, Forge ni servicios externos.

## Correcciones

- `functional-window-2-memberships-cart-qa-plan.json` es ahora una plantilla operativa. El SHA se materializa exclusivamente desde un `release-source.json` con `status=verified|PASS` y `active_sha` de 40 hexadecimales.
- B12 y B13 reciben claves nuevas por ventana; B17 reutiliza exactamente la clave de B12. La salida generada rechaza archivos existentes y no deja marcadores de SHA o claves sin resolver.
- El runner reconoce `functional_case=true`. B16 sigue en la sección de cleanup, pero también aparece como resultado funcional con `phase=cleanup`; si no existe el recurso, queda `NOT_RUN`, nunca PASS.
- El arnés de membresías configura las siete identidades sintéticas y limpia únicamente las definiciones de storage de `akubica-uat-batch2-a4-v1` antes de aplicar. Esto resuelve `UAT_CONFIG_REQUIRED`/colisiones de storage del arnés sin cambiar las guardas del materializador ni depender de `akubica-uat-v1`.

## Recorrido validado

| Parte | Resultado | Evidencia |
|---|---|---|
| Generación de plan desde identidad verificada | PASS | 2 tests Python; SHA real y rechazo de fuente no verificada/ inválida |
| Runner: orden, assertions, timeout, interrupción, sanitización y cleanup fallido | PASS | 22 tests Python, código no cero ante assertion/cleanup fallido |
| Custody CLI real, escenario de membresías y scope ajeno | PASS | 1 test, 41 assertions |
| Inventory Laravel/MySQL de ítem médico | PASS | 3 tests, 12 assertions; registro/verificación/rechazo de ownership/unregister |
| Laravel/MySQL: acceso, checkout, B12, B12r, B13, B17, B16 | PASS | 1 test, 24 assertions; una fila, replay con el mismo ID y DELETE verificado |
| Variantes de membresía e idempotencia de acceso | PASS | 1 test, 13 assertions |

En el recorrido Laravel, B12 crea una línea, B12r devuelve su ID, B13 devuelve `409/MEMBERSHIP_ALREADY_IN_CART`, B17 devuelve el replay `201` con el mismo ID y `Idempotency-Replayed=true`, y B16 elimina la línea. La conciliación MySQL comprueba una sola fila antes del DELETE y ausencia posterior.

La prueba del runner usa HTTP controlado sintético y custodia sintética; las pruebas Laravel/MySQL usan kernel Laravel y base MySQL sintética. No se presenta el HTTP controlado como prueba de endpoint Laravel. La integración de ambos procesos en una única corrida QA sigue pendiente y requiere despliegue; no se ha accedido a QA.

## Comandos y resultados

```text
wsl.exe bash -lc 'python3 -m unittest scripts.tests.test_functional_window2_http scripts.tests.test_functional_window2_plan scripts.tests.test_functional_window2_membership_cart_plan -q'
Ran 22 tests ... OK

vendor/bin/pest --configuration=phpunit.rc2.mysql.window2.xml tests/Unit/FunctionalWindow2InventoryMysqlTest.php --colors=never
3 passed (12 assertions), exit 0

vendor/bin/pest --configuration=phpunit.rc2.mysql.window2.xml tests/Unit/FunctionalWindow2CustodyCommandTest.php --filter='membership scenario' --colors=never
1 passed (41 assertions), exit 0

vendor/bin/pest --configuration=phpunit.rc2.mysql.window2.xml tests/Feature/Console/AkubicaUatWindow2AccessTest.php --filter='native membership none reaches checkout prepare' --colors=never
1 passed (24 assertions), exit 0

vendor/bin/pest --configuration=phpunit.rc2.mysql.window2.xml tests/Feature/Console/AkubicaUatWindow2AccessTest.php --filter='membership variants are namespace-scoped' --colors=never
1 passed (13 assertions), exit 0
```

Una ejecución paralela de suites que comparten deliberadamente el mismo namespace produjo `UAT_RESET_EXTERNAL_REFERENCE`/colisiones; se conserva como diagnóstico del arnés y no como resultado funcional. La ruta válida es secuencial sobre la base sintética exclusiva.

## Limpieza y límites

Los tests ejecutados dejan acceso y fixtures sintéticos en estado reseteable; no modifican `akubica-uat-v1`, legacy ni QA. Las pruebas del runner incluyen timeout, interrupción y `CLEANUP_FAILED` no cero. La prueba Laravel ejecuta reset de acceso al finalizar. No se ejecutaron OTP, pagos, SMS, correo, ATIX, GDA ni proveedores.

Los PASS QA previos, los 98 fallos históricos, BLQ-A1-01, AJ-B y A12 permanecen sin reclasificar.
