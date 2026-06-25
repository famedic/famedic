# Especificación de APIs faltantes — Famedic Mobile (Flutter)

**Rol:** Arquitecto de software  
**Base:** Laravel 11 existente (lógica en Actions/Services; hoy solo web + sesión)  
**Convención propuesta:** prefijo `/api/v1`, respuestas JSON envueltas, autenticación **Sanctum Bearer** (a implementar)  
**Fecha:** Junio 2025

---

## Middleware estándar (referencia)

| Alias | Uso en API móvil |
|-------|------------------|
| `auth:sanctum` | Usuario autenticado con token |
| `throttle:api` | Rate limiting |
| `customer` | Asegura cuenta `Customer` |
| `verified` | Email verificado |
| `phone-verified` | Teléfono verificado |
| `documentation` | TOS/privacidad aceptados |
| `profile.complete` | Perfil completo (sustituye redirect web) |
| `membership.active` | Membresía activa (familia, telemedicina) |

**Stack autenticado completo (referencia):**
`auth:sanctum`, `customer`, `verified`, `phone-verified`, `documentation`, `profile.complete`

### Envelope de respuesta (convención)

```json
{
  "success": true,
  "message": "string opcional",
  "data": {},
  "meta": {}
}
```

```json
{
  "success": false,
  "message": "Error descriptivo",
  "errors": { "campo": ["mensaje"] }
}
```

---

## 1. Login / Autenticación

### 1.1 Login

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/login` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P0** |

**Request:**
```json
{
  "email": "usuario@ejemplo.com",
  "password": "********",
  "device_name": "iPhone 15 - Flutter"
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "token": "1|abc...",
    "token_type": "Bearer",
    "expires_at": "2026-12-07T00:00:00Z",
    "user": {
      "id": 1,
      "email": "usuario@ejemplo.com",
      "profile_is_complete": true,
      "email_verified_at": "2026-01-01T00:00:00Z",
      "phone_verified_at": "2026-01-01T00:00:00Z",
      "documentation_accepted_at": "2026-01-01T00:00:00Z"
    },
    "onboarding": {
      "requires_complete_profile": false,
      "requires_email_verification": false,
      "requires_phone_verification": false,
      "requires_documentation_accept": false
    }
  }
}
```

---

### 1.2 Logout

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/logout` |
| **Middleware** | `auth:sanctum` |
| **Prioridad** | **P0** |

**Request:** `{}` o `{ "revoke_all_devices": false }`

**Response 200:**
```json
{ "success": true, "message": "Sesión cerrada" }
```

---

### 1.3 Refresh token

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/refresh` |
| **Middleware** | `auth:sanctum` |
| **Prioridad** | **P0** |

**Request:**
```json
{ "device_name": "iPhone 15 - Flutter" }
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "token": "2|xyz...",
    "token_type": "Bearer",
    "expires_at": "2026-12-07T00:00:00Z"
  }
}
```

---

### 1.4 Usuario autenticado (me)

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/auth/me` |
| **Middleware** | `auth:sanctum`, `customer` |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Juan",
    "paternal_lastname": "Pérez",
    "maternal_lastname": "García",
    "full_name": "Juan Pérez García",
    "email": "usuario@ejemplo.com",
    "phone": "+525551234567",
    "phone_country": "MX",
    "birth_date": "1990-05-15",
    "gender": "male",
    "state": "NL",
    "profile_photo_url": "https://...",
    "profile_is_complete": true,
    "email_verified_at": "2026-01-01T00:00:00Z",
    "phone_verified_at": "2026-01-01T00:00:00Z",
    "documentation_accepted_at": "2026-01-01T00:00:00Z",
    "customer_id": 10,
    "membership_active": true,
    "pending_results_count": 2,
    "unread_notifications_count": 5
  }
}
```

---

### 1.5 Olvidé contraseña

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/forgot-password` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P1** |

**Request:** `{ "email": "usuario@ejemplo.com" }`

**Response 200:**
```json
{ "success": true, "message": "Si el correo existe, enviamos instrucciones" }
```

---

### 1.6 Restablecer contraseña

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/reset-password` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P1** |

**Request:**
```json
{
  "token": "reset-token-from-email",
  "email": "usuario@ejemplo.com",
  "password": "nuevaPassword1!",
  "password_confirmation": "nuevaPassword1!"
}
```

**Response 200:**
```json
{ "success": true, "message": "Contraseña actualizada" }
```

---

### 1.7 Reenviar verificación email

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/verify-email/resend` |
| **Middleware** | `auth:sanctum`, `throttle:6,1` |
| **Prioridad** | **P0** |

