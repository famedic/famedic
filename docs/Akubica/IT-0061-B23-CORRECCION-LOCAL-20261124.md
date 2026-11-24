# IT-0061 — corrección local de B23

## Resultado

Rama: `it0061/fix-b23-native-preparation`
Candidato local: `86f5beb13ee5f7e89db48d5c347324f0383e26e4`
Base local: `339a1cd2281916fe8f281eef34895b7dd31ef3ab`
Contrato: rev7 conservada; no se modifican A4, OpenAPI, roles globales, perfiles Leo ni guardas.

La corrección añade `checkout:prepare` únicamente a la capacidad declarada para `native_membership_none` en `functional-window-2`. El aprovisionador propaga esa misma lista al PAT, la delegación y el binding. No se adopta ningún recurso ni se amplía un permiso global.

El FAIL QA histórico de B23 (403) se conserva como histórico. La evidencia anterior no contenía un `error.code` ni un correlation ID correlacionable; no se inventan esos campos.

## Validación local

Arnés: MySQL sintético aislado en `127.0.0.1:13307`, base exclusiva de IT-0061, esquema preparado una vez por `tests/bootstrap-rc2-materializer.php` y configuración `phpunit.rc2.mysql.window2.xml`. Dependencias instaladas en el worktree de validación, sin usar QA ni credenciales remotas.

Comando:

```text
php vendor/bin/pest --configuration=phpunit.rc2.mysql.window2.xml tests/Feature/Console/AkubicaUatWindow2AccessTest.php --display-warnings --colors=never
```

Resultado real: `2 passed, 26 assertions, exit 0`.

Las comprobaciones cubren:

- apply y repetición idempotente del acceso; la segunda aplicación no crea PAT adicionales;
- propagación de `checkout:prepare` al escenario, delegación y binding;
- GET Laravel `/api/v1/checkout/prepare?service_line=medical_services` con titular y carrito sintéticos dedicados: respuesta contractual 200;
- retirada de la capacidad sólo del escenario y del binding: rechazo 403 `CONSUMER_NOT_AUTHORIZED`;
- reset en `finally`, custodia cifrada en estado local, revocación y ausencia de residuos del namespace.

El header `X-Synthetic-Machine-Credential` sólo lo usa el transporte de pruebas de Laravel (`LocalMachineCredentialSource`). No es un cambio de runtime ni una simulación HTTP: la aserción pasa por el kernel Laravel, middleware, Sanctum, delegación, binding y controlador reales. La primera corrida con el transporte productivo fue rechazada correctamente por el arnés; se corrigió el fixture para usar su interfaz de prueba soportada.

## Procedimiento postdeploy B23 únicamente

1. Confirmar release, SHA completo mediante `current`, `REVISION`, registro Forge e `inventory action=identity`; detenerse ante discrepancia.
2. Confirmar staging, host HTTPS autorizado, flags financieras bloqueadas, operador/Gate, TTL y ausencia de accesos residuales.
3. Generar un plan nuevo desde identity e inventario del release; seleccionar sólo B23 y registrar antes de emitir acceso: alias, consumer, titular, customer, delegación, binding, ownership, marca `olab`, IDs y propósito.
4. Ejecutar el dry-run del aprovisionador namespace-scoped. Aplicar sólo con los mecanismos autorizados y custodiar secretos en el canal privado existente; nunca en argumentos, logs o evidencias.
5. Ejecutar exactamente `GET /api/v1/checkout/prepare?service_line=medical_services` con `native_membership_none`; exigir la respuesta contractual completa, no sólo el status HTTP. Ejecutar los negativos de capacidad ausente, titular/ownership incompatible y marca ajena sin modificar identidades existentes.
6. Revocar/resetear en `finally`; verificar por IDs que no quedan PAT, bindings, delegaciones, credenciales ni estado activo de la ventana. Marcar `completed` sólo después de la conciliación independiente y conservar los recursos ajenos.
7. Registrar respuesta sanitizada, assertion, código de salida, correlation ID sólo si aparece realmente, inventario antes/después y checksums. Esto no certifica Batch 2.

## Pendientes únicos de Batch 2

| Caso/bloque | Evidencia vigente | Bloqueo exacto | Responsable / siguiente acción |
|---|---|---|---|
| B23 | FAIL QA histórico; corrección validada sólo localmente | Retest QA del perfil corregido aún no ejecutado | QA: ejecutar únicamente B23 con el procedimiento anterior |
| B09, B14, B26 | PASS_QA conservados | Ninguno dentro de su alcance acreditado | Mantener evidencia; no repetir |
| Nueve PAT históricos | `LIMITED` | No existe trazabilidad individual completa de aquella corrida | Control: conservar la limitación; no adoptar ni borrar PAT ajenos |
| Sesión first-party / B24 | Pendiente | Falta sesión first-party autorizada y sus prerrequisitos | Leobardo/QA: agrupar autorización y ventana |
| OTP / step-up | Pendiente | Requiere titulares, OTP y custodia operativa | Operador QA: coordinar una sola intervención |
| ATIX / R08 | Pendiente | Integración externa y condiciones de entrada no autorizadas en esta etapa | Responsable de integración: definir ventana y pruebas |
| R05 | Excluido de la ventana funcional | Su preparación no está autorizada para ejecución | Control: mantener excluido |

No se ejecutaron QA, credenciales remotas, pagos, OTP, SMS, correo, ATIX, GDA, migraciones ni deploy en esta corrección. Fixtures base, manifests y `akubica-uat-v1` permanecen fuera del cambio.
