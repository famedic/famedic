# OpenAPI changelog ? v1.2.3 (TI-03 cat?logo)

Fecha: 2026-08-12

## Cambios contractuales / correcciones

- `info.version` actualizado a `1.2.3`.
- Enum contractual de marcas corregido a `olab`, `swisslab`, `jenner`, `liacsa`, `azteca`; se elimina `azul`.
- Respuestas de cat?logo tipadas para marcas, estudios, categor?as y sedes.
- Detalle de estudio completado seg?n `LaboratoryTestResource`.
- Filtro `requires_appointment` documentado en `catalogLaboratoryTests`.
- Constraints/defaults reales de filtros documentados: `state`, `search`, `category_id`, `page`, `per_page`.
- Paginaci?n real de `laboratory-tests` documentada con `current_page`, `last_page`, `per_page`, `total`.
- Respuesta `422 VALIDATION_ERROR` agregada ?nicamente en los cuatro endpoints de lista con `FormRequest`.
- No se agrega campo de vigencia porque el runtime no lo expone.

## Aclaraciones

- No se agregan endpoints ni capacidades nuevas.
- No se modifican runtime, rutas, Resources, Models, tests, Postman ni configuraci?n.
- No se agregan Algolia, aliases cl?nicos, equivalencias cl?nicas, sin?nimos, reglas comerciales, farmacia ni cancelaci?n autom?tica.