**Response 200:**
```json
{ "success": true, "message": "Correo de verificación enviado" }
```

---

### 1.8 Enviar OTP verificación teléfono

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/verify-phone/send` |
| **Middleware** | `auth:sanctum`, `throttle:6,1` |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": { "expires_in": 300, "resend_in": 60 }
}
```

---

### 1.9 Confirmar OTP teléfono

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/verify-phone/confirm` |
| **Middleware** | `auth:sanctum`, `throttle:6,1` |
| **Prioridad** | **P0** |

**Request:** `{ "code": "123456" }`

**Response 200:**
```json
{
  "success": true,
  "data": { "phone_verified_at": "2026-06-07T12:00:00Z" }
}
```

---

### 1.10 Login con Google

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/google` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P2** |

**Request:**
```json
{
  "id_token": "google-id-token",
  "device_name": "Pixel 8 - Flutter"
}
```

**Response 200:** igual estructura que login.

---

## 2. Registro

### 2.1 Registro de usuario

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/auth/register` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P0** |

**Request** (basado en `RegisterRequest`):
```json
{
  "name": "Juan",
  "paternal_lastname": "Pérez",
  "maternal_lastname": "García",
  "email": "nuevo@ejemplo.com",
  "phone": "5551234567",
  "phone_country": "MX",
  "birth_date": "1990-05-15",
  "gender": "male",
  "state": "NL",
  "password": "Password1!",
  "password_confirmation": "Password1!",
  "referrer_id": null,
  "device_name": "iPhone 15 - Flutter"
}
```

**Response 201:**
```json
{
  "success": true,
  "data": {
    "token": "1|abc...",
    "user": { "id": 2, "email": "nuevo@ejemplo.com" },
    "onboarding": {
      "requires_email_verification": true,
      "requires_phone_verification": true,
      "requires_documentation_accept": true
    }
  }
}
```

---

### 2.2 Aceptar documentación legal

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/documentation/accept` |
| **Middleware** | `auth:sanctum`, `customer` |
| **Prioridad** | **P0** |

**Request:**
```json
{
  "accept_terms": true,
  "accept_privacy": true
}
```

**Response 200:**
```json
{
  "success": true,
  "data": { "documentation_accepted_at": "2026-06-07T12:00:00Z" }
}
```

---

### 2.3 Obtener documentos legales

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/documentation` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P1** |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "terms_url": "https://famedic.com/terms-of-service",
    "privacy_url": "https://famedic.com/privacy-policy",
    "version": "2026-03-01"
  }
}
```

---

## 3. Perfil

### 3.1 Completar perfil

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/profile/complete` |
| **Middleware** | `auth:sanctum`, `customer` |
| **Prioridad** | **P0** |

**Request** (`StoreCompleteProfileRequest`):
```json
{
  "name": "Juan",
  "paternal_lastname": "Pérez",
  "maternal_lastname": "García",
  "birth_date": "1990-05-15",
  "gender": "male",
  "email": "usuario@ejemplo.com",
  "phone": "5551234567",
  "phone_country": "MX"
}
```

**Response 200:** objeto `user` actualizado + `profile_is_complete: true`.

---

### 3.2 Actualizar datos básicos

| Campo | Valor |
|-------|-------|
| **Método** | `PUT` |
| **Endpoint** | `/api/v1/profile/basic-info` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P1** |

**Request:**
```json
{
  "name": "Juan",
  "paternal_lastname": "Pérez",
  "maternal_lastname": "García",
  "birth_date": "1990-05-15",
  "gender": "male"
}
```

---

### 3.3 Actualizar contacto

| Campo | Valor |
|-------|-------|
| **Método** | `PUT` |
| **Endpoint** | `/api/v1/profile/contact-info` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P1** |

**Request:**
```json
{
  "email": "nuevo@ejemplo.com",
  "phone": "5559876543",
  "phone_country": "MX"
}
```

---

### 3.4 Estado de onboarding

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/profile/onboarding-status` |
| **Middleware** | `auth:sanctum`, `customer` |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "profile_is_complete": false,
    "email_verified": false,
    "phone_verified": false,
    "documentation_accepted": false,
    "next_step": "complete_profile"
  }
}
```

---

### 3.5 Direcciones

| Endpoint | Método | Prioridad | Middleware |
|----------|--------|-----------|------------|
| `/api/v1/addresses` | `GET` | P1 | stack autenticado completo |
| `/api/v1/addresses` | `POST` | P1 | stack autenticado completo |
| `/api/v1/addresses/{id}` | `PUT` | P1 | stack autenticado completo |
| `/api/v1/addresses/{id}` | `DELETE` | P1 | stack autenticado completo |

**POST Request** (`StoreAddressRequest`):
```json
{
  "street": "Av. Reforma",
  "number": "123",
  "neighborhood": "Centro",
  "state": "NL",
  "city": "Monterrey",
  "zipcode": "64000",
  "additional_references": "Entre X y Y"
}
```

**GET Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "street": "Av. Reforma",
      "number": "123",
      "neighborhood": "Centro",
      "state": "NL",
      "city": "Monterrey",
      "zipcode": "64000",
      "full_address": "Av. Reforma 123, Centro, Monterrey, NL"
    }
  ]
}
```

