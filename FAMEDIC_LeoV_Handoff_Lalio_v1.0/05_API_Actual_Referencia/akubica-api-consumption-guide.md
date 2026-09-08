# Guía de consumo — API Akubica Famedic v1

**Base URL staging:**

```text
https://staging.famedic.com.mx/api/v1
```

Esta guía sigue el **orden recomendado de pruebas** para integración desde cero. Para detalle paso a paso ver [`akubica-flujo-pruebas-staging.md`](akubica-flujo-pruebas-staging.md).

---

## 1. Autenticación

### 1.1 Registro (empezar aquí)

| Paso | Método | Ruta |
| ---- | ------ | ---- |
| Solicitar OTP | POST | `/auth/register` |
| Verificar OTP | POST | `/auth/register/verify-code` |

**Contrato real `POST /auth/register`:**

| Campo | Tipo | Requerido | Notas |
| ----- | ---- | --------- | ----- |
| `email` | string | sí | Email único |
| `phone` | string | sí | E.164 o nacional; validado con `phone_country` |
| `full_name` | string | sí | Nombre completo (min 3) |
| `phone_country` | string | no | ISO-2, default `MX` |

> **Diferencia vs registro web clásico:** la API Akubica **no recibe** `password`, `birth_date`, `gender` ni `residence_state` en el registro. El acceso es **OTP por correo**; la contraseña de cuenta se genera internamente. Los datos demográficos del paciente para estudios se capturan en **`POST /user/contacts`**.

**Ejemplo:**

```json
{
  "email": "paciente.prueba@example.com",
  "phone": "+528112345678",
  "full_name": "Juan Pérez López",
  "phone_country": "MX"
}
```

**`POST /auth/register/verify-code`:**

| Campo | Requerido |
| ----- | --------- |
| `email` | sí |
| `code` | sí, 6 dígitos |

**Respuesta exitosa:** `data.token`, `data.token_type` (`Bearer`), `data.expires_in`, `data.user`.

### 1.2 Login (usuario existente)

| Paso | Método | Ruta |
| ---- | ------ | ---- |
| Solicitar OTP | POST | `/auth/login/request-code` |
| Verificar OTP | POST | `/auth/login/verify-code` |
| Revocar token | DELETE | `/auth/token` |

Body login: `{ "email": "..." }` y `{ "email", "code" }`.

---

## 2. Perfil usuario

| Método | Ruta | Descripción |
| ------ | ---- | ----------- |
| GET | `/user/family` | Datos familiares / titular |
| GET | `/user/contacts` | Pacientes registrados |
| GET | `/user/addresses` | Direcciones |
| GET | `/user/tax-profiles` | Perfiles fiscales |
| GET | `/user/payment-methods` | Métodos de pago (lectura) |

---

## 3. Catálogo laboratorio

| Orden | Método | Ruta |
| ----- | ------ | ---- |
| 1 | GET | `/catalog/laboratory-brands` |
| 2 | GET | `/catalog/laboratory-test-categories?brand={brand}` |
| 3 | GET | `/catalog/laboratory-tests?brand={brand}&search={q}` |
| 4 | GET | `/catalog/laboratory-stores?brand={brand}` |
| 5 | GET | `/catalog/laboratory-tests/{laboratory_test_id}` |

Marcas: `olab`, `swisslab`, `jenner`, `liacsa`, `azteca`.

---

## 4. Carrito

| Método | Ruta | Notas |
| ------ | ---- | ----- |
| POST | `/cart/items` | body: `brand`, `laboratory_test_id` |
| GET | `/cart?brand={brand}` | |
| GET | `/cart/totals?brand={brand}` | Incluye cupón si aplicado |
| DELETE | `/cart/items/{cart_item_id}` | |
| DELETE | `/cart?brand={brand}` | Vaciar carrito |

---

## 5. Cupones (saldo a favor)

