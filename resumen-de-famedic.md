# Resumen de Famedic

**Proyecto:** Famedic — Backend Laravel para consumo por app móvil Flutter  
**Stack:** Laravel 11, PHP 8.3, Inertia.js, Vue/React (SPA web)  
**Fecha de auditoría:** Junio 2025

---

## Conclusión ejecutiva

Famedic es una **aplicación web orientada a sesiones** (Inertia + cookies), no una API REST diseñada para móvil. Las rutas bajo `/api` son limitadas: pagos EfevooPay, webhooks de laboratorio y endpoints de prueba. **No existe módulo de Doctores.** Laravel Sanctum está instalado pero **no está operativo** para autenticación por token.

Para Flutter será necesario construir una capa API (`/api/v1/...`) reutilizando la lógica de negocio existente en Actions y Services.

---

## Inventario general

### Rutas

| Archivo | Contexto | Tipo | Cantidad aprox. |
|---------|----------|------|-----------------|
| `routes/api.php` | `/api/*` | JSON REST (parcial) | ~25 |
| `routes/web.php` | `/` | Web (Inertia) | ~20 |
| `routes/auth.php` | Login, registro | Web | ~22 |
| `routes/settings.php` | Perfil, pagos | Web | ~35 |
| `routes/laboratories.php` | Laboratorio | Web | ~40 |
| `routes/online-pharmacy.php` | Farmacia | Web (deshabilitada) | ~8 |
| `routes/medical-attention.php` | Atención médica | Web | 3 |
| `routes/odessa.php` | Integración Odessa B2B | Web | 6 |
| `routes/admin.php` | `/admin/*` | Panel admin | ~120 |
| `routes/webhooks.php` | Webhooks externos | JSON | 5 |

### Controladores (140)

Organizados por dominio: Auth, Usuarios/Perfil, Laboratorios, Farmacia, Membresías, Pagos, Notificaciones, Odessa B2B, Admin (~50), API (2).

### Modelos (68)

Incluyen: `User`, `Customer`, `LaboratoryPurchase`, `LaboratoryQuote`, `LaboratoryAppointment`, `MedicalAttentionSubscription`, `OnlinePharmacyPurchase`, `EfevooToken`, `InAppNotification`, `Coupon`, etc.

**No existe modelo `Doctor`.**

### Migraciones (120)

Tablas principales: usuarios, clientes, laboratorio, farmacia, pagos, membresías, cupones, facturación, permisos (Spatie), integraciones (Odessa, Murguia, GDA).

### Middlewares personalizados (22)

| Middleware | Función |
|------------|---------|
| `EnsureDocumentationIsAccepted` | Exige aceptación de TOS/privacidad |
| `EnsureUserHasCustomerAccount` | Crea/asigna cuenta de cliente |
| `EnsureUserHasAdminAccount` | Restringe panel admin |
| `EnsureUserHasSuperAdminRole` | Solo super admin |
| `EnsurePhoneIsVerified` | Teléfono verificado |
| `RedirectIfUserProfileIsComplete/Incomplete` | Flujo de perfil |
| `RedirectIfMissingMedicalAttentionSubscription` | Requiere membresía (familia) |
| `RedirectIfEmptyLaboratoryCartItems` | Carrito lab no vacío |
| `EnsureLabResultsOtpVerified` | OTP de resultados verificado |
| `HandleInertiaRequests` | Datos compartidos con Inertia |
| Otros | Citas lab, carrito farmacia, confirmación contraseña, CSRF |

### Sistemas de autenticación

| Sistema | Estado |
|---------|--------|
| Sesión web (`guard: web`) | Activo — toda la app |
| Google OAuth (Socialite) | Activo |
| Magic link (URL firmada) | Activo |
| Verificación email | Activo |
| Verificación teléfono (SMS/Vonage) | Activo |
| Reset de contraseña | Activo |
| Odessa SSO (corporativo) | Activo |
| OTP resultados laboratorio | Activo |
| Spatie Permissions (admin) | Activo |
| reCAPTCHA | Instalado |
| Laravel Sanctum | Instalado, **no operativo** |
| Stripe/Cashier | Activo en `Customer` (legacy) |

### Módulos funcionales

1. Autenticación y registro (regular, Odessa, Google)
2. Perfil de usuario (datos, direcciones, contactos, documentación legal)
3. Atención médica / membresías (Murguia)
4. Cuentas familiares
5. Laboratorios (catálogo, carrito, checkout, citas, cotizaciones GDA, resultados)
6. Farmacia en línea (Vitau — **deshabilitada** en rutas)
7. Pagos (EfevooPay, Hey Banco, PayPal, cupones)
8. Facturación fiscal (CFDI, perfiles fiscales)
9. Notificaciones (in-app, laboratorio/GDA, email/SMS)
10. Derechos ARCO
11. Cupones / saldo
12. Panel administrativo
13. Integración Odessa (afiliados corporativos)
14. Webhooks externos (GDA, PayPal, Efevoo)