---

### 3.6 Contactos (pacientes)

| Endpoint | Método | Prioridad | Middleware |
|----------|--------|-----------|------------|
| `/api/v1/contacts` | `GET` | P1 | stack autenticado completo |
| `/api/v1/contacts` | `POST` | P1 | stack autenticado completo |
| `/api/v1/contacts/{id}` | `PUT` | P2 | stack autenticado completo |
| `/api/v1/contacts/{id}` | `DELETE` | P2 | stack autenticado completo |

**POST Request** (`StoreContactRequest`):
```json
{
  "name": "María",
  "paternal_lastname": "López",
  "maternal_lastname": "Hernández",
  "phone": "5551112233",
  "phone_country": "MX",
  "birth_date": "1985-03-20",
  "gender": "female"
}
```

---

## 4. Laboratorios

### 4.1 Marcas de laboratorio

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/laboratories/brands` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "value": "swisslab",
      "label": "Swisslab",
      "image_url": "https://.../GDA-SWISSLAB.png",
      "states": ["Nuevo León"]
    }
  ]
}
```

---

### 4.2 Catálogo de estudios

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/laboratories/{brand}/tests` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P0** |

**Query:** `?search=glucosa&category_id=1&page=1`

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": 42,
      "name": "Biometría hemática",
      "description": "...",
      "price_cents": 35000,
      "formatted_price": "$350.00",
      "requires_appointment": false,
      "category": { "id": 1, "name": "Análisis clínicos" }
    }
  ],
  "meta": { "current_page": 1, "last_page": 5, "total": 48 }
}
```

---

### 4.3 Detalle de estudio

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/laboratories/tests/{id}` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P0** |

---

### 4.4 Sucursales

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/laboratories/{brand}/stores` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P1** |

**Query:** `?state=NL`

---

### 4.5 Carrito — consultar

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/laboratories/{brand}/cart` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 7,
        "laboratory_test_id": 42,
        "name": "Biometría hemática",
        "price_cents": 35000,
        "requires_appointment": false
      }
    ],
    "subtotal_cents": 35000,
    "discount_cents": 0,
    "total_cents": 35000,
    "requires_appointment": false,
    "item_count": 1
  }
}
```

---

### 4.6 Carrito — agregar ítem

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/laboratories/{brand}/cart/items` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Request:** `{ "laboratory_test_id": 42 }`

---

### 4.7 Carrito — eliminar ítem

| Campo | Valor |
|-------|-------|
| **Método** | `DELETE` |
| **Endpoint** | `/api/v1/laboratories/cart/items/{cart_item_id}` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

---

### 4.8 Checkout — sincronizar borrador

| Campo | Valor |
|-------|-------|
| **Método** | `PUT` |
| **Endpoint** | `/api/v1/laboratories/{brand}/checkout/draft` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Request** (`SyncLaboratoryCheckoutDraftRequest`):
```json
{
  "step": "payment",
  "contact_id": 3,
  "address_id": 1,
  "payment_method": "15",
  "coupon_id": null
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "step": "payment",
    "contact_id": 3,
    "address_id": 1,
    "payment_method": "15",
    "totals": {
      "subtotal_cents": 35000,
      "coupon_discount_cents": 0,
      "total_cents": 35000
    },
    "allowed_payment_methods": ["15", "odessa"]
  }
}
```

---

### 4.9 Checkout — crear orden

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/laboratories/{brand}/checkout` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Request** (`StoreLaboratoryPurchaseRequest`):
```json
{
  "total": 35000,
  "address_id": 1,
  "contact_id": 3,
  "laboratory_appointment_id": null,
  "payment_method": "15",
  "coupon_id": null
}
```

