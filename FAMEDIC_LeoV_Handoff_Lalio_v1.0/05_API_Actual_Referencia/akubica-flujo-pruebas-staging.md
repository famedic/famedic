# Flujo de pruebas en staging — API Akubica

**Audiencia:** QA / integración Akubica  
**Base URL:**

```text
https://staging.famedic.com.mx/api/v1
```

**Flujo recomendado:**

1. Registrar usuario nuevo.
2. Verificar registro con OTP.
3. Guardar Bearer token.
4. Probar login con el mismo usuario.
5. Continuar con catálogo, carrito, checkout y payment link.
6. Validar post-compra: pedidos, resultados, facturas y descargas Bearer-native.

---

## Convenciones

| Header | Valor |
| ------ | ----- |
| `Accept` | `application/json` (excepto descargas PDF) |
| `Content-Type` | `application/json` en POST/PUT |
| `Authorization` | `Bearer {token}` en rutas protegidas |

Todas las respuestas JSON siguen:

```json
{ "success": true, "data": { } }
```

o

```json
{ "success": false, "error": { "code": "...", "message": "..." } }
```

---

## 1. Registro de usuario nuevo

### Paso 1 — Solicitar OTP de registro

```http
POST /auth/register
```

**Campos requeridos (contrato real API v1):**

| Campo API | Descripción | Validación |
| --------- | ----------- | ---------- |
| `email` | Correo del paciente | requerido, email válido |
| `phone` | Teléfono celular | requerido, formato válido (E.164 o nacional MX) |
| `full_name` | Nombre completo | requerido, min 3 caracteres |
| `phone_country` | País del teléfono | opcional, default `MX` |

> **Nota:** La API Akubica **no solicita contraseña, fecha de nacimiento, sexo ni estado** en el registro. Esos datos se capturan después al crear un **contacto/paciente** para checkout (`POST /user/contacts`). La contraseña de cuenta web se genera internamente; el acceso del asistente es **OTP + Bearer token**.

```bash
curl -X POST "https://staging.famedic.com.mx/api/v1/auth/register" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "paciente.prueba+akubica@example.com",
    "phone": "+528112345678",
    "full_name": "Juan Pérez López",
    "phone_country": "MX"
  }'
```

Respuesta esperada: `200` con `data.verification_sent: true`.

---

## 2. Verificar registro

### Paso 2 — Confirmar OTP

```http
POST /auth/register/verify-code
```

| Campo | Descripción |
| ----- | ----------- |
| `email` | Mismo correo del paso 1 |
| `code` | Código OTP de 6 dígitos |

**Cómo obtener el OTP en staging:**

- Revisar el buzón del correo usado en registro.
- Si el entorno usa captura de correos (Mailhog, log de staging), consultar con el equipo Famedic.
- En pruebas automatizadas internas se usa `Notification::fake`; en staging real llega por email.

```bash
curl -X POST "https://staging.famedic.com.mx/api/v1/auth/register/verify-code" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "paciente.prueba+akubica@example.com",
    "code": "123456"
  }'
```

Respuesta esperada: `200` con:

```json
{
  "success": true,
  "data": {
    "token": "...",
    "token_type": "Bearer",
    "expires_in": 86400,
    "expires_at": "...",
    "user": { "id": 1, "email": "...", "name": "..." }
  }
}
```

**Guardar** `data.token` como `Authorization: Bearer {token}` para el resto de pruebas.

---

## 3. Login con usuario existente

Sección separada del registro — usar el mismo email ya verificado.

### Paso 3 — Solicitar OTP de login

```http
POST /auth/login/request-code
```

```bash
curl -X POST "https://staging.famedic.com.mx/api/v1/auth/login/request-code" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{ "email": "paciente.prueba+akubica@example.com" }'
```

### Paso 4 — Verificar OTP de login

```http
POST /auth/login/verify-code
```

Mismo body que registro (`email` + `code`). Devuelve nuevo Bearer token.

### Revocar token (opcional)

```http
DELETE /auth/token
Authorization: Bearer {token}
```

---

## 4. Perfil de usuario

```http
GET /user/family
GET /user/addresses
GET /user/tax-profiles
GET /user/payment-methods
GET /user/contacts
```

Todas requieren Bearer token.

---

