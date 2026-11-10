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