**Response 201:**
```json
{
  "success": true,
  "data": {
    "order": {
      "id": 101,
      "type": "laboratory_purchase",
      "brand": "swisslab",
      "status": "confirmed",
      "total_cents": 35000,
      "gda_order_id": "ORD-12345",
      "requires_appointment": false,
      "created_at": "2026-06-07T12:00:00Z"
    },
    "payment": {
      "status": "completed",
      "transaction_id": 500,
      "payment_method": "efevoopay"
    }
  }
}
```

---

### 4.10 Citas de laboratorio — crear

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/laboratories/{brand}/appointments` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P1** |

**Response 201:**
```json
{
  "success": true,
  "data": {
    "id": 20,
    "status": "pending",
    "laboratory_brand": "swisslab",
    "contact_id": 3
  }
}
```

---

### 4.11 Citas — consultar / cancelar

| Endpoint | Método | Prioridad |
|----------|--------|-----------|
| `/api/v1/laboratories/appointments/{id}` | `GET` | P1 |
| `/api/v1/laboratories/appointments/{id}` | `DELETE` | P2 |

---

### 4.12 Cotización (quote)

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/laboratories/{brand}/quotes` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P2** |

---

## 5. Órdenes (unificado)

### 5.1 Listar órdenes

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/orders` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Query:** `?type=laboratory|pharmacy|membership&page=1&search=`

**Response 200** (basado en `PatientLaboratoryPurchaseCardResource`):
```json
{
  "success": true,
  "data": [
    {
      "id": 101,
      "type": "laboratory_purchase",
      "patient_name": "Juan Pérez García",
      "study_name": "Biometría hemática",
      "study_status": "in_progress",
      "study_status_label": "En proceso",
      "laboratory_name": "Swisslab",
      "formatted_total": "$350.00",
      "purchased_at": "2026-06-07T12:00:00Z",
      "has_results": false,
      "is_new_result": false,
      "requires_appointment": false,
      "has_appointment_scheduled": false
    }
  ],
  "meta": { "current_page": 1, "last_page": 3, "total": 25 }
}
```

---

### 5.2 Detalle de orden

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/orders/{type}/{id}` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Tipos:** `laboratory`, `pharmacy`, `membership`

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id": 101,
    "type": "laboratory_purchase",
    "items": [{ "name": "Biometría hemática", "price_cents": 35000 }],
    "address": { "id": 1, "full_address": "..." },
    "contact": { "id": 3, "full_name": "Juan Pérez" },
    "transaction": {
      "payment_method": "efevoopay",
      "amount_cents": 35000,
      "status": "completed"
    },
    "invoice": null,
    "invoice_requested": false
  }
}
```

---

### 5.3 Solicitar factura

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/orders/laboratory/{id}/invoice-request` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P2** |

**Request:** `{ "tax_profile_id": 2 }`

---

## 6. Resultados de laboratorio

### 6.1 Listar resultados disponibles

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/laboratory-results` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "order_id": 101,
      "patient_name": "Juan Pérez",
      "study_name": "Biometría hemática",
      "status": "results_ready",
      "is_new": true,
      "results_available_at": "2026-06-06T10:00:00Z"
    }
  ]
}
```

---

### 6.2 Estado OTP de resultados

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/laboratory-results/{order_id}/otp/status` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "verified": false,
    "expires_in": 0,
    "trust_minutes": 15
  }
}
```

---

### 6.3 Enviar OTP resultados

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/laboratory-results/{order_id}/otp/send` |
| **Middleware** | stack autenticado completo, `throttle:12,1` |
| **Prioridad** | **P0** |

**Request:** `{ "channel": "sms" }` o `"email"`

**Response 200:**
```json
{
  "success": true,
  "data": {
    "sent": true,
    "channel": "sms",
    "expires_in": 300,
    "resend_in": 60,
    "max_attempts": 5
  }
}
```

---

### 6.4 Verificar OTP resultados

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/laboratory-results/{order_id}/otp/verify` |
| **Middleware** | stack autenticado completo, `throttle:12,1` |
| **Prioridad** | **P0** |

