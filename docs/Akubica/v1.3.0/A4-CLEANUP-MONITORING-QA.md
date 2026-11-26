# IT-0061 — Procedimiento QA de limpieza de monitoreo A4

Este procedimiento se ejecuta **después del deploy autorizado** del release
que incluye la corrección de `akubica:a4-continuity-cart`. No sustituye el
precheck de SHA/release ni autoriza una corrida W3.

Conservar: PASS_QA de L05a y el FAIL histórico de A4
(`ASSERTION_HEADER_MISMATCH`). No reclasificar esos resultados. Esta
corrección sólo cierra el residuo de limpieza de monitoreo.

No crear residuos nuevos. No emitir accesos, fixtures ni ligas de W3 en
esta ventana. No cambiar flags (incluida B24). No ejecutar pagos, OTP,
ATIX, GDA ni proveedores.

Confirmaciones exactas (no intercambiables):

- cleanup de ventana: `akubica-a4-continuity-cart`
- retiro del residuo histórico: `akubica-a4-retire-monitoring-residue`

Namespace: `akubica-uat-batch2-a4-v1`.

## 0. Precheck de sólo lectura (obligatorio)

Tras el deploy, contrastar `current`, `REVISION`, inventory identity y el
release físico. El SHA desplegado debe ser el del merge autorizado, no el
HEAD local del runner.

Comprobar, sin escribir:

- entorno staging, host HTTPS, Gate;
- namespace `akubica-uat-batch2-a4-v1`;
- `akubica-uat-v1` intacto;
- presencia del residuo histórico acreditado:
  - carrito de monitoreo `1221` del titular `membership_none` (customer 13613);
  - ítem de monitoreo `10769`;
  - ligas 6 y 7 (FAIL histórico / W2), ya expiradas, ligadas a `1221`.
- 0 ítems de laboratorio del titular, si sigue siendo el estado acreditado.

No borrar nada en este paso. Si SHA, release, ownership o namespace
discrepan, detener y reportar.

## 1. Dry-run del retiro histórico

En el checkout del release desplegado:

```text
php artisan akubica:a4-continuity-cart \
  --namespace=akubica-uat-batch2-a4-v1 \
  --retire-monitoring-residue \
  --cart-id=1221 \
  --cart-item-id=10769 \
  --no-ansi
```

Debe ser dry-run (sin `--execute`). Esperado:

- `status=ok`, `reason=DRY_RUN`;
- plan con `cart_id=1221`, `cart_item_id=10769`;
- `evidence` explícita: `accredited_historical=true`, IDs pedidos 1221/10769,
  `continuity_link_ids` de las ligas 6 y 7 (y, si el ledger de una ventana
  posterior las tuviera, `ledger_monitoring_cart_id` /
  `ledger_monitoring_cart_item_ids`; el residuo histórico 1221/10769 no se
  acredita por coincidencia de `product_id`);
- ninguna fila eliminada.

Si el comando rechaza por procedencia, exclusividad (`CART_NOT_EXCLUSIVE`),
carrito completado (`A4_MONITORING_RESIDUE_CART_COMPLETED`) o porque el
titular ya tiene ítems de laboratorio (`LAB_CART_IN_USE`), **no** ejecutar
el retiro. Reportar el código, la evidencia del plan y el inventario actual.

## 2. Retiro real del residuo acreditado

Sólo si el dry-run fue aceptado y la autorización de escritura está vigente:

```text
php artisan akubica:a4-continuity-cart \
  --namespace=akubica-uat-batch2-a4-v1 \
  --retire-monitoring-residue \
  --execute \
  --cart-id=1221 \
  --cart-item-id=10769 \
  --confirm=akubica-a4-retire-monitoring-residue \
  --no-ansi
```

