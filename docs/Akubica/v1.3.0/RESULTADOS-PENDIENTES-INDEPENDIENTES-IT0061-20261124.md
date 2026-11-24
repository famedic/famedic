# IT-0061 — pendientes independientes y siguiente bloque QA

Fecha de conciliación: 2026-11-24 (America/Mexico_City)

## Identidad y precheck

La fuente verificable del release, ejecutada dentro de `current`, devolvió:

```json
{"status":"verified","release":"83906347","active_sha":"aeb8c58cee8a83dbddc87ac471c99d56e82216c1"}
```

Comando read-only:

```text
php artisan functional-window2:inventory --no-ansi
```

El catálogo de comandos confirmó `akubica:rc2-catalog-fixtures`,
`akubica:rc2-price-fixtures`, `akubica:uat`, `akubica:uat-access`,
`functional-window2:custody` y `functional-window2:inventory`.

El dry-run del acceso dedicado con `--scenario=functional-window-2` pasó la
guarda del operador, no creó credenciales y no produjo efectos externos. El
dry-run de `akubica:uat --scenarios=B08,B09,B10,B11` sólo construyó un plan para
`akubica-uat-v1` y devolvió `bearer_flow=blocked_without_approved_synthetic_otp`.
Por tanto, no se usó para adoptar ni modificar el namespace legacy.

No se emitieron accesos, no se aplicaron fixtures y no se ejecutaron requests
funcionales en esta conciliación.

## Matriz: lógico frente a variantes

El alcance histórico contiene 46 escenarios lógicos: A01–A12 (12), B01–B26
(26) y R01–R08 (8). Las variantes (`B01r`, `B03r`, `B06r`, `B12r`, `B21r`,
`B21x`, `R01b`, `R02b`, `R03b`, `R07a`, `R07a2`, `R07b`, y las variantes A4)
son requests o controles trazables, no escenarios lógicos adicionales.

Los 22 resultados QA PASS ya acreditados, incluyendo variantes cuando aplica,
se conservan y no se repiten. B23 conserva además `PAT_TRACEABILITY_LIMITED`;
los FAIL históricos de B23 y B25 permanecen separados.

## Casos sin evidencia QA concluyente

| Caso lógico / variante | Estado | Precondición concreta | Preparación disponible | Cleanup | Responsable / siguiente acción |
|---|---|---|---|---|---|
| B07 | BLOCKED | Bearer native dedicado y catálogo de planes observable | Endpoint y acceso dedicado existen; falta runner/plan QA de solo lectura con credencial emitida | Ninguno si no se escribe | QA: generar plan desde inventory y ejecutar `GET /api/v1/memberships/plans` |
| B08 | BLOCKED | Titular sin membresía y acceso native coherente | `akubica:uat` sólo prepara legacy y bloquea bearer sin OTP; no adopta ese plan | Ninguno | QA/operador: exponer variante namespace-scoped o aportar OTP por canal seguro |
| B10 | BLOCKED | Membresía patrocinada dedicada, ownership y estado verificables | No existe en el dry-run dedicado una preparación de membresía patrocinada | Reset por IDs del manifest | QA: mecanismo soportado de fixture patrocinada |
| B11 | BLOCKED | Membresía expirada, incluida historia/estado exacto | Sólo evidencia local; el comando QA observado no ofrece variante dedicada | Reset por IDs | QA: fixture QA namespace-scoped y readback HTTP |
| B12 / B12r | BLOCKED | Carrito médico dedicado sin membresía, `cart:membership:write`, `qa_writes_authorized=1`; luego alta y lectura | No hay comando desplegado que prepare esta variante sin entrar en `akubica-uat-v1`; el dry-run de acceso no materializa carrito | Borrar sólo el item creado y conciliar fila antes/después | QA: publicar interfaz de carrito namespace-scoped o procedimiento aprobado |
| B13 | BLOCKED / dependent | B12 exitoso y segundo alta con misma pertenencia | No independiente de B12 | Probar ausencia de segunda fila | QA: ejecutar después de B12 |
| B15 | BLOCKED | Fixture de membresía patrocinada y ownership | Igual que B10; no acreditado por el dry-run | Reset por IDs | QA |
| B16 | BLOCKED / dependent | Item de membresía creado por B12 y DELETE soportado | No hay item dedicado disponible sin B12 | DELETE por ID dinámico verificado | QA: ejecutar después de B12 |
| B17 | BLOCKED / dependent | Idempotency key y recurso de B12 | Runner lo soporta, pero no hay recurso QA preparado | Conciliar una sola fila | QA |
| B22 | BLOCKED | Flag farmacia deshabilitada y endpoint que rechace antes de persistir | Código y assertion existen; falta evidencia HTTP QA vigente | Verificar cero filas de carrito/pago | QA: ejecutar con flag efectiva y sin proveedor |
| R01 / R01b | BLOCKED | Pedido propio y ajeno dedicados con ownership | Interfaz R04/R05 existe, pero no se acreditó preparación de pedidos en esta ventana | Reset por marcador/IDs | QA |
| R02 / R03 y variantes incidentales R02b/R03b | BLOCKED / incidental | Resultado/factura, step-up y ligas seguras | Las variantes incidentales no se convierten en requisitos; faltan datos/OTP | Sólo recursos propios | QA/Identidad |
| R04 | BLOCKED | Familiar dedicado y relación con titular | Comando `uat-fixtures-r04r05` existe, pero requiere dry-run/apply QA no ejecutado aquí | Reset por marcador derivado | QA |
| R05 | BLOCKED | Cita/concierge y sesión first-party | Preparación externa pendiente | Reset por procedencia | Producto/QA |
| R06 | BLOCKED / local-only | Correlation ID y trazas sanitizadas observables en QA | Sólo pruebas locales | Ninguno | QA: revisión read-only de logs |
| R07a/R07a2/R07b | BLOCKED | Payload inválido, binding negativo y ausencia de bearer, respectivamente | Runner y assertions existen; falta selección QA con credenciales/contexto acreditado | Probar cero filas | QA |
| R08 | OUT_OF_SCOPE_BATCH2 | Sesión conversacional y canal UAT | Batch 3 | Según canal UAT | Responsable UAT |