**Request:** `{ "code": "123456" }`

**Response 200:**
```json
{
  "success": true,
  "data": {
    "verified": true,
    "trust_expires_in": 900
  }
}
```

---

### 6.5 Obtener PDF de resultados

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/laboratory-results/{order_id}/download` |
| **Middleware** | stack autenticado completo, `lab-results-otp-verified` |
| **Prioridad** | **P0** |

**Response 200 (opción A — base64):**
```json
{
  "success": true,
  "data": {
    "pdf_base64": "JVBERi0...",
    "filename": "resultados-101.pdf",
    "cached": true
  }
}
```

**Response 200 (opción B — binario):** `Content-Type: application/pdf` (recomendado para Flutter).

---

### 6.6 Refrescar resultados desde GDA

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/laboratory-results/{order_id}/refresh` |
| **Middleware** | stack autenticado completo, `lab-results-otp-verified` |
| **Prioridad** | **P2** |

---

### 6.7 Marcar resultado como leído

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/laboratory-results/notifications/{notification_id}/read` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P1** |

**Response:** `{ "success": true }`

---

## 7. Membresías (Atención Médica)

### 7.1 Estado de membresía

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/memberships/status` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "is_active": true,
    "expires_at": "2027-06-07T00:00:00Z",
    "type": "regular",
    "formatted_price": "$299.00",
    "trial_available": false,
    "family_slots_used": 1,
    "family_slots_max": 4
  }
}
```

---

### 7.2 Planes / precio

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/memberships/plans` |
| **Middleware** | `throttle:api` |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "regular": {
      "price_cents": 29900,
      "formatted_price": "$299.00",
      "billing_period": "annual",
      "trial_days": 0
    }
  }
}
```

---

### 7.3 Suscribirse (pago)

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/memberships/subscribe` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Request** (`MedicalAttentionSubscriptionRequest`):
```json
{
  "payment_method": "15"
}
```

**Response 201:**
```json
{
  "success": true,
  "data": {
    "subscription": {
      "id": 50,
      "type": "regular",
      "start_date": "2026-06-07",
      "end_date": "2027-06-07",
      "is_active": true
    },
    "murguia_sync_status": "pending"
  }
}
```

---

### 7.4 Trial gratuito

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/memberships/trial` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P1** |

---

### 7.5 Historial de membresías

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/memberships/history` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P2** |

---

### 7.6 Cuentas familiares

| Endpoint | Método | Prioridad | Middleware |
|----------|--------|-----------|------------|
| `/api/v1/memberships/family` | `GET` | P1 | stack + `membership.active` |
| `/api/v1/memberships/family` | `POST` | P1 | stack + `membership.active` |
| `/api/v1/memberships/family/{id}` | `PUT` | P2 | stack + `membership.active` |
| `/api/v1/memberships/family/{id}` | `DELETE` | P2 | stack + `membership.active` |

**POST Request** (`StoreFamilyAccountRequest`):
```json
{
  "name": "Ana",
  "paternal_lastname": "Pérez",
  "maternal_lastname": "García",
  "birth_date": "2015-08-10",
  "gender": "female",
  "kinship": "child"
}
```

---

## 8. Farmacias

> Módulo **deshabilitado en web** hoy. Prioridad global **P1** (P2 si no entra en v1).

### 8.1 Buscar productos

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/pharmacy/products` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P1** |

**Query:** `?search=paracetamol&category=analgesicos&page=1`

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": 9001,
      "name": "Paracetamol 500mg",
      "price_cents": 4500,
      "image_url": "https://...",
      "requires_prescription": false,
      "stock_available": true
    }
  ],
  "meta": { "current_page": 1, "next_page": 2 }
}
```

---

### 8.2 Categorías

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/pharmacy/categories` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P1** |

---

### 8.3 Carrito farmacia

| Endpoint | Método | Prioridad |
|----------|--------|-----------|
| `/api/v1/pharmacy/cart` | `GET` | P1 |
| `/api/v1/pharmacy/cart/items` | `POST` | P1 |
| `/api/v1/pharmacy/cart/items/{id}` | `PUT` | P2 |
| `/api/v1/pharmacy/cart/items/{id}` | `DELETE` | P1 |

**POST Request:** `{ "vitau_product_id": 9001, "quantity": 2 }`

---

### 8.4 Checkout farmacia

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/pharmacy/checkout` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P1** |