---

# Authentication

**Existe API REST:** No (parcial en EfevooPay sin middleware de auth explícito)

### Rutas encontradas (web)

- `GET/POST /login`, `POST /logout`
- `GET/POST /register`, `GET /register/invitation/{user}`
- `GET/POST /forgot-password`, `GET/POST /reset-password/{token}`
- `GET /auth/redirect`, `GET /auth/callback` (Google)
- `GET /magic-login/{user}` (passwordless)
- `GET/POST /odessa-register/{odessa_token}`, `GET /odessa/{odessa_token}`
- Verificación: `/verify-email/*`, `/verify-phone/*`
- `GET /confirm-password`, `PUT /password`

### Controladores relacionados

`AuthenticatedSessionController`, `RegisteredUserController`, `GoogleAuthController`, `PasswordlessAuthenticationController`, `PasswordResetLinkController`, `NewPasswordController`, `VerifyEmailController`, `VerifyPhoneController`, `OdessaRegisterController`, `OdessaController`, `OdessaLinkAuthSelectionController`, `OdessaUpgradeController`, `ConfirmablePasswordController`, `PasswordController`

### Endpoints API existentes

Ninguno para autenticación móvil.

### Endpoints faltantes para Flutter

- `POST /api/auth/login`, `POST /api/auth/register`, `POST /api/auth/logout`
- `POST /api/auth/refresh`, `GET /api/auth/me`
- `POST /api/auth/forgot-password`, `POST /api/auth/reset-password`
- Configurar Laravel Sanctum (`HasApiTokens`, `personal_access_tokens`, `auth:sanctum`)
- `POST /api/auth/google`, verificación email/teléfono, flujo Odessa

---

# Users

**Existe API REST:** No

### Rutas (web)

- `GET /user`, `PUT /basic-info`, `PUT /contact-info`
- `GET/POST /complete-profile`
- `GET/POST /documentation-accept`
- `resource addresses`, `resource contacts`
- `resource tax-profiles`
- Derechos ARCO: `/derechos-arco`, `/mis-solicitudes-arco`

### Controladores

`Auth\UserController`, `BasicInfoUpdateController`, `ContactInfoUpdateController`, `CompleteProfileController`, `DocumentationAcceptController`, `AddressController`, `ContactController`, `TaxProfileController`, `DocumentsServiceController`, `FamilyController`

### Modelos

`User`, `Customer`, `RegularAccount`, `CertificateAccount`, `OdessaAfiliateAccount`, `Address`, `Contact`, `TaxProfile`, `FamilyAccount`, `ArcoSolicitud`, `Documentation`

### Endpoints faltantes para Flutter

- `GET/PUT /api/user/profile`
- CRUD `/api/user/addresses`, `/api/user/contacts`, `/api/user/tax-profiles`
- `POST /api/user/documentation/accept`
- `GET/POST /api/user/arco-requests`
- CRUD `/api/user/family`

---

# Doctors

**Existe API REST:** No  
**Módulo en el backend:** No existe

No hay rutas, controladores, modelos ni migraciones de doctores. La funcionalidad clínica más cercana es **Atención Médica** (suscripción telemedicina vía Murguia), sin listado de médicos ni agendas.

### Endpoints faltantes para Flutter

Definir alcance e implementar desde cero si aplica:

- `GET /api/doctors`, `GET /api/doctors/{id}`
- `GET /api/doctors/{id}/availability`
- `POST /api/appointments/medical`

---

# Pharmacies

**Existe API REST:** No  
**Estado:** Deshabilitada — rutas públicas redirigen a `/` con aviso.

### Rutas web existentes

- `GET /online-pharmacy`, `GET /online-pharmacy/search` → redirigen
- Carrito, checkout y órdenes protegidos por auth (lógica presente en controladores)

### Controladores

`OnlinePharmacySearchController`, `OnlinePharmacyCartItemController`, `OnlinePharmacyShoppingCartController`, `OnlinePharmacyCheckoutController`, `OnlinePharmacyPurchaseController`

### Modelos

`OnlinePharmacyCartItem`, `OnlinePharmacyPurchase`, `OnlinePharmacyPurchaseItem`

Integración externa: **Vitau API** (productos/categorías).

### Endpoints faltantes para Flutter

