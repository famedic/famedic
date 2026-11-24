# IT-0061 — retest local focalizado

Este informe complementa y no sustituye `RESULTADOS-DIAGNOSTICO-FUNCIONAL-WINDOW2-LOCAL.md`.
No contiene resultados de una nueva ventana QA.

## B19

El `403` QA no demostró un defecto de autorización del runtime. El plan operativo
serializó el payload bajo `body`; el runner sólo consume `json`, por lo que envió
un POST sin `service_line` ni `item_type`. `AuthorizeRegisteredConsumer` rechazó
la solicitud antes de `MedicalServiceCartService`, exactamente en la guarda de
capacidad/forma de operación, y nunca se alcanzó `MEMBERSHIP_ALREADY_ACTIVE`.

La corrección local hace que el runner rechace un plan que contenga `body` sin
`json` (`JSON_BODY_KEY_INVALID`) antes de abrir HTTP. El payload correcto conserva
la expectativa `409/MEMBERSHIP_ALREADY_ACTIVE`; la prueba Laravel/MySQL de
membresía activa pasa con esa expectativa.

## Carrito temporal

`akubica:rc2-price-fixtures --cart-window` extiende el preparador existente con
estudios B20/B21 bajo el prefijo de namespace `RC2-PRICE-FIX-<namespace>-`.
No adopta los cart items del manifest ni los fixtures legacy.

El inventory helper incorpora un ledger privado `window2-inventory.json` y acciones
`register`/`unregister`. Sólo acepta recursos creados por B06b/B20/B21, del
customer primario, marca `olab`, con el prefijo de namespace y procedencia
coincidente. La verificación de DELETE exige ese registro y vuelve a comprobar
ownership, tipo y propósito.

El runner puede registrar el ID devuelto por HTTP mediante
`register_after_success`; un ID ausente, ajeno o con SHA incompatible falla
cerrado. La limpieza elimina primero el recurso HTTP, verifica que ya no exista
como fila activa y después retira su entrada del ledger mediante `unregister`.

## Validación

- Runner Python: 19 tests, PASS; incluye rechazo del campo `body` no consumido.
- Inventory identity: 2 tests / 4 assertions, PASS.
- B19/B20/B21 aislamiento Laravel/MySQL: 3 tests / 31 assertions, PASS.
- A2 laboratorio y controles de capacidad/marca/ownership: 20 tests, PASS.
- Precio B03/B06 y cleanup aislado: 5 tests / 35 assertions, PASS.
- Membresía médica y rechazo `MEMBERSHIP_ALREADY_ACTIVE`: 5 tests / 34 assertions, PASS.

Las corridas PHPUnit muestran advertencias conocidas de `file_get_contents` del
arnés; no son fallos de assertions. El proceso WSL sin `pdo_mysql` no es una ruta
válida para esta suite; las corridas concluyentes usaron PHP Windows con
`127.0.0.1:13307`, MySQL sintético y el bootstrap de esquema único.

## Pendientes explícitos

La extensión requiere revisión, publicación y despliegue antes de poder usarse
en QA. No se hizo push, merge, deploy, acceso QA ni emisión remota de credenciales.
El retest QA mínimo será B19, B06b/B06r, B20/B21/B21r y los controles negativos
de capacidad, marca y titular ajenos, con un plan nuevo y cleanup por ledger.