**Request** (`StoreOnlinePharmacyPurchaseRequest`):
```json
{
  "total": 9000,
  "address_id": 1,
  "contact_id": 3,
  "payment_method": "15"
}
```

---

## 9. Notificaciones

### 9.1 Listar notificaciones in-app

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/notifications` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Query:** `?page=1&unread_only=false`

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "type": "lab_results_ready",
      "title": "Resultados disponibles",
      "message": "Tus resultados de Swisslab ya están listos",
      "is_read": false,
      "created_at": "2026-06-07T08:00:00Z",
      "action": {
        "type": "navigate",
        "route": "laboratory_results",
        "params": { "order_id": 101 }
      }
    }
  ],
  "meta": { "unread_count": 5 }
}
```

---

### 9.2 Contador no leídas

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/notifications/unread-count` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Response:** `{ "success": true, "data": { "count": 5 } }`

---

### 9.3 Marcar como leída / todas

| Endpoint | Método | Prioridad |
|----------|--------|-----------|
| `/api/v1/notifications/{id}/read` | `POST` | P0 |
| `/api/v1/notifications/read-all` | `POST` | P1 |

---

### 9.4 Registrar dispositivo push (FCM/APNs)

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/devices` |
| **Middleware** | `auth:sanctum`, `customer` |
| **Prioridad** | **P1** |

**Request:**
```json
{
  "fcm_token": "firebase-token",
  "platform": "android",
  "device_name": "Pixel 8"
}
```

---

### 9.5 Eliminar dispositivo push

| Campo | Valor |
|-------|-------|
| **Método** | `DELETE` |
| **Endpoint** | `/api/v1/devices/{token}` |
| **Middleware** | `auth:sanctum` |
| **Prioridad** | **P2** |

---

## 10. Pagos (soporte transversal)

### 10.1 Listar métodos de pago

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/payment-methods` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "cards": [
      {
        "id": "15",
        "type": "efevoopay",
        "card_last_four": "0969",
        "card_brand": "mastercard",
        "alias": "Tarjeta principal",
        "expires_at": "2028-11-30"
      }
    ],
    "coupon_balance_cents": 5000,
    "has_odessa_account": false
  }
}
```

---

### 10.2 Agregar tarjeta (iniciar 3DS)

| Campo | Valor |
|-------|-------|
| **Método** | `POST` |
| **Endpoint** | `/api/v1/payment-methods` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Request:**
```json
{
  "card_number": "5267772159330969",
  "exp_month": "11",
  "exp_year": "2028",
  "cvv": "123",
  "card_holder": "JUAN PEREZ",
  "alias": "Mi tarjeta"
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "session_id": "uuid-3ds",
    "requires_3ds": true,
    "redirect_url": "https://famedic.com/payment-methods/3ds/redirect/uuid-3ds",
    "status": "pending"
  }
}
```

---

### 10.3 Polling estado 3DS