- `GET /api/pharmacy/products`, `GET /api/pharmacy/categories`
- CRUD `/api/pharmacy/cart`
- `POST /api/pharmacy/checkout`
- `GET /api/pharmacy/orders`, `GET /api/pharmacy/orders/{id}`
- `POST /api/pharmacy/orders/{id}/invoice-request`

---

# Laboratories

**Existe API REST:** Parcial (notificaciones GDA; lógica principal es web)

### Endpoints API existentes

| Método | Endpoint |
|--------|----------|
| GET/POST/PUT/DELETE | `/api/laboratory/notifications` |
| GET | `/api/laboratory/test`, `/api/laboratory/create-test` |
| POST | `/api/laboratory/webhook/notifications` |
| GET | `/api/laboratory/webhook/health` |

### Rutas web (negocio principal)

- Catálogo: `/laboratory-brand-selection`, tests, stores
- Carrito y checkout: `laboratory-cart-items`, `/laboratory/{brand}/checkout`
- Citas: `laboratory-appointments`
- Cotizaciones: `POST /{brand}/quote`
- Órdenes: `laboratory-purchases`
- Resultados: `/laboratory-results/*`, OTP, PDFs

### Controladores

`LaboratoryTestsController`, `LaboratoryCartItemController`, `LaboratoryCheckoutController`, `LaboratoryPurchaseController`, `LaboratoryAppointmentController`, `LaboratoryQuoteController`, `LaboratoryResultController`, `LabResultsAccessController`, `LaboratoryEndpointController`

### Modelos

`LaboratoryTest`, `LaboratoryStore`, `LaboratoryCartItem`, `LaboratoryPurchase`, `LaboratoryAppointment`, `LaboratoryQuote`, `LaboratoryNotification`, `LabResultAccessToken`, `OtpCode`

### Endpoints faltantes para Flutter

- Catálogo: brands, tests, stores
- Carrito y checkout completo
- Citas y cotizaciones
- Órdenes e historial
- Resultados con flujo OTP móvil

---

# Orders

**Existe API REST:** No

| Tipo | Modelo | Rutas web |
|------|--------|-----------|
| Orden laboratorio | `LaboratoryPurchase` | `laboratory-purchases`, checkout lab |
| Cotización lab | `LaboratoryQuote` | `laboratory-quotes`, `POST quote` |
| Cita lab | `LaboratoryAppointment` | `laboratory-appointments` |
| Orden farmacia | `OnlinePharmacyPurchase` | `online-pharmacy-purchases` |
| Suscripción médica | `MedicalAttentionSubscription` | `medical-attention/subscription` |

### Endpoints faltantes para Flutter

- `GET /api/orders`, `GET /api/orders/{id}`, `GET /api/orders/{id}/status`
- `POST /api/orders/{id}/invoice-request`
- Polling/webhooks de estado GDA

---

# Memberships

**Existe API REST:** No

Membresía = **Atención Médica** (`MedicalAttentionSubscription`). Tipos: regular, certificado, afiliado Odessa, trial. Integración **Murguia**.

### Rutas web

- `GET /medical-attention`
- `POST /medical-attention/subscription` (pago)
- `POST /free-medical-attention/subscription` (trial)
- `prefix family/*` (requiere membresía activa)

### Controladores

`MedicalAttentionController`, `MedicalAttentionSubscriptionController`, `FreeMedicalAttentionSubscriptionController`, `FamilyController`

### Modelos

`MedicalAttentionSubscription`, `FamilyAccount`, `CertificateAccount`, `Company`, `MurguiaSyncLog`

### Endpoints faltantes para Flutter

- `GET /api/memberships/status`, `GET /api/memberships/current`, `GET /api/memberships/history`
- `POST /api/memberships/subscribe`, `POST /api/memberships/trial`, `POST /api/memberships/cancel`
- CRUD `/api/memberships/family`

---

# Notifications

**Existe API REST:** Parcial (laboratorio/GDA; in-app solo web)

### Tipos

| Tipo | Modelo | Canal |
|------|--------|-------|
| In-app | `InAppNotification` (tabla `notifications`) | Web POST |
| Laboratorio/GDA | `LaboratoryNotification` | API + webhooks |
| Email/SMS | Laravel Notifications + Vonage | Transaccional |
| OTP | `OtpCode` | SMS resultados |

### Rutas existentes

- API: CRUD `/api/laboratory/notifications`, webhook GDA
- Web: `POST /in-app-notifications/{id}/read`, `read-all`

### Endpoints faltantes para Flutter

- `GET /api/notifications`, `GET /api/notifications/unread-count`
- `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all`
- `POST /api/devices/register` (FCM/APNs)
- `GET /api/notifications/laboratory`

