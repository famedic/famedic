# RC2 — B02: fuente autorizada de paquetes

Estado: bloqueado para implementación funcional local.

La referencia técnica RC1.1 (`9c060c81e6eda7871e2c902563e3c9e0daac74dc`) conserva el catálogo médico basado en `LaboratoryTest`; no contiene una fuente autorizada y verificable de paquetes médicos ni una regla contractual de precios para ellos. El paquete documental entregado a Leo no constituye por sí mismo una fuente de datos de catálogo/precio integrada al repositorio.

Por seguridad no se agregan catálogos, precios, identificadores ni operaciones inventadas. B02 podrá implementarse cuando dirección/producto entregue una fuente autorizada, con identidad de paquete, composición, vigencia y precio en centavos.

Este bloqueo no impide los ajustes independientes de B24, contrato OpenAPI de `applied_benefits`, exclusiones de Leo ni sus pruebas locales.

## Actualización Batch 1 · T6 (2026-11-18): criterio vigente B02

El bloqueo de arriba es **histórico**: después se resolvió con la fuente de paquetes de la nota técnica B02 v5 (`package_test_id`, clasificación explícita y composición híbrida).

El plan de cierre v1.0 fija el criterio de aceptación de B02 (escenario S05). Se evalúan **exactamente** siete puntos:

1. Nombre.
2. Marca.
3. `item_type=package`.
4. Precio público.
5. Precio FAMEDIC.
6. Ahorro (precio público menos precio FAMEDIC, mayor que 0).
7. Detalle del mismo paquete coincidente con el listado.

No condicionan PASS/FAIL:

- `composition_status` (`validated` o `incomplete`).
- `validation_status` de cada elemento de composición.
- Impuestos y vigencia.

Esos datos se registran como informativos en Newman (`console.log`). Un paquete `incomplete` es aceptable para B02 siempre que Leo no lo presente como composición validada.

Cambio de aserciones (documentado, sin impacto de seguridad):

- B02 y B02r ya no evalúan como `pm.test` impuestos, vigencia ni `composition_status`.
- Las aserciones de precio, ahorro, marca, nombre, tipo y detalle se mantienen o se separan en pruebas individuales.

