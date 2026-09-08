# Checklist — Endpoints disponibles para Akubica

**Base URL staging:** `https://staging.famedic.com.mx/api/v1`  
**Documentación:** `docs/Akubica/guias-y-postman/`  
**Auth:** Bearer token (Sanctum) en rutas protegidas, excepto registro/login.

---

## Configuración inicial

- [ ] Importar colección Postman: `postman/Famedic-Akubica-API-v1.postman_collection.json`
- [ ] Importar environment: `postman/Famedic-Akubica-Staging.postman_environment.json`
- [ ] Configurar `base_url` = `https://staging.famedic.com.mx/api/v1`
- [ ] Leer [`akubica-flujo-pruebas-staging.md`](akubica-flujo-pruebas-staging.md) (orden de pruebas)
- [ ] Confirmar acceso al correo para recibir OTP en staging

**Headers estándar (JSON):**

| Header | Valor |
| ------ | ----- |
| `Accept` | `application/json` |
| `Content-Type` | `application/json` (POST/PUT) |
| `Authorization` | `Bearer {token}` (rutas protegidas) |

---

## 1. Autenticación — sin token

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 1.1 | ☐ | POST | `/auth/register` | Solicitar OTP de registro nuevo |
| 1.2 | ☐ | POST | `/auth/register/verify-code` | Verificar OTP → obtener Bearer token |
| 1.3 | ☐ | POST | `/auth/login/request-code` | Solicitar OTP de login (usuario existente) |
| 1.4 | ☐ | POST | `/auth/login/verify-code` | Verificar OTP login → obtener token |

**Registro — campos mínimos:**

- [ ] `email` (requerido)
- [ ] `phone` (requerido)
- [ ] `full_name` (requerido, nombre completo)
- [ ] `phone_country` (opcional, default `MX`)

**Verify OTP — body:** `{ "email", "code" }`  
**Guardar:** `data.token` para el resto de llamadas.

> **Nota:** La API no recibe `password`, `birth_date`, `gender` ni `residence_state` en el registro. Esos datos van en `POST /user/contacts`.

---

## 2. Autenticación — con token

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 2.1 | ☐ | DELETE | `/auth/token` | Revocar token activo |

---

## 3. Perfil de usuario — lectura

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 3.1 | ☐ | GET | `/user/family` | Datos del titular / familia |
| 3.2 | ☐ | GET | `/user/contacts` | Listar pacientes |
| 3.3 | ☐ | GET | `/user/addresses` | Listar direcciones |
| 3.4 | ☐ | GET | `/user/tax-profiles` | Listar perfiles fiscales |
| 3.5 | ☐ | GET | `/user/payment-methods` | Métodos de pago (solo lectura) |

---

## 4. Contactos (pacientes) — CRUD completo

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 4.1 | ☐ | GET | `/user/contacts` | Listar |
| 4.2 | ☐ | POST | `/user/contacts` | Crear paciente para checkout |
| 4.3 | ☐ | PUT | `/user/contacts/{contact_id}` | Actualizar |
| 4.4 | ☐ | DELETE | `/user/contacts/{contact_id}` | Eliminar |

**Crear contacto — campos mínimos:**

- [ ] `name`, `paternal_lastname`, `maternal_lastname`
- [ ] `phone`, `phone_country`
- [ ] `birth_date`
- [ ] `gender` (`male` / `female` o `1` / `2`)

---

## 5. Direcciones — CRUD completo

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 5.1 | ☐ | GET | `/user/addresses` | Listar |
| 5.2 | ☐ | POST | `/user/addresses` | Crear dirección |
| 5.3 | ☐ | PUT | `/user/addresses/{address_id}` | Actualizar |
| 5.4 | ☐ | DELETE | `/user/addresses/{address_id}` | Eliminar |

**Crear dirección — campos mínimos:**

- [ ] `street`, `number`, `neighborhood`
- [ ] `state`, `city`, `zipcode` (catálogo MX)

---