---

# Payments

**Existe API REST:** Parcial (EfevooPay; Hey Banco y 3DS solo web)

### Pasarelas

| Pasarela | Rutas | Formato |
|----------|-------|---------|
| EfevooPay | `/api/efevoopay/*` + web `payment-methods` | JSON API + 3DS web |
| Hey Banco / Banregio | `/payments/hey-banco/*` | Web |
| PayPal | `/paypal/create-order`, webhook | Web |
| Cupones | Integrado en checkout | Web |
| Stripe/Cashier | Legacy en `Customer` | Interno |

### Endpoints API existentes (EfevooPay)

```
GET    /api/efevoopay/health
POST   /api/efevoopay/tokenize
GET    /api/efevoopay/tokens
DELETE /api/efevoopay/tokens/{token}
POST   /api/efevoopay/payment
POST   /api/efevoopay/refund
POST   /api/efevoopay/transactions/search
```

> Usan `$request->user()` sin middleware `auth:sanctum`. Sanctum no configurado en `User`.

### Endpoints faltantes para Flutter

- Proteger EfevooPay con `auth:sanctum`
- CRUD `/api/payment-methods`
- `POST /api/payments/hey-banco/tokenize|charge|verify`
- `POST /api/payments/paypal/create-order`, `capture`
- `GET /api/payments/transactions`, saldo cupones
- Flujo 3DS adaptado a WebView / deep link móvil

---

## Tabla resumen

| Módulo | API lista | Requiere trabajo | Prioridad |
|--------|-----------|------------------|-----------|
| **Authentication** | No | Sí — Sanctum/JWT + login/registro/refresh | **Crítica** |
| **Users** | No | Sí — perfil, direcciones, fiscal, ARCO, familia | **Crítica** |
| **Doctors** | No | Sí — módulo inexistente | **Baja / N/A** |
| **Pharmacies** | No | Sí — reactivar + API Vitau | **Media** |
| **Laboratories** | Parcial | Sí — catálogo, carrito, checkout, citas, resultados | **Alta** |
| **Orders** | No | Sí — unificar órdenes lab/farmacia/suscripción | **Alta** |
| **Memberships** | No | Sí — status, suscripción, trial, Murguia | **Alta** |
| **Notifications** | Parcial | Sí — in-app + push FCM/APNs | **Media-Alta** |
| **Payments** | Parcial | Sí — auth API, Hey Banco, PayPal, 3DS móvil | **Crítica** |

---

## Recomendaciones para Flutter

1. Configurar **Laravel Sanctum** (`HasApiTokens`, migración, `auth:sanctum`).
2. Crear capa **`/api/v1/...`** reutilizando Actions/Services existentes.
3. No consumir rutas web directamente (sesión, CSRF, redirects Inertia).
4. Priorizar: Auth → Users → Laboratories → Orders → Memberships → Payments → Notifications → Pharmacies.
5. Confirmar con negocio el alcance del módulo **Doctors** (Murguia externo u omitir en v1).

---

## Rutas API completas (`routes/api.php`)

| Método | Endpoint | Controlador |
|--------|----------|-------------|
| GET | `/api/test` | `TestApiController@test` |
| * | `/api/test-items` | `TestApiController` (apiResource) |
| GET | `/api/endpoint/{id}` | `LaboratoryEndpointController@show` |
| GET | `/api/laboratory/test` | `LaboratoryEndpointController@test` |
| GET | `/api/laboratory/create-test` | `LaboratoryEndpointController@createTest` |
| * | `/api/laboratory/notifications` | `LaboratoryEndpointController` (apiResource) |
| GET | `/api/laboratory/webhook/health` | `LaboratoryWebhookController@healthCheck` |
| POST | `/api/laboratory/webhook/test` | `LaboratoryWebhookController@testWebhook` |
| POST | `/api/laboratory/webhook/notifications` | `LaboratoryWebhookController@handleNotification` |
| GET | `/api/efevoopay/health` | `EfevooPayController@healthCheck` |
| POST | `/api/efevoopay/tokenize` | `EfevooPayController@tokenizeCard` |
| GET | `/api/efevoopay/tokens` | `EfevooPayController@getUserTokens` |
| DELETE | `/api/efevoopay/tokens/{token}` | `EfevooPayController@deleteToken` |
| POST | `/api/efevoopay/payment` | `EfevooPayController@processPayment` |
| POST | `/api/efevoopay/refund` | `EfevooPayController@refund` |
| POST | `/api/efevoopay/transactions/search` | `EfevooPayController@searchTransactions` |

> `Api\PaymentController` existe en código pero **no está registrado en rutas**.