El comando exige IDs exactos, el namespace dedicado, la confirmación
distinta de la de `--cleanup`, ownership del titular `membership_none` y
procedencia por recurso: IDs del ledger de la ventana (`monitoring_cart_id`
y, si la lista existe y el ítem sigue presente, pertenencia a
`monitoring_cart_item_ids`) y/o ligas de continuidad con
`metadata.laboratory_cart_id=1221`. El par acreditado 1221/10769 se
documenta de forma explícita en la evidencia; no se acredita por
`product_id`. Un carrito en estado `Completed` se rechaza con
`A4_MONITORING_RESIDUE_CART_COMPLETED` y no se borra. Con `--execute`,
adquiere el lock del namespace y, dentro de la transacción, revalida con
`lockForUpdate` ownership, estado (no completado), exclusividad,
procedencia y ausencia de uso de laboratorio antes de cualquier DELETE.
Si el contenido cambió desde el dry-run (por ejemplo un ítem ajeno
añadido al carrito), rechaza con `CART_NOT_EXCLUSIVE` y no borra el
contenido ajeno ni el ítem acreditado. No hace borrados generales. No
elimina las ligas 6 y 7 (se conservan como evidencia del FAIL histórico).

Si el carrito y el ítem ya no existen y la procedencia se puede acreditar,
el resultado es idempotente (`ALREADY_ABSENT`).

## 3. Conciliación independiente

Verificar en base de datos, sin usar sólo la salida del comando:

- `carts.id=1221` ausente;
- `cart_items.id=10769` ausente;
- ligas 6 y 7 presentes, expiradas, sin uso;
- 0 ítems de laboratorio del customer 13613, si esa era la precondición;
- recursos base, compartidos y ajenos intactos;
- `akubica-uat-v1` intacto;
- ningún job, correo, ActiveCampaign ni llamada a proveedor disparados
  por este retiro.

Una repetición del comando de retiro debe ser idempotente.

## 4. Cleanup habitual de una ventana posterior

El `--cleanup` de A4 (confirmación `akubica-a4-continuity-cart`) ahora
concilia también carts/cart_items de monitoreo de la ventana, verifica
procedencia por recurso y no depende del observer. Sigue siendo
namespace-scoped.

`--apply` registra en el ledger los IDs exactos de los `cart_items` de
monitoreo de la ventana (`monitoring_cart_item_ids`), junto con
`monitoring_cart_id`, `customer_id` y `test_id`. El cleanup sólo elimina
esos IDs, y sólo en el carrito registrado. Una coincidencia de
`product_id` no autoriza el borrado: otro carrito del mismo titular con
el mismo estudio, un ítem no registrado dentro del carrito de la ventana
(aunque tenga el mismo `product_id`) o cualquier ítem ajeno se conservan.
Si un ítem no puede acreditarse, se reporta en `monitoring_limitations`
(`ITEM_NOT_IN_LEDGER_PRESERVED`) y no se adopta automáticamente.

Si, entre `--apply` y `--cleanup`, el sync oficial de monitoreo
(`LaboratoryCheckoutContinuity::issue` → `syncLaboratory`) recrea los
ítems del carrito de la ventana, los IDs registrados en el ledger
desaparecen. En ese caso el cleanup no adopta el ítem en el ledger: sólo
si **todos** los IDs registrados están ausentes y el carrito sigue siendo
el `monitoring_cart_id` del ledger, retira los reemplazos con el mismo
`test_id` en **ese** carrito y lo reporta como
`LEDGER_ITEM_IDS_SUPERSEDED`. No toca otros carritos ni ítems ajenos
añadidos mientras los IDs originales siguen presentes.

Los carritos en estado `Completed` quedan fuera tanto del cleanup habitual
como del retiro histórico; el cleanup los reporta como
`CART_COMPLETED_PRESERVED` y conserva carrito e ítems. El carrito de la
ventana sólo se retira si queda vacío, no está completado y coincide con
`ledger.monitoring_cart_id`. Una liga histórica no acredita por sí sola
todos los ítems actuales del carrito.

No usar `--cleanup` como sustituto del retiro histórico con IDs exactos
si el ledger ya quedó `completed` y sólo resta el residuo acreditado
1221/10769; para ese caso usar los pasos 1–3, que son un procedimiento
separado por IDs y con evidencia explícita.

## Fuera de alcance

- No merge ni deploy desde este documento.
- No cambiar `AKUBICA_B24_MEDICAL_CONTINUITY_ENABLED` ni ejecutar B24.
- No declarar Batch 2 certificado.
- No ejecutar W3 hasta autorización expresa posterior a esta limpieza.
