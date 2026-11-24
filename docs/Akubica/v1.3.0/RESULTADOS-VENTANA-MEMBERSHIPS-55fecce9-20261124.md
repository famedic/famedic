# IT-0061 — ventana memberships QA

## Identidad y precheck

- Release activo: `83916495`.
- SHA observado en `current`, Git del release, `REVISION` e
  `functional-window2:inventory action=identity`:
  `55fecce944a5cc2c20e08d061f862cd21809af5a`.
- Ambiente/host: staging, HTTPS first-party `famedic-otp.on-forge.com`.
- La configuración efectiva y el Gate del operador ya estaban verificados
  por el dry-run de acceso; no se modificaron flags ni permisos.
- Fixtures: el apply previo quedó idempotente (`created=0`, `updated=47`);
  no se repitió.
- Accesos residuales al inicio: el estado de acceso existente era inválido
  para custody (`STATE_TOKEN_REFERENCE_INVALID`), por lo que se hizo reset
  y un único apply de acceso para esta ventana. No se emitieron OTP ni se
  llamaron proveedores externos.

## Selección ejecutada

El plan nuevo fue `functional-window-2-memberships-qa-plan.json`, con
`credential_scenario=functional-window-2-memberships`, selección explícita y
cuatro requests independientes. El custody CLI privado entregó en memoria
los aliases `machine_native`, `native_membership_none`,
`native_membership_institutional` y `native_membership_expired`; no se
registraron secretos ni headers.

| Caso | Resultado | HTTP/assertions | Código |
|---|---|---|---:|
| B07 | PASS_QA | 200; planes, precio MXN, moneda y ausencia de payment link | 0 |
| B08 | PASS_QA | 200; `none`, elegible, sin plan ni motivo | 0 |
| B10 | PASS_QA | 200; `active`, `sponsored`, no elegible, expiración presente | 0 |
| B11 | PASS_QA | 200; `expired`, elegible, plan `basic_annual`, expiración presente | 0 |

Runner: `functional_window2_http.py`, modo QA, TLS verificado, sin redirects;
salida sanitizada `exit_code=0`, `cleanup_status=PASS`. La revocación privada
de credenciales terminó correctamente y el reset posterior informó
`deleted=32`.

## Casos no ejecutados

B12, B12r, B13, B16 y B17 permanecen `BLOCKED`: el release desplegado no
expone en `functional-window2:inventory` un propósito acreditado para
registrar/verificar el recurso dinámico de carrito de membresía. Sin esa
verificación de namespace, ownership y propósito, no se emitieron escrituras
de carrito ni se ejecutó DELETE. Esto es una limitación del mecanismo de
cleanup desplegado, no un PASS ni un cambio contractual.

## Limpieza y conciliación

- `access-inventory.json`: `status=completed`.
- `access-state.json`: ausente tras el reset verificado en la ruta efectiva
  del disk `local`.
- La identidad del release continuó verificada después de la limpieza.
- Fixtures, manifests, namespace legacy y PAT ajenos no fueron modificados.
- La ausencia histórica de IDs individuales de PAT sigue documentada como
  `PAT_TRACEABILITY_LIMITED`; no se usó esta corrida para reconstruirla.

Batch 2 permanece abierto y sin certificación.

## Corrección local posterior

Se preparó un candidato local que añade el recurso explícito
`medical_service_cart_item` al inventory helper. La validación exige
`customer_role=membership_none`, `service_line=medical_services`, carrito
activo, `item_type=membership`, `membership_plan_code=basic_annual`,
procedencia posterior a la creación del manifest y SHA del release. Los
propósitos de laboratorio continúan usando `LaboratoryCartItem`; no se
reutiliza ese modelo para el carrito médico.

El plan local `functional-window-2-memberships-cart-qa-plan.json` cubre B12,
B12r, B13 y B17, con B16 como cleanup dinámico. Esta parte requiere publicar
y desplegar el candidato antes de poder cambiar los cinco casos de BLOCKED a
ejecutables en QA; no se accedió a QA durante esta corrección.
