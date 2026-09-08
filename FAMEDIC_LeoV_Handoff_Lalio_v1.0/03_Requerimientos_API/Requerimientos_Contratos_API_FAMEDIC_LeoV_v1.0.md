# Requerimientos de contratos API FAMEDIC–LeoV v1.0

## 1. Principios obligatorios

- API dinámica prevalece para precios, catálogo, disponibilidad, pedidos, resultados y facturas.
- Una ruta existente no equivale a capacidad liberada.
- Toda modificación requiere confirmación previa de LeoV y respuesta exitosa antes de anunciar éxito.
- Toda ruta protegida debe validar titularidad del recurso.
- No exponer contraseñas, OTP, tokens, tarjetas, CVV ni documentos sensibles en texto libre.
- Las operaciones de escritura deben soportar idempotencia.
- Los errores deben ser estables, trazables y seguros para el usuario.

## 2. Autenticación OTP por SMS

### POST /auth/register
Solicita registro y envío de OTP SMS.

**Requerido:** `email`, `phone`, `full_name`, `phone_country`.

**Respuesta pública:** mensaje neutro que no facilite enumeración.

### POST /auth/register/verify-code
Verifica OTP y devuelve token.

### POST /auth/login/request-code
Envía OTP SMS para usuario existente, con respuesta neutra.

### POST /auth/login/verify-code
Valida OTP y devuelve token.

### Política mínima
- TTL: 5 minutos.
- 5 intentos.
- Reenvío después de 60 segundos.
- Máximo 3 reenvíos en 15 minutos.
- Uso único.
- Bloqueo temporal.
- Asociación a usuario, canal, intención y operación.
- Auditoría sin registrar el código.
- Correo como respaldo opcional, no como canal principal de LeoV.

## 3. Acceso seguro a resultados

### POST /orders/{order_id}/results/secure-link

**Controles:**
- Bearer válido.
- OTP SMS adicional o step-up válido.
- Titularidad del pedido.
- TTL configurable, objetivo 60 minutos.
- Token aleatorio, sin datos clínicos en URL.
- Uso único o política explícita de accesos.
- Revocación y registro de apertura/expiración.
- No devolver el PDF a WhatsApp.

**Respuesta sugerida**
```json
{
  "success": true,
  "data": {
    "url": "https://...",
    "expires_at": "2026-07-12T18:00:00-06:00",
    "single_use": true
  },
  "meta": {"correlation_id": "..."}
}
```

## 4. Acceso seguro a factura

### POST /orders/{order_id}/invoices/{invoice_id}/secure-link

Mismos controles que resultados. Validar titularidad del pedido y factura.

## 5. Catálogo de estudios

### GET /catalog/laboratory-tests

Debe soportar:
- `brand`
- `search`
- `category_id`
- `element_count`
- `page`
- `per_page`

Cada resultado debería devolver:
```json
{
  "id": "123",
  "official_name": "Química sanguínea de 28 elementos",
  "aliases": ["QS 28", "Química 28"],
  "element_count": 28,
  "components": ["Glucosa", "Urea"],
  "match_type": "exact|alias|component|approximate",
  "match_score": 0.96,
  "brand": "olab",
  "price": {"currency": "MXN", "public": 0, "famedic": 0},
  "preparation": "...",
  "requires_appointment": false,
  "availability": []
}
```

No debe declarar equivalencia clínica si no existe una equivalencia oficial.

## 6. Citas

El contrato debe incluir:
- `store_id`
- slots consultables
- zona horaria
- `contact_id`
- estudios relacionados
- estado `requested|confirmed|rejected|cancelled`
- reglas de expiración/reprogramación

No usar “confirmada” si el proveedor aún no aceptó el horario.

## 7. Payment link

### POST /checkout/payment-link

Requisitos:
- carrito válido;
- contacto y dirección válidos;
- confirmación del usuario;
- TTL documentado;
- liga de un solo uso o política explícita;
- invalidación cuando cambia el carrito;
- invalidación después del pago;
- no tokenizar ni cobrar en la API;
- idempotencia.

## 8. Idempotencia

Aplicar `Idempotency-Key` a:
- registro;
- contactos;
- direcciones;
- carrito;
- citas;
- perfil fiscal;
- solicitud de factura;
- payment link.

Repetir la misma clave debe devolver el resultado original o un conflicto controlado, nunca duplicar.

## 9. Errores

Formato:
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "No fue posible completar la solicitud.",
    "details": {},
    "retryable": false
  },
  "meta": {"correlation_id": "..."}
}
```

Documentar al menos 400, 401, 403, 404, 409, 422, 429, 500 y 503.

## 10. Escalamiento

Crear un endpoint o integración equivalente:

### POST /support/escalations

Campos mínimos:
- `intent_id`
- `priority`
- `summary`
- `order_id` opcional
- `error_code` opcional
- `authentication_state`
- `channel`
- `user_contact_masked`

No incluir contraseña, OTP, token, tarjeta completa ni resultado clínico.

## 11. Auditoría

Registrar:
- usuario;
- canal WhatsApp;
- intención;
- fecha/hora;
- endpoint;
- operación;
- confirmación;
- resultado;
- código;
- identificador;
- error;
- escalamiento.

## 12. Entregables técnicos

- OpenAPI consolidado.
- Postman sincronizado.
- Environment sin secretos ni variable password no utilizada.
- Ejemplos de requests/responses.
- Catálogo de errores.
- Evidencia de pruebas de seguridad, idempotencia y titularidad.