## 6. Catálogo laboratorio

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 6.1 | ☐ | GET | `/catalog/laboratory-brands` | Listar marcas |
| 6.2 | ☐ | GET | `/catalog/laboratory-test-categories?brand={brand}` | Categorías por marca |
| 6.3 | ☐ | GET | `/catalog/laboratory-tests?brand={brand}&search={q}` | Buscar estudios |
| 6.4 | ☐ | GET | `/catalog/laboratory-stores?brand={brand}` | Sucursales |
| 6.5 | ☐ | GET | `/catalog/laboratory-tests/{laboratory_test_id}` | Detalle de estudio |

**Marcas disponibles:** `olab`, `swisslab`, `jenner`, `liacsa`, `azteca`

---

## 7. Carrito

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 7.1 | ☐ | POST | `/cart/items` | Agregar estudio (`brand`, `laboratory_test_id`) |
| 7.2 | ☐ | GET | `/cart?brand={brand}` | Ver carrito |
| 7.3 | ☐ | GET | `/cart/totals?brand={brand}` | Totales (subtotal, descuentos, total) |
| 7.4 | ☐ | DELETE | `/cart/items/{cart_item_id}` | Quitar ítem |
| 7.5 | ☐ | DELETE | `/cart?brand={brand}` | Vaciar carrito |

---

## 8. Cupones (saldo a favor)

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 8.1 | ☐ | GET | `/cart/coupon?brand={brand}` | Ver cupón aplicado |
| 8.2 | ☐ | POST | `/cart/coupon` | Aplicar cupón (`brand`, `code`) |
| 8.3 | ☐ | DELETE | `/cart/coupon?brand={brand}` | Quitar cupón |

**Notas:**

- [ ] Tipo `balance` (saldo a favor), no porcentual
- [ ] El cupón debe estar asignado al usuario
- [ ] Totales: `coupon_discount_cents` refleja el descuento del cupón

---

## 9. Checkout (sin pago en API)

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 9.1 | ☐ | GET | `/checkout/prepare?brand={brand}` | Validar requisitos previos al pago |
| 9.2 | ☐ | POST | `/checkout/draft` | Guardar borrador (`contact_id`, `address_id`, etc.) |
| 9.3 | ☐ | POST | `/checkout/payment-link` | Generar URL de pago en Famedic |

**Draft — campos típicos:**

- [ ] `brand`
- [ ] `contact_id`
- [ ] `address_id`
- [ ] `tax_profile_id` (opcional)
- [ ] `requires_invoice` (opcional)

**Payment link:**

- [ ] Body: `{ "brand", "expires_in_minutes" }`
- [ ] Respuesta: URL para redirigir al paciente a Famedic
- [ ] **No cobra ni tokeniza tarjetas en la API**

---

## 10. Citas laboratorio

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 10.1 | ☐ | GET | `/laboratory-appointments/requirements?brand={brand}` | Saber si el estudio requiere cita |
| 10.2 | ☐ | GET | `/laboratory-appointments?brand={brand}` | Listar citas |
| 10.3 | ☐ | POST | `/laboratory-appointments` | Crear cita |
| 10.4 | ☐ | DELETE | `/laboratory-appointments/{appointment_id}` | Cancelar cita |

**Crear cita — body:** `brand`, `contact_id`, `address_id`, `scheduled_at` (ISO8601 futuro)

---

## 11. Pedidos — consulta

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 11.1 | ☐ | GET | `/orders` | Listar pedidos del paciente |
| 11.2 | ☐ | GET | `/orders/{order_id}/status` | Estado del pedido |
| 11.3 | ☐ | GET | `/orders/{order_id}/products` | Estudios del pedido |

**Filtros opcionales en listado:** `brand`, `status`, `search`, fechas, paginación (`per_page`, `page`)

---

## 12. Resultados — metadata

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 12.1 | ☐ | GET | `/orders/results` | Listar resultados disponibles |
| 12.2 | ☐ | GET | `/orders/{order_id}/results` | Detalle de resultados del pedido |

**Incluye metadata de descarga:** `download.type: bearer`, `download.url`

---

## 13. Descarga PDF resultados — Bearer native

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 13.1 | ☐ | GET | `/orders/{order_id}/results/download` | Descargar PDF binario |

**Headers:** `Authorization: Bearer {token}`, `Accept: application/pdf`  
**Respuesta:** archivo PDF (no JSON)