| Método | Ruta |
| ------ | ---- |
| GET | `/cart/coupon?brand={brand}` |
| POST | `/cart/coupon` — body: `brand`, `code` |
| DELETE | `/cart/coupon?brand={brand}` |

Tipo `balance`, no porcentual. Requiere cupón asignado al usuario.

---

## 6. Contactos y direcciones

### Contacto (`POST /user/contacts`)

| Campo | Requerido |
| ----- | --------- |
| `name`, `paternal_lastname`, `maternal_lastname` | sí |
| `phone`, `phone_country` | sí |
| `birth_date` | sí |
| `gender` | sí (`male`/`female` o `1`/`2`) |

### Dirección (`POST /user/addresses`)

| Campo | Requerido |
| ----- | --------- |
| `street`, `number`, `neighborhood` | sí |
| `state`, `city`, `zipcode` | sí — catálogo MX |
| `additional_references` | no |

CRUD completo: GET/POST/PUT/DELETE en `/user/contacts` y `/user/addresses`.

---

## 7. Checkout (sin pago)

| Método | Ruta |
| ------ | ---- |
| GET | `/checkout/prepare?brand={brand}` |
| POST | `/checkout/draft` |

Draft: `brand`, `contact_id`, `address_id`, opcional `tax_profile_id`, `requires_invoice`.

---

## 8. Citas laboratorio

| Método | Ruta |
| ------ | ---- |
| GET | `/laboratory-appointments/requirements?brand={brand}` |
| GET | `/laboratory-appointments` |
| POST | `/laboratory-appointments` |
| DELETE | `/laboratory-appointments/{appointment_id}` |

POST body: `brand`, `contact_id`, `address_id`, `scheduled_at` (ISO8601 futuro).

---

## 9. Payment link

```http
POST /checkout/payment-link
{ "brand": "olab", "expires_in_minutes": 60 }
```

Devuelve URL a Famedic. **No ejecuta pago.**

---

## 10. Pedidos

| Método | Ruta |
| ------ | ---- |
| GET | `/orders` |
| GET | `/orders/{order_id}/status` |
| GET | `/orders/{order_id}/products` |
| GET | `/orders/results` |
| GET | `/orders/{order_id}/results` |
| GET | `/orders/invoices` |
| GET | `/orders/{order_id}/invoices` |

---

## 11. Descargas Bearer-native

| Método | Ruta | Response |
| ------ | ---- | -------- |
| GET | `/orders/{order_id}/results/download` | PDF binario |
| GET | `/orders/{order_id}/invoices/{invoice_id}/download` | PDF binario |

Header: `Authorization: Bearer {token}`, `Accept: application/pdf`.

Metadata JSON incluye `download.type: bearer` y `download.url`.

---

## 12. Facturación

| Método | Ruta |
| ------ | ---- |
| POST | `/user/tax-profiles` |
| GET | `/orders/{order_id}/invoice-request/status` |
| POST | `/orders/{order_id}/invoice-request` |

---

## 13. Desactivados (503)

| Método | Ruta | Código |
| ------ | ---- | ------ |
| GET | `/catalog/medications/{id}` | `CATALOG_UNAVAILABLE` |
| PUT | `/orders/{order_id}/cancel` | `FEATURE_DISABLED` |

---

## Formato de respuesta

**Éxito:** `{ "success": true, "data": { ... } }`  
**Error:** `{ "success": false, "error": { "code": "...", "message": "..." } }`

Códigos comunes: `UNAUTHENTICATED`, `FORBIDDEN`, `VALIDATION_ERROR`, `ORDER_NOT_FOUND`, `EMPTY_CART`, `CHECKOUT_NOT_READY`.

---

## Referencias

- OpenAPI: [`akubica-openapi.yaml`](akubica-openapi.yaml)
- Postman: [`postman/`](postman/)
- Matriz de cobertura: [`akubica-requirements-coverage-matrix.md`](akubica-requirements-coverage-matrix.md)
