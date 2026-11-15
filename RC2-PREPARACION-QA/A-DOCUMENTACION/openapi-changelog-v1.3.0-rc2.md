# Changelog OpenAPI RC2

## Compatibilidad

OpenAPI 1.2.3 histórico permanece sin cambios. RC2 usa el contrato principal de 74 operaciones derivado del paquete RC1.1 reconciliado. R30 se conserva en un documento first party separado de cuatro operaciones.

## Correcciones y límites

- Se documenta `applied_benefits` en los totales del carrito médico.
- ATIX mantiene respuesta contractual HTTP 202 y no emite OTP ni Bearer.
- Se conservan Bearer, step-up y vinculación a consumidor, canal y delegación.
- Se documentan comparación de precios, Plan Básico, altas/retiros idempotentes y separación de carritos.
- `checkout/prepare` mantiene continuidad preparada, pero `assistant_can_pay=false`; B24 no está activado.
- Leo no recibe pagos, payment links, cupones, saldo, beneficios legacy ni capacidades R30.
- No se agregan schemas ni precios de paquetes mientras B02 siga bloqueado.

## Referencias de validación

La sintaxis JSON del contrato principal y del suplemento R30 se valida en el informe de cierre local. La validación oficial de OpenAPI y las pruebas HTTP de staging permanecen pendientes.

## 2026-11-15 — Documentación de `/catalog/packages` (B02)

- **Nuevo en el contrato (ya implementado en código):** `GET /catalog/packages` (lista paginada; filtros `brand`, `search`, `category_id`, `page`, `per_page`) y `GET /catalog/packages/{package_id}` (detalle por `package_test_id`; `404 PACKAGE_NOT_FOUND`; `503 PRICING_SOURCE_UNAVAILABLE`). Públicos, sin autenticación.
- Esquemas nuevos: `LaboratoryPackage`, `LaboratoryPackageCompositionElement`, `CatalogPackagesResponse`, `CatalogPackageResponse`; parámetro `PackageId`.
- `validation_status` de cada elemento refleja la aprobación comercial; `composition_status=validated` solo cuando todos los elementos están `validated`. `validation_reference` aparece también en estudios (requiere el cambio B02 de esta rama).
- Discrepancias conocidas de la implementación (no documentadas como contrato): la lista acepta `requires_appointment` en validación pero no lo aplica; la lista no captura `PRICING_SOURCE_UNAVAILABLE` (el detalle sí).
- Validación: `openapi-spec-validator` OK; `redocly lint` sin problemas nuevos (los 8 errores `operation-summary` y 33 avisos son preexistentes, idénticos en la versión base).