| Campo | Valor |
|-------|-------|
| **Método** | `GET` |
| **Endpoint** | `/api/v1/payment-methods/3ds/{session_id}/status` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P0** |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "final": true,
    "status": "completed",
    "message": "Tarjeta verificada correctamente",
    "payment_method_id": "16"
  }
}
```

---

### 10.4 Eliminar método de pago

| Campo | Valor |
|-------|-------|
| **Método** | `DELETE` |
| **Endpoint** | `/api/v1/payment-methods/{id}` |
| **Middleware** | stack autenticado completo |
| **Prioridad** | **P1** |

---

### 10.5 PayPal — crear / capturar orden (lab)

| Endpoint | Método | Prioridad |
|----------|--------|-----------|
| `/api/v1/payments/paypal/create-order` | `POST` | P1 |
| `/api/v1/payments/paypal/capture-order` | `POST` | P1 |

**Request create-order:**
```json
{
  "laboratory_brand": "swisslab",
  "address_id": 1,
  "contact_id": 3,
  "total": 35000,
  "coupon_id": null
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "paypal_order_id": "ORDER-ID",
    "approval_url": "https://paypal.com/..."
  }
}
```

---

## Resumen por prioridad

### P0 — Imprescindible para lanzar (~37 endpoints)

| Módulo | Endpoints clave |
|--------|-----------------|
| Auth | login, logout, refresh, me, verify email/phone |
| Registro | register, accept documentation |
| Perfil | complete profile, onboarding-status |
| Laboratorios | brands, tests, cart, checkout, crear orden |
| Órdenes | list, detail |
| Resultados | list, OTP status/send/verify, download |
| Membresías | status, plans, subscribe |
| Notificaciones | list, unread-count, mark read |
| Pagos | list methods, add card, 3DS status |

### P1 — Importante (~28 endpoints)

Auth forgot/reset, documentación, direcciones, contactos, citas lab, resultados mark-read, membresía trial/familia, farmacia completa, push devices, PayPal, eliminar tarjeta, perfil update.

### P2 — Opcional (~12 endpoints)

Google login, quotes lab, refresh GDA, historial membresías, solicitud factura, eliminar contactos, cancelar citas, actualizar carrito farmacia, delete push device.

---

## Tabla resumen de todos los endpoints

| # | Método | Endpoint | Prioridad |
|---|--------|----------|-----------|
| 1 | POST | `/api/v1/auth/login` | P0 |
| 2 | POST | `/api/v1/auth/logout` | P0 |
| 3 | POST | `/api/v1/auth/refresh` | P0 |
| 4 | GET | `/api/v1/auth/me` | P0 |
| 5 | POST | `/api/v1/auth/forgot-password` | P1 |
| 6 | POST | `/api/v1/auth/reset-password` | P1 |
| 7 | POST | `/api/v1/auth/verify-email/resend` | P0 |
| 8 | POST | `/api/v1/auth/verify-phone/send` | P0 |
| 9 | POST | `/api/v1/auth/verify-phone/confirm` | P0 |
| 10 | POST | `/api/v1/auth/google` | P2 |
| 11 | POST | `/api/v1/auth/register` | P0 |
| 12 | POST | `/api/v1/documentation/accept` | P0 |
| 13 | GET | `/api/v1/documentation` | P1 |
| 14 | POST | `/api/v1/profile/complete` | P0 |
| 15 | PUT | `/api/v1/profile/basic-info` | P1 |
| 16 | PUT | `/api/v1/profile/contact-info` | P1 |
| 17 | GET | `/api/v1/profile/onboarding-status` | P0 |
| 18 | GET | `/api/v1/addresses` | P1 |
| 19 | POST | `/api/v1/addresses` | P1 |
| 20 | PUT | `/api/v1/addresses/{id}` | P1 |
| 21 | DELETE | `/api/v1/addresses/{id}` | P1 |
| 22 | GET | `/api/v1/contacts` | P1 |
| 23 | POST | `/api/v1/contacts` | P1 |
| 24 | PUT | `/api/v1/contacts/{id}` | P2 |
| 25 | DELETE | `/api/v1/contacts/{id}` | P2 |
| 26 | GET | `/api/v1/laboratories/brands` | P0 |
| 27 | GET | `/api/v1/laboratories/{brand}/tests` | P0 |
| 28 | GET | `/api/v1/laboratories/tests/{id}` | P0 |
| 29 | GET | `/api/v1/laboratories/{brand}/stores` | P1 |
| 30 | GET | `/api/v1/laboratories/{brand}/cart` | P0 |
| 31 | POST | `/api/v1/laboratories/{brand}/cart/items` | P0 |
| 32 | DELETE | `/api/v1/laboratories/cart/items/{id}` | P0 |
| 33 | PUT | `/api/v1/laboratories/{brand}/checkout/draft` | P0 |
| 34 | POST | `/api/v1/laboratories/{brand}/checkout` | P0 |
| 35 | POST | `/api/v1/laboratories/{brand}/appointments` | P1 |
| 36 | GET | `/api/v1/laboratories/appointments/{id}` | P1 |
| 37 | DELETE | `/api/v1/laboratories/appointments/{id}` | P2 |
| 38 | POST | `/api/v1/laboratories/{brand}/quotes` | P2 |
| 39 | GET | `/api/v1/orders` | P0 |
| 40 | GET | `/api/v1/orders/{type}/{id}` | P0 |
| 41 | POST | `/api/v1/orders/laboratory/{id}/invoice-request` | P2 |
| 42 | GET | `/api/v1/laboratory-results` | P0 |
| 43 | GET | `/api/v1/laboratory-results/{order_id}/otp/status` | P0 |
| 44 | POST | `/api/v1/laboratory-results/{order_id}/otp/send` | P0 |
| 45 | POST | `/api/v1/laboratory-results/{order_id}/otp/verify` | P0 |
| 46 | GET | `/api/v1/laboratory-results/{order_id}/download` | P0 |
| 47 | POST | `/api/v1/laboratory-results/{order_id}/refresh` | P2 |
| 48 | POST | `/api/v1/laboratory-results/notifications/{id}/read` | P1 |
| 49 | GET | `/api/v1/memberships/status` | P0 |
| 50 | GET | `/api/v1/memberships/plans` | P0 |
| 51 | POST | `/api/v1/memberships/subscribe` | P0 |
| 52 | POST | `/api/v1/memberships/trial` | P1 |
| 53 | GET | `/api/v1/memberships/history` | P2 |
| 54 | GET | `/api/v1/memberships/family` | P1 |
| 55 | POST | `/api/v1/memberships/family` | P1 |
| 56 | PUT | `/api/v1/memberships/family/{id}` | P2 |
| 57 | DELETE | `/api/v1/memberships/family/{id}` | P2 |
| 58 | GET | `/api/v1/pharmacy/products` | P1 |
| 59 | GET | `/api/v1/pharmacy/categories` | P1 |
| 60 | GET | `/api/v1/pharmacy/cart` | P1 |
| 61 | POST | `/api/v1/pharmacy/cart/items` | P1 |
| 62 | PUT | `/api/v1/pharmacy/cart/items/{id}` | P2 |
| 63 | DELETE | `/api/v1/pharmacy/cart/items/{id}` | P1 |
| 64 | POST | `/api/v1/pharmacy/checkout` | P1 |
| 65 | GET | `/api/v1/notifications` | P0 |
| 66 | GET | `/api/v1/notifications/unread-count` | P0 |
| 67 | POST | `/api/v1/notifications/{id}/read` | P0 |
| 68 | POST | `/api/v1/notifications/read-all` | P1 |
| 69 | POST | `/api/v1/devices` | P1 |
| 70 | DELETE | `/api/v1/devices/{token}` | P2 |
| 71 | GET | `/api/v1/payment-methods` | P0 |
| 72 | POST | `/api/v1/payment-methods` | P0 |
| 73 | GET | `/api/v1/payment-methods/3ds/{session_id}/status` | P0 |
| 74 | DELETE | `/api/v1/payment-methods/{id}` | P1 |
| 75 | POST | `/api/v1/payments/paypal/create-order` | P1 |
| 76 | POST | `/api/v1/payments/paypal/capture-order` | P1 |

---

## Dependencias arquitectónicas (previas al desarrollo)

1. **Sanctum** — `HasApiTokens` en `User`, migración `personal_access_tokens`, middleware `auth:sanctum`.
2. **Middleware API** — versiones JSON de redirects web (`profile.complete`, `documentation`) que respondan 403/422 en lugar de redirect.
3. **API Resources** — formalizar `PatientLaboratoryPurchaseCardResource`, `OrderResource`, `NotificationResource`, `UserResource`.
4. **Versionado** — `/api/v1/` desde el inicio.
5. **Reutilización** — envolver Actions existentes:
   - `PurchaseRegularSubscriptionAction`
   - `CalculateTotalsAndDiscountAction`
   - `ResolveGdaResultsPdfAction`
   - `CreateRegularAccountCustomerAction`
   - `FetchProductsAction` (farmacia Vitau)

---

## Mapa de cobertura vs. backend actual

| Módulo Flutter | Existe hoy | Falta construir |
|----------------|------------|-----------------|
| Login | Lógica web (sesión) | 100% API token |
| Registro | `RegisterRequest` + web | API + token |
| Perfil | Controladores web | API JSON |
| Laboratorios | Flujo web completo | ~90% API |
| Órdenes | `LaboratoryPurchaseController` | API unificada |
| Resultados | OTP AJAX parcial | API formal + download |
| Membresías | `MedicalAttentionSubscriptionController` | API |
| Farmacias | Vitau integrado, rutas off | 100% API |
| Notificaciones | Solo mark-read web | list + push |

---

## Documentos relacionados

- [`resumen-de-famedic.md`](resumen-de-famedic.md) — Auditoría general del proyecto
- [`auditoria-rutas-api.md`](auditoria-rutas-api.md) — Inventario de rutas API existentes