La tabla no convierte PASS local en PASS_QA. Los casos B01/B01r/B02/B04/B05,
B03/B03r, B06, B18–B21, B23, B25, B26 y A12 mantienen sus estados y evidencias
anteriores; no se repiten.

## B24/A4: mecanismo exacto

La emisión API y el consumo first-party son dos pasos distintos:

1. `GET /api/v1/checkout/prepare?service_line=medical_services` o
   `service_line=laboratory&brand=olab` emite la respuesta de preparación y,
   sólo con la configuración staging correspondiente, una `continuity_url`.
2. El titular consume `/checkout/continue/{token}` mediante la guardia web
   (`Auth::guard('web')`); el controlador comprueba titular, customer, carrito,
   línea y, en laboratorio, marca `olab`, y reclama el token atómicamente.

El código limita el TTL a 10 minutos y rechaza expiración, replay, sesión
incorrecta, carrito ajeno y marca ajena. La configuración inspeccionada
mantiene `first_party_purchase.enabled=false`, `financial.enabled=false` y no
crea pagos ni payment links. B24 requiere
`APP_ENV=staging` + `AKUBICA_B24_MEDICAL_CONTINUITY_ENABLED=true` recargado;
A4 laboratorio requiere su bandera staging y sesión first-party del titular.

Intervención mínima: operador QA habilita sólo la bandera de continuidad
correspondiente durante la ventana y el titular dedicado inicia sesión por el
canal seguro. No se simula sesión ni se entregan cookies/tokens por chat.

## Una solicitud operativa externa

| Bloque | Responsable | Requisito / dato | Canal seguro | Efecto previsto | Finalización |
|---|---|---|---|---|---|
| B08/B10/B11/B12–B17 | QA/operador | Variantes namespace-scoped de membresía/carrito; sin legado | procedimiento Forge/vault | filas sintéticas de ventana | readback, cleanup y cero residuos |
| B24/A4 | QA + Seguridad + titular dedicado | bandera staging, titular y sesión first-party | Forge + IdP/canal de titular | liga de continuidad, sin pago | expiración/replay/cleanup verificados |
| A01–A07 | Responsable ATIX | sandbox, allowlist, HMAC, reloj y credenciales | vault corporativo | llamadas sandbox autorizadas | correlación y revocación |
| A08–A11 | Identidad/QA + titular | OTP/step-up real y titular controlado | proveedor seguro, nunca chat | SMS/OTP y auditoría | OTP revocado/expirado y evidencia |
| R02/R03/R05 | QA/Producto/Identidad | resultados/facturas/cita/concierge y sesión | fixtures aprobados + canal seguro | ligas/step-up según contrato | cleanup por IDs |
| R08 | Responsable UAT | sesión conversacional de prueba y retención | canal UAT | conversación controlada | cierre y retención verificados |

## Resultado y control

Conteos: 46 lógicos; variantes reportadas por separado; 22 filas QA PASS
conservadas; 2 FAIL históricos conservados; casos pendientes según la tabla;
0 accesos nuevos y 0 escrituras en esta iteración.

BLQ-A1-01, AJ-B, los 98 fallos históricos y `PAT_TRACEABILITY_LIMITED` siguen
abiertos. No se declara Batch 2 certificado.

## Extensión local completada