## 5. Catálogo laboratorio

Orden sugerido:

1. `GET /catalog/laboratory-brands`
2. `GET /catalog/laboratory-test-categories?brand=olab`
3. `GET /catalog/laboratory-tests?brand=olab&search=glucosa`
4. `GET /catalog/laboratory-stores?brand=olab`
5. `GET /catalog/laboratory-tests/{laboratory_test_id}`

Anotar un `laboratory_test_id` válido para el carrito.

---

## 6. Carrito

```http
POST /cart/items          # body: brand, laboratory_test_id
GET  /cart?brand=olab
GET  /cart/totals?brand=olab
DELETE /cart/items/{cart_item_id}   # opcional
```

---

## 7. Cupones (saldo a favor)

```http
GET    /cart/coupon?brand=olab
POST   /cart/coupon       # body: brand, code
DELETE /cart/coupon?brand=olab
```

Requiere cupón asignado al usuario en staging. Tipo `balance`, no porcentual.

---

## 8. Contacto y dirección

Antes del checkout, crear paciente y domicilio:

```http
POST /user/contacts
POST /user/addresses
```

**Contacto — campos mínimos:**

| Campo | Requerido |
| ----- | --------- |
| `name` | sí |
| `paternal_lastname` | sí |
| `maternal_lastname` | sí |
| `phone` | sí |
| `phone_country` | sí |
| `birth_date` | sí (YYYY-MM-DD) |
| `gender` | sí (`male`/`female` o `1`/`2`) |

**Dirección — campos mínimos:** `street`, `number`, `neighborhood`, `state`, `city`, `zipcode`.

Guardar `contact_id` y `address_id` de la respuesta.

---

## 9. Checkout (sin pago en API)

```http
GET  /checkout/prepare?brand=olab
POST /checkout/draft
```

Body draft ejemplo:

```json
{
  "brand": "olab",
  "contact_id": 1,
  "address_id": 1
}
```

---

## 10. Citas laboratorio (si aplica)

```http
GET  /laboratory-appointments/requirements?brand=olab
POST /laboratory-appointments
```

Body cita:

```json
{
  "brand": "olab",
  "contact_id": 1,
  "address_id": 1,
  "scheduled_at": "2026-06-15T10:00:00-06:00",
  "notes": "Preferencia mañana"
}
```

Si `checkout/prepare` advierte `REQUIRES_APPOINTMENT`, crear cita antes del payment link.

---

## 11. Payment link (sin cobro)

```http
POST /checkout/payment-link
```

```json
{
  "brand": "olab",
  "expires_in_minutes": 60
}
```

Respuesta: URL hacia Famedic. **El paciente paga en la web**, no en WhatsApp ni en esta API.

---

## 12. Post-compra

### Pedidos

```http
GET /orders
GET /orders/{order_id}/status
GET /orders/{order_id}/products
```

### Resultados

```http
GET /orders/results
GET /orders/{order_id}/results
GET /orders/{order_id}/results/download    # PDF Bearer-native
```

### Facturas

```http
GET /orders/invoices
GET /orders/{order_id}/invoices
GET /orders/{order_id}/invoices/{invoice_id}/download   # PDF Bearer-native
```

### Solicitud de factura

```http
POST /user/tax-profiles          # si no existe perfil fiscal
GET  /orders/{order_id}/invoice-request/status
POST /orders/{order_id}/invoice-request
```

---

## 13. Features desactivadas (503)

Validar que responden 503:

```http
GET /catalog/medications/1           → CATALOG_UNAVAILABLE
PUT /orders/{order_id}/cancel        → FEATURE_DISABLED
```

---

## Checklist rápido

- [ ] Registro + verify-code → token guardado
- [ ] Login request + verify → token renovado
- [ ] Estudio agregado al carrito
- [ ] Contacto y dirección creados
- [ ] Checkout prepare sin warnings bloqueantes
- [ ] Payment link generado
- [ ] Pedido visible en `GET /orders`
- [ ] Descarga PDF resultado/factura con Bearer (si hay datos en staging)
- [ ] Medicamentos y cancelación → 503

---

## Postman

Importar colección y environment de esta carpeta. Ejecutar carpetas `01` → `17` en orden. Los scripts de auth guardan `token` automáticamente en el environment.