---

## 14. Facturas — metadata

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 14.1 | ☐ | GET | `/orders/invoices` | Listar facturas del paciente |
| 14.2 | ☐ | GET | `/orders/{order_id}/invoices` | Facturas de un pedido |

---

## 15. Descarga PDF factura — Bearer native

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 15.1 | ☐ | GET | `/orders/{order_id}/invoices/{invoice_id}/download` | Descargar PDF de factura |

**Headers:** `Authorization: Bearer {token}`, `Accept: application/pdf`

---

## 16. Perfiles fiscales — CRUD + solicitud de factura

| # | Listo | Método | Endpoint | Uso |
| - | ----- | ------ | -------- | --- |
| 16.1 | ☐ | GET | `/user/tax-profiles` | Listar perfiles |
| 16.2 | ☐ | POST | `/user/tax-profiles` | Crear perfil fiscal |
| 16.3 | ☐ | PUT | `/user/tax-profiles/{tax_profile_id}` | Actualizar |
| 16.4 | ☐ | DELETE | `/user/tax-profiles/{tax_profile_id}` | Eliminar |
| 16.5 | ☐ | GET | `/orders/{order_id}/invoice-request/status` | Estado de solicitud |
| 16.6 | ☐ | POST | `/orders/{order_id}/invoice-request` | Solicitar factura (`tax_profile_id`) |

---

## 17. Flujo completo recomendado (smoke test)

- [ ] **1.** Registrar usuario → verify OTP → guardar token
- [ ] **2.** Probar login con el mismo usuario
- [ ] **3.** Consultar perfil (`/user/family`)
- [ ] **4.** Buscar estudio en catálogo
- [ ] **5.** Agregar al carrito → consultar totales
- [ ] **6.** Aplicar cupón (si hay código de prueba)
- [ ] **7.** Crear contacto + dirección
- [ ] **8.** Checkout prepare → draft
- [ ] **9.** Validar si requiere cita → crear cita si aplica
- [ ] **10.** Generar payment link → completar pago en Famedic
- [ ] **11.** Consultar pedidos
- [ ] **12.** Consultar resultados → descargar PDF
- [ ] **13.** Consultar facturas → solicitar factura → descargar PDF
- [ ] **14.** Validar endpoints desactivados (503)

---

## No disponible — no usar

| Endpoint | Respuesta | Motivo |
| -------- | --------- | ------ |
| `GET /catalog/medications/{id}` | **503** `CATALOG_UNAVAILABLE` | Farmacia desactivada |
| `PUT /orders/{order_id}/cancel` | **503** `FEATURE_DISABLED` | Cancelación desactivada |
| Pago con tarjeta en API | — | Solo payment link a Famedic |
| Tokenización de tarjetas | — | Fuera de alcance |
| Subida constancia fiscal PDF vía API | — | Pendiente |
| Descarga XML de factura | — | Pendiente |

---

## Resumen numérico

| Categoría | Endpoints disponibles |
| --------- | --------------------- |
| Auth | 5 |
| Perfil usuario (lectura) | 5 |
| Contactos CRUD | 4 |
| Direcciones CRUD | 4 |
| Catálogo laboratorio | 5 |
| Carrito | 5 |
| Cupones | 3 |
| Checkout | 3 |
| Citas laboratorio | 4 |
| Pedidos | 3 |
| Resultados | 2 + 1 descarga PDF |
| Facturas | 2 + 1 descarga PDF |
| Perfiles fiscales + solicitud | 6 |
| **Total usable** | **~47 rutas** |
| Desactivados | 2 |

---

## Referencias

- Guía paso a paso: [`akubica-flujo-pruebas-staging.md`](akubica-flujo-pruebas-staging.md)
- Referencia endpoints: [`akubica-api-consumption-guide.md`](akubica-api-consumption-guide.md)
- OpenAPI: [`akubica-openapi.yaml`](akubica-openapi.yaml)
- Matriz de cobertura: [`akubica-requirements-coverage-matrix.md`](akubica-requirements-coverage-matrix.md)
- Postman: [`postman/`](postman/)
