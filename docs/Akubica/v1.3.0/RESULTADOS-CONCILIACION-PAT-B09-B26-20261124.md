# IT-0061 — conciliación de seguridad y preparación B09/B14/B23/B26

Fecha: 2026-11-24. Esta evidencia no repite PASS QA acreditados y no emite
accesos nuevos.

## Referencia

- Release/SHA QA de las corridas conservadas: `83882430` /
  `49c72de20a53a9a4864b2ab9262d26db7f186e73`.
- Namespace: `akubica-uat-batch2-a4-v1`.
- Manifest dedicado y `akubica-uat-v1`: conservados; no se ejecutó reset de
  fixtures ni se modificaron recursos ajenos.
- PASS no repetidos: A12, B03/B03r, B01/B01r/B02/B04/B05, B18, B06/B06r,
  B19, B20/B21/B21r y B25.

## Conciliación de los 9 PAT

### Hechos demostrables

1. La corrida B18 registró nueve PAT temporales antes del reset. Sus IDs
   individuales no se conservaron en la evidencia persistente: sólo fueron
   materializados en un archivo temporal de trabajo que posteriormente fue
   eliminado. No existe una lista verificable para consultar cada ID ahora.
2. La primera invocación de reset terminó con código `1`; inmediatamente
   después se observó `state_present=0` y nueve PAT aún contables. Esto
   conserva el fallo original: la desaparición del estado no acreditó cleanup.
3. La invocación posterior devolvió `status=ok`, `deleted=29`, y la
   conciliación independiente posterior observó cero PAT asociados a los seis
   usuarios dedicados, cero recursos temporales de carrito y estado ausente.
4. La consulta actual sólo acredita el conteo cero del conjunto dedicado. No
   acredita la ausencia individual de cada uno de los nueve IDs ni permite
   atribuir qué DELETE eliminó cada PAT. No se infiere eliminación por
   expiración, estado vacío o desaparición del archivo.

### Código y orden del reset

`AkubicaUatAccessProvisioner::reset` lee y valida el estado, revoca bindings y
delegaciones, elimina `scenario_token_ids` y `token_ids`, revoca credenciales,
canales y consumers, ejecuta `assertCleanupComplete`, y sólo después elimina
`access-state.json`. `assertCleanupComplete` comprueba que los PAT listados ya
no existan, además de bindings, delegaciones, credenciales, canales y
consumers. La secuencia esperada, por tanto, no explica por sí sola la primera
corrida observada: el estado desapareció mientras nueve PAT seguían visibles.

Conclusión: existe una incidencia demostrada de trazabilidad/orden observado,
pero la operación exacta que eliminó cada PAT no es reconstruible con las
evidencias conservadas. No se cambia runtime ni se despliega en esta
conciliación. La emisión de nuevos accesos queda **BLOCKED** hasta conservar
IDs y auditoría por recurso antes del reset.

## Siguiente bloque preparado

| Caso | Perfil/titular | Consumer y capacidades | Delegación/binding/ownership | Precondición | Assertion | Cleanup | Estado |
|---|---|---|---|---|---|---|---|
| B09 | native / `membership_regular` dedicado | `machine_native`; `memberships:read`, `cart:read`, `cart:membership:write`, `cart:laboratory:read`, `cart:laboratory:write`; sin permisos de pago | customer dedicado del titular; delegación y binding `membership_regular`; ownership del customer | Membresía individual activa del mismo customer, sin acceso ajeno | `GET /api/v1/memberships/status` → 200; `active`, `individual`, `membership_already_active` | Revocar sólo el alias y verificar bindings/delegación/PAT por IDs | BLOCKED: no emitir hasta resolver trazabilidad PAT |
| B14 | native / mismo titular de B09 | Mismo contexto y capacidades de B09; no se amplían permisos | Mismo customer/binding; ownership debe coincidir con la línea | B09 activo; item de membresía no creado por la petición | `POST /api/v1/cart/items` → 409 `MEMBERSHIP_ALREADY_ACTIVE`, sin nueva fila | Inventario antes/después y prueba de ausencia de item nuevo; luego revocación | BLOCKED: depende de B09 y emisión |
| B23 | native/bearer none / titular `membership_none` dedicado | `machine_credential` + `bearer_none`; capacidad `checkout:prepare` y lectura de carrito según matriz; sin pago, payment-link ni gateway | customer dedicado; delegación/binding del contexto `membership_none`; ownership del carrito | Carrito médico sintético preparado; comprobar que prepare no activa membresía ni llama proveedores | `GET /api/v1/checkout/prepare?service_line=medical_services` → 200; `assistant_can_pay=false`, sin `payment_url`, `can_continue_on_platform=true`, `can_continue_to_payment_platform=false`; continuidad sólo según bandera | Conciliar carrito y auditoría permitida; revocar accesos; confirmar cero efectos externos | BLOCKED: primero debe acreditarse aislamiento HTTP y emisión trazable |
| B26 | native/bearer none / mismo titular de B23 | Mismo `machine_credential`/`bearer_none`; sin permisos de pago | Mismo customer y ownership; depende del carrito de B12/B23 | B12 + B23 completados sin pago; membresía nunca activada | `GET /api/v1/memberships/status` → 200; `status=none`, `expires_at=null` | Sólo limpieza de recursos propios de B12/B23 y revocación trazable | BLOCKED: dependiente de B23/B12 y emisión |