La carencia demostrada estaba en `akubica:uat-access`, no en el
materializador: `uat-fixtures` ya crea los cuatro usuarios/customers y los
estados `membership_none`, `membership_regular`,
`membership_institutional` y `membership_expired` dentro del manifest
dedicado. Se añadió el escenario explícito
`functional-window-2-memberships`, sin alterar `functional-window-2` ni el
flujo legacy. Sus cuatro bindings son native, con capacidades de lectura y
carrito ya existentes; no añade capacidades de pago, OTP ni proveedores.

| Caso | Alias de escenario | Fixture | Assertion | Cleanup |
|---|---|---|---|---|
| B08 | `native_membership_none` | customer sin suscripción | 200, estado `none` | reset por IDs |
| B10 | `native_membership_institutional` | suscripción institucional | estado institucional contractual | reset por IDs |
| B11 | `native_membership_expired` | suscripción vencida | estado expirado contractual | reset por IDs |
| B12/B12r | `native_membership_none` | carrito médico vacío al inicio | 201 y lectura 200 | DELETE del item dinámico |
| B13 | `native_membership_none` | mismo carrito después de B12 | 409 `MEMBERSHIP_ALREADY_IN_CART` | ausencia de segunda fila |
| B16 | `native_membership_none` | item creado por B12 | DELETE 200 | conciliación de carrito |
| B17 | `native_membership_none` | clave/recurso de B12 | repetición sin fila adicional | reset por IDs |

## Validación local

Base: MySQL sintético local en `127.0.0.1:13307`, base exclusiva
`famedic_rc2_it0061_window2_testing`, datos sintéticos y sin llamadas externas.

- `akubica:uat-fixtures --dry-run`: PASS; siete identidades, manifest
  dedicado y allowlist de storage.
- `akubica:uat-fixtures --apply`: PASS idempotente; `created=0`,
  `updated=47`, `deleted=0`.
- PHP lint de los dos comandos/servicio y test: PASS.
- `git diff --check`: PASS.
- Suite focalizada de acceso, endpoints Laravel y cleanup: código 0, 47
  assertions; incluye B12 201, repetición 409, listado y DELETE, estados de
  las cuatro variantes y repetición idempotente. El runner marcó dos warnings
  `file_get_contents` del arnés/configuración; no son fallos de assertion,
  pero impiden llamarlo regresión limpia.
- Suite de recuperación de inventario/custody: código 0, 93 assertions;
  ocho warnings del mismo arnés. Cubre apply parcial, pérdida de state, reset
  repetido, nuevo apply, revocación y rechazo de referencias ajenas.

No se ejecutó QA ni se emitieron credenciales remotas. B07/B08/B10/B11 y los
dependientes quedan preparados localmente; requieren publicar/desplegar este
candidato antes de su ejecución HTTP QA. B22 y R01/R01b/R04/R06/R07 no tienen
en el repositorio evidencia QA recuperable con todas sus assertions; no se
reclasifican por similitud ni por pruebas locales.

## Candidato revisable

Rama publicada: `it0061/independent-membership-preparation`

Commit: `0a3e689aa0a6a04d691cdfae1218eb2b182291fa`

Comparación: https://github.com/famedic/famedic/compare/prep%2Fakubica-technical-integration-rc11...it0061%2Findependent-membership-preparation

El candidato requiere deploy antes de cualquier ejecución QA de los nuevos
perfiles. No se hizo merge ni deploy.

## Custody explícito para memberships — iteración posterior

Se extendió `functional-window2:custody` con una allowlist por escenario.
Además de `functional-window-2`, acepta únicamente
`functional-window-2-memberships` y los aliases native de sus cuatro
variantes más `machine_native`. `leo_membership_none` y cualquier escenario
desconocido son rechazados. La validación conserva namespace dedicado,
vigencia, integridad de secretos, canal privado y revocación de todos los
PAT registrados por el estado, no sólo los aliases solicitados.

Validación local: PASS, 1 test / 14 assertions / exit 0. El recorrido usó
el materializador y el helper CLI real por pipe privado, entregó credenciales
únicamente en memoria al cliente Laravel, ejecutó cuatro consultas de estado
de membresía, rechazó escenario y alias ajenos, revocó y completó reset. No
hubo llamadas externas ni valores de credenciales en evidencias.

La variante de memberships pasó 1/13 assertions; custody general pasó 1/33
y recuperación 1/46, con las advertencias históricas de `file_get_contents`
del arnés. La selección combinada del archivo Feature no se cuenta como PASS:
su orden transaccional produjo `UAT_FIXTURE_UNEXPECTED_ERROR` al reutilizar
estado entre pruebas; las ejecuciones focalizadas y aisladas sí fueron
concluyentes. Se clasifica como arnés/orden de pruebas, no como defecto de
runtime.

El cambio requiere publicación/despliegue antes de usar el escenario nuevo en
QA. Batch 2 permanece abierto y sin certificación.

