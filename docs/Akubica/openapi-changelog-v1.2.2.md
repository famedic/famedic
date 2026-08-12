# OpenAPI changelog ? v1.2.2 (TI-02 cierre contractual)

Fecha: 2026-08-12

## Cambios contractuales / correcciones

- `info.version` actualizado a `1.2.2`.
- Secure links: contrato de ligas nuevas documentado con TTL de 60 minutos y m?ximo 3 consumos autorizados en emisi?n, descarga p?blica y schema `SecureLink`.
- `Idempotency-Key`: lista exacta de las 9 operaciones con middleware `api.idempotency`:
  - `POST /auth/login/request-code`
  - `POST /auth/register`
  - `POST /checkout/payment-link`
  - `POST /laboratory-appointments`
  - `POST /orders/{order_id}/results/step-up/request`
  - `POST /orders/{order_id}/results/secure-link`
  - `POST /orders/{order_id}/invoices/{invoice_id}/step-up/request`
  - `POST /orders/{order_id}/invoices/{invoice_id}/secure-link`
  - `POST /orders/{order_id}/invoice-request`
- Request bodies agregados para escrituras con validaci?n real:
  - `TaxProfileWriteRequest`
  - `AddressWriteRequest`
  - `ContactWriteRequest`
  - `CancelOrderRequest`
- Respuestas `422 VALIDATION_ERROR` agregadas donde existe validaci?n real por `FormRequest`.
- Respuestas `403 FORBIDDEN` agregadas en operaciones protegidas por `api.customer`.
- Respuestas `429 TOO_MANY_REQUESTS` documentadas en operaciones con throttle real.

## Aclaraciones

- No se agregan endpoints ni capacidades nuevas.
- No cambia Postman ni configuraci?n runtime en este alcance.
- La alineaci?n de runtime/configuraci?n de secure links de 60/5 a 60/3 pertenece a TI-05.
