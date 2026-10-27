# Guía de consumo — Akúbica API v1.3.0

Base URL: `{{base_url}}` con sufijo `/api/v1` cuando el environment no lo incluya.

## Convenciones

Todas las solicitudes JSON usan `Accept: application/json`; POST/PUT/DELETE usan `Content-Type: application/json`. En rutas protegidas se envía `Authorization: Bearer {{token}}`. Cada request debe llevar `X-Correlation-ID: {{correlation_id}}`; el valor de respuesta debe conservarse para trazabilidad y no exponerse en logs públicos junto con payload sensible.

## Catálogo comparativo

`GET /catalog/laboratory-tests?brand={{brand}}` y `GET /catalog/laboratory-tests/{laboratory_test_id}` son públicos. El servidor es la fuente de precios: `price_cents` es entero y `currency` identifica la moneda. El consumidor no envía precio, descuento ni total.

`brand` debe ser una marca válida del catálogo, por ejemplo `olab`, `swisslab`, `jenner`, `liacsa` o `azteca`.

## Membresías

- `GET /memberships/plans` devuelve planes disponibles y precios canónicos.
- `GET /memberships/status` requiere Bearer y devuelve el estado protegido del usuario autenticado.
- Una membresía puede representarse en el carrito médico solo con el código de plan provisto por el catálogo. Esta entrega no autoriza activación ni pago.

## Carrito de servicio médico

Usar siempre `service_line=medical_services`:

```http
GET /cart?service_line=medical_services
GET /cart/totals?service_line=medical_services
```

Los items deben conservar esa línea. No mezclar items de laboratorio o farmacia en el mismo flujo.

## Beneficio por cupón o saldo a favor

`POST /cart/medical-benefit` recibe solo el código de beneficio:

```json
{"service_line":"medical_services","code":"{{coupon_code}}"}
```

El valor puede representar un cupón o un saldo a favor según la configuración del ambiente. Los totales se recalculan en servidor. Validar `COUPON_NOT_FOUND`, `COUPON_EXPIRED` y `COUPON_NOT_APPLICABLE` sin registrar códigos reales. Reaplicar la misma `Idempotency-Key` con el mismo body valida replay; cambiar el body con la misma key debe producir conflicto.

`DELETE /cart/medical-benefit?service_line=medical_services` retira el beneficio del carrito médico.

## Checkout de preparación

```http
GET /checkout/prepare?service_line=medical_services
```

Esta operación solo devuelve carrito, requisitos, warnings y `can_continue_to_payment_platform=false`. No crea pagos, payment links, activaciones ni llamadas a gateways. No usar `POST /checkout/payment-link` en esta entrega.

## Reconocimiento pre-OTP y elevación

El reconocimiento de contacto inicia el flujo OTP, pero no entrega un Bearer ni un `account_context_token` al consumidor. El contexto queda ligado internamente a `conversation_id`, `channel_event_id`, `channel`, `audience` y el challenge OTP. La elevación post-OTP ocurre mediante `POST /auth/login/verify-code`; el cliente recibe únicamente la respuesta contractual de autenticación. No reutilizar un contexto como token ni incluirlo en logs.

## ATIX phone lookup

`POST /integrations/atix/phone-lookups` requiere estos headers:

| Header | Fuente |
|---|---|
| `X-Integration-Key-Id` | `{{atix_integration_key_id}}` |
| `X-Timestamp` | Unix seconds actual |
| `X-Nonce` | sintético único, 8–128 caracteres |
| `X-Signature` | HMAC-SHA256 hexadecimal |
| `X-Correlation-ID` | UUID sintético único |
| `Idempotency-Key` | sintética única por operación |

Canonical string:

```text
METHOD
/api/v1/integrations/atix/phone-lookups
timestamp
nonce
sha256(raw_json_body)
```

La firma es `HMAC-SHA256(canonical_string, ATIX_SANDBOX_HMAC_SECRET)`. El secreto solo debe llegar desde el gestor seguro del ambiente y nunca debe guardarse en Postman exportado.

Body mínimo:

```json
{
  "phone": "+525500000001",
  "conversation_id": "synthetic-conv-v130-001",
  "channel_event_id": "synthetic-event-v130-001",
  "channel": "whatsapp",
  "audience": "leo",
  "personalization": {"allowed": true}
}
```

Un teléfono desconocido debe conservar el mismo resultado anti-enumeración que un teléfono elegible: no exponer existencia, datos de cuenta ni contexto. Nonce repetido, timestamp expirado, firma inválida, canal inválido, replay y conflicto de payload son casos negativos esperados y deben conservar `correlation_id`.