### Rutas y procedencia contractual

- B09: `GET /api/v1/memberships/status`.
- B14: `POST /api/v1/cart/items` con membresía `basic_annual` y línea
  `medical_services`.
- B23: `GET /api/v1/checkout/prepare?service_line=medical_services`.
  Es una preparación sin pago; el contrato exige ausencia de `payment_url` y
  no autoriza llamadas a gateway, correo, SMS o proveedor.
- B26: `GET /api/v1/memberships/status`, después de la precondición B12/B23;
  no se puede ejecutar aisladamente como prueba de no activación.

No se ejecutaron estos casos en esta iteración: la limitación PAT afecta sólo
la emisión y, por dependencia, los HTTP que requieren esos accesos. No se
presenta un precheck histórico ni una respuesta sin bearer como PASS funcional.

## Matriz restante y dependencias externas

- **Pendientes QA sin integración externa:** B09, B14, B23 y B26, sujetos a
  custodia trazable y a las precondiciones indicadas. B01/B01r/B02/B04/B05,
  B03/B03r, B18, A12 y los casos de carrito ya acreditados no se repiten.
- **Incidental fuera de alcance:** R02b y R03b no se convierten en requisito
  por haber sido incluidos accidentalmente en una selección anterior.
- **Intervención externa agrupada:** B24 requiere sesión first-party y
  step-up; A08–A11 y cualquier OTP requieren titulares y OTP reales; A01–A07
  requieren ATIX/HMAC; R05 requiere cita/concierge; R08 requiere sesión
  conversacional. Estos bloques no se ejecutan aquí.
- BLQ-A1-01, AJ-B, los 98 fallos históricos, R05/R08 y las dependencias de
  OTP/ATIX permanecen abiertos.

## Estado

`PAT_RECONCILIATION_LIMITED / ACCESS_ISSUANCE_BLOCKED`.
No es `STAGING_PASS` ni certificación de Batch 2.

## Verificación read-only del estado actual QA

Consulta ejecutada sobre el release activo, sin escrituras:

- `current` → release `83882430`; `REVISION` e `inventory identity` devuelven
  SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73`.
- Manifest dedicado activo: presente; 6 usuarios y 6 customers del manifest
  fueron la fuente de la consulta. No se adoptaron filas por prefijo de token.
- PAT asociados a esos usuarios, incluidos expirados que aún existieran: `0`.
- `access-state.json`: ausente.
- Delegaciones relacionadas por los usuarios del manifest: `142`; activas:
  `0`. Bindings relacionados por esas delegaciones: `142`; activos: `0`.
- Consumers relacionados: `50`; todos `enabled=0` y con `revoked_at`.
  Channels relacionados: `50`; todos `enabled=0` y con `revoked_at`.
  Credenciales machine relacionadas: `50`; todas `enabled=0` y con
  `revoked_at`. No se encontraron PAT machine asociados a esos consumers.

El alcance de esta conciliación es el grafo alcanzable desde los usuarios y
customers del manifest; no prueba la pertenencia histórica de cada fila a una
ventana concreta porque el release no conserva un ledger de namespace para
ellas. Como todas las filas encontradas están revocadas/deshabilitadas, no hay
un recurso activo ambiguo que requiera adopción o DELETE. La ambigüedad
histórica queda documentada y no bloquea por sí sola; sí bloquea la emisión
porque el release desplegado aún no tiene recuperación durable.

## Corrección local de recuperabilidad

Se preparó localmente una corrección mínima en el candidato actual:

- `AkubicaUatAccessProvisioner` escribe `access-inventory.json` con namespace,
  escenario, usuarios, consumers, channels, delegaciones, credenciales,
  bindings, PAT IDs, expiración y estado; nunca incluye secretos.
- El reset puede recuperar un state perdido usando ese inventario sólo cuando
  está `active`; al completar cleanup lo conserva como `completed` y elimina
  sólo `access-state.json`.
- El bridge privado de custody puede revocar usando el inventario activo si
  falta el state; la lectura de secretos sigue exigiendo el state cifrado.
- El inventario se valida por namespace/escenario y no puede adoptar otro
  namespace.

Prueba de regresión local en MySQL sintético aislado:

`vendor/bin/pest --configuration=phpunit.rc2.mysql.window2.xml tests/Feature/Console/AkubicaUatWindow2AccessTest.php tests/Feature/Console/ResetStateInventoryRecoveryTest.php --colors=never`

Resultado: `24 assertions`, código `0`. Incluye apply de 9 PAT, pérdida
inyectada de `access-state.json`, reset recuperado desde el inventario,
comprobación BD de cero PAT, inventario `completed`, manifest conservado y
cleanup de fixtures. Las dos advertencias son únicamente la ausencia de
`.env` en el worktree temporal; no afectaron assertions ni código de salida.

La corrección requiere publicación y nuevo deploy antes de abrir B09/B14/B23/B26.
No se hizo merge, push, deploy ni emisión QA.

## Corrección de ledger recuperable — candidato 7be558b4

- `access-inventory.json` se escribe antes de la primera escritura y después
  de cada recurso creado, incluyendo PAT creados antes de su binding. Sólo
  conserva IDs, relaciones, namespace, escenario, usuarios y expiración; no
  contiene plaintext, ciphertext ni headers.
- La escritura usa temporal + reemplazo atómico en Forge/Linux. El arnés
  Windows usa un fallback con lock exclusivo porque PHP no reemplaza un
  destino existente mediante `rename()`; la ruta desplegada no usa ese
  fallback.
- El reset valida namespace, tipos, IDs y coherencia de consumer/channel/
  delegation/binding/credential/token. No adopta referencias ajenas.
- Si una eliminación falla, el inventario permanece `active`; sólo pasa a
  `completed` después de comprobar cero PAT y revocación de los demás
  recursos. Un segundo reset es idempotente.

Prueba focalizada en worktree con Composer propio y MySQL sintético:

`pest --configuration=phpunit.rc2.mysql.window2.xml tests/Feature/Console/ResetStateInventoryRecoveryTest.php tests/Feature/Console/AccessInventoryRecoveryScenariosTest.php tests/Feature/Console/AkubicaUatWindow2AccessTest.php --colors=never`

Resultado: `4 tests`, `43 assertions`, código `0`. Incluye pérdida de state,
apply parcial, eliminación interrumpida, retry, rechazo de namespace/ID
malformado, ausencia de temporales y regresión de acceso/idempotencia. Las
advertencias son únicamente `.env` sintético ausente en el worktree.

Este candidato aún no se publicó ni desplegó; B09/B14/B23/B26 siguen
bloqueados en QA hasta verificar el release corregido.

## Procedimiento postdeploy único — no ejecutado

1. Precheck read-only: release/current, SHA verificable, REVISION, staging,
   host HTTPS, flags, Gate, TTL, manifests e inventario inicial.
2. Preparar sólo B09, B14, B23 y B26 con namespace dedicado; verificar
   ownership, capacidades, bindings y ausencia de integraciones externas.
3. Emitir accesos mediante custody privada y guardar únicamente el inventario
   sanitizado generado por el release.
4. Ejecutar cada caso independientemente, registrando request, assertion,
   código y efectos; detener sólo sus dependientes.
5. En `finally`, revocar por IDs del inventario, ejecutar reset, verificar en BD
   cero PAT/accesos temporales y conservar `access-inventory.json` como
   `completed`; emitir código no exitoso si falla cleanup.
6. Conciliar por IDs y namespace contra el inventario previo y actualizar
   evidencias sin secretos. No repetir PASS anteriores.

## Corrección sobre c46f8067 — candidato 9b7ffb24

La revisión del checkout publicado `c46f8067a892d297c38061c4c4b6959cac8079bf`
confirmó dos hallazgos: el conjunto `inventoryUserIds` no estaba inicializado
en ese commit y algunos snapshots todavía usaban sólo `context.user_ids`;
además, `completed` se trataba como inventario inválido en un reset repetido.

La corrección inicializa el conjunto antes del primer snapshot, agrega los
titulares de escenario y lo reutiliza en todos los snapshots y estados. Un
inventario `completed` ahora se valida con `assertCleanupComplete` antes de
permitir un nuevo apply; un reset repetido devuelve `empty`, y un inventario
`active` nunca se sobrescribe mientras conserve recursos pendientes.

Validación en checkout exacto del candidato corregido `9b7ffb24`:

`pest --configuration=phpunit.rc2.mysql.window2.xml tests/Feature/Console/AccessInventoryRecoveryScenariosTest.php tests/Feature/Console/ResetStateInventoryRecoveryTest.php tests/Feature/Console/AkubicaUatWindow2AccessTest.php --colors=never`

Resultado: `6 tests`, `60 assertions`, código `0`. Se verificó:

- `apply → pérdida de state → reset → reset repetido → nuevo apply → reset`;
- fallo después de crear un PAT de escenario, con el ID presente en el
  snapshot y cero PAT de la ventana al finalizar;
- cero residuos del namespace, inventario `completed`, fixtures activos y
  preservación del conteo de PAT preexistentes.

Las seis advertencias fueron únicamente `.env` sintético ausente. La corrida
anterior reportada como `4 tests / 43 assertions` no correspondía íntegramente
al SHA publicado: se ejecutó en un worktree de validación que tenía cambios
locales posteriores no incluidos en `c46f8067`, mientras el worktree principal
usaba además `vendor` enlazado al árbol A4. Esa discrepancia queda corregida y
se conserva como incidencia documental; no se reutiliza como evidencia del
candidato corregido.
