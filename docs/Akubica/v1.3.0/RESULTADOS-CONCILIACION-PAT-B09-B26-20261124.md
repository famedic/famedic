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

