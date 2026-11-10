# Paquete técnico RDS para RC2

## Alcance

Este documento describe la migración real `database/migrations/2026_11_08_220000_create_opaque_medical_benefit_store.php`. La validación de DDL se realizó en MySQL 8.0.46 local. La aprobación de QA corresponde al DBA y no está implícita.

## Objetos

La migración añade `opaque_revision` a `medical_service_carts`, `coupons` y `coupon_user`. Crea:

- `opaque_benefit_mutex`: `id` primario y `generation`.
- `opaque_benefit_contexts`: referencias a carrito, usuario, consumidor, canal y delegación; hashes, ciphertext, revisión, índice único carrito/actor y `cart_ref_hash` único.
- `opaque_benefit_states`: bucket, `key_hash`, contexto, fuente opcional, ciphertext, índice contexto/bucket y unicidad bucket/hash.
- `opaque_benefit_settlements`: contexto, transacción, fuente, hashes de compra/aplicación, crédito y subtotal.
- `opaque_benefit_movements`: liquidación, fuente, transacción de crédito opcional, tipo, importes, revisiones y unicidad liquidación/tipo.

Todas las claves foráneas y acciones son las definidas por la migración. No se propone DDL alternativo.

## Los nueve triggers

1. `obi_insert`: incrementa `medical_service_carts.opaque_revision` al insertar un item.
2. `obi_delete`: incrementa la revisión al eliminar un item.
3. `obi_update`: incrementa la revisión cuando cambia la relación del item con un carrito.
4. `obb_insert`: incrementa la revisión al insertar un beneficio.
5. `obb_delete`: incrementa la revisión al eliminar un beneficio.
6. `obb_update`: incrementa la revisión cuando cambia la relación del beneficio con un carrito.
7. `obc_update`: incrementa la revisión del carrito cuando cambia estado, subtotal, descuento, crédito aplicado o total.
8. `ob_source`: incrementa `coupons.opaque_revision` antes de cada actualización MySQL.
9. `ob_assignment`: incrementa `coupon_user.opaque_revision` antes de cada actualización MySQL.

El anexo `RC2-PREPARACION-QA/B-RDS-RUBEN/RC2-TRIGGERS-LITERAL.sql` contiene los nueve `CREATE TRIGGER` y sus `DROP TRIGGER IF EXISTS` extraídos de la rama de trabajo. La validación de integridad compara ese anexo con la sección `triggers()` de la migración; cualquier cambio en la migración exige regenerar el anexo.

## Prerrequisitos y permisos

- MySQL 8 compatible con `SIGNAL`, `BEFORE UPDATE`, `AFTER UPDATE`, `DROP TRIGGER IF EXISTS` y comparación null-safe `<=>`.
- Binlog y replicación revisados por el DBA.
- Si `@@log_bin=1`, `@@log_bin_trust_function_creators` debe ser `1` antes de aplicar la migración. La migración falla cerradamente si no se cumple.
- El usuario de aplicación/DBA necesita crear y eliminar triggers, alterar las tres tablas, crear tablas, índices y claves foráneas, y escribir en la tabla `migrations` durante la ventana controlada. No se solicita privilegio global permanente.

## Orden controlado

1. Confirmar build y SHA.
2. Tomar respaldo y verificar restauración en una instancia de QA.
3. Confirmar conexiones, binlog, `log_bin_trust_function_creators`, charset y permisos.
4. Ejecutar únicamente la migración versionada.
5. Validar tablas, columnas, claves, índices y exactamente nueve triggers.
6. Ejecutar smoke tests de revisión, idempotencia, aislamiento y rollback funcional.

## Respaldo y rollback

El DBA debe definir el mecanismo de snapshot/backup de QA y comprobar su restauración antes de la ventana. El `down()` de la migración elimina los nueve triggers, las cinco tablas opacas y las tres columnas `opaque_revision`; debe ejecutarse solo con aprobación del DBA y después de confirmar que no existen datos que deban conservarse. No se probó rollback en QA.

## Riesgos y suspensión

Suspender si el valor de `log_bin_trust_function_creators` no coincide, si falta una FK o índice, si aparece un trigger adicional con el mismo propósito, si el conteo no es nueve, si el backup no es restaurable o si los smoke tests muestran incremento incorrecto de revisiones. La validación local no acredita disponibilidad ni parámetros de RDS QA.
