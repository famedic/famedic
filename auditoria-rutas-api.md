# Auditoría de rutas API — Famedic

**Proyecto:** Famedic (Laravel 11)  
**Alcance:** `routes/api.php`, `routes/web.php` y archivos incluidos vía web  
**Fecha:** Junio 2025

---

## Resumen ejecutivo

En Laravel 11, `routes/api.php` se monta bajo el prefijo **`/api`** con middleware de grupo **`api`**: `EnsureFrontendRequestsAreStateful` (Sanctum), `throttle:api`, `SubstituteBindings`. **Ninguna ruta API tiene `auth:sanctum` ni `auth`.**

`routes/web.php` carga otros archivos (`settings.php`, `laboratories.php`, `webhooks.php`, etc.). Varias rutas web devuelven JSON vía AJAX desde Inertia, pero usan **sesión + CSRF** — no son API REST para móvil.

| Métrica | Valor |
|---------|-------|
| Rutas en `routes/api.php` | 24 |
| Rutas web con respuesta JSON/AJAX | ~20 |
| API Resources HTTP expuestos | 0 |
| Sanctum operativo | No (instalado, no configurado) |
| Controlador API sin ruta | `Api\PaymentController` |

---

## Clasificación de rutas

| Tipo | Definición en este proyecto | Ejemplos |
|------|----------------------------|----------|
| **REST** | `Route::apiResource` con verbos HTTP estándar | `/api/test-items`, `/api/laboratory/notifications` |
| **JSON** | `response()->json()` / `JsonResponse` | EfevooPay, webhooks, PayPal, OTP |
| **AJAX** | Rutas web (`middleware: web`) consumidas por el frontend SPA | `checkout/addresses`, `3ds-status`, `paypal/create-order` |
| **API Resources** | Solo 1 resource; embebido en Inertia, **no expuesto como endpoint HTTP** | `PatientLaboratoryPurchaseCardResource` |

---

## Controladores que retornan JSON

| Controlador | Rutas registradas | Uso |
|-------------|-------------------|-----|
| `Api\EfevooPayController` | `/api/efevoopay/*` | Pagos REST |
| `TestApiController` | `/api/test*`, `/api/test-items` | Pruebas |
| `LaboratoryEndpointController` | `/api/laboratory/*`, `/api/endpoint/{id}` | CRUD notificaciones + tests |
| `Laboratory\LaboratoryWebhookController` | `/api/laboratory/webhook/*` | Webhook GDA |
| `Api\PaymentController` | **Sin ruta** | Código huérfano |
| `PayPalController` | `/paypal/*` (web) | AJAX checkout lab |
| `LaboratoryResultsOtpController` | `/otp/*` (web) | AJAX OTP resultados |
| `LaboratoryResultsController` | `results-automatic-fetch` (web) | AJAX fetch PDF base64 |
| `LaboratoryResultController` | `mark-read`, `refresh`, `debug` (web) | AJAX resultados |
| `PaymentMethodController` | `3ds-status` (web) | Polling 3DS |
| `TaxProfileController` | `tax-profiles/extract-data` (web) | AJAX extracción RFC |
| `Checkout\AddressController` | `checkout/addresses` (web) | AJAX checkout |
| `Checkout\ContactController` | `checkout/contacts` (web) | AJAX checkout |
| `EfevooWebhookController` | `efevoo/webhook` (web) | Webhook pasarela |
| `WebHook\GDAController` | `/apigda/*` (web) | Webhook GDA legacy |
| `Admin\*` (varios) | `/admin/*` | AJAX panel admin |

---

## API Resources

Solo existe **`PatientLaboratoryPurchaseCardResource`** (`app/Http/Resources/PatientLaboratoryPurchaseCardResource.php`).

Se usa en `LaboratoryPurchaseController@index` con `->toArray($request)` dentro de una respuesta **Inertia**, no como `JsonResource` HTTP. **No hay endpoints que devuelvan API Resources de Laravel.**

---

## Tabla 1 — `routes/api.php` (prefijo `/api`)

Middleware común en todas: **`api`** (Sanctum stateful, throttle:api, bindings). Sin autenticación.

| Método | Endpoint | Middleware | Controlador | Descripción |
|--------|----------|------------|-------------|-------------|
| GET | `/api/test` | `api` | `TestApiController@test` | Health check de prueba |
| GET | `/api/test-items` | `api` | `TestApiController@index` | Listar ítems de prueba (REST) |
| POST | `/api/test-items` | `api` | `TestApiController@store` | Crear ítem de prueba (REST) |
| GET | `/api/test-items/{test_item}` | `api` | `TestApiController@show` | Ver ítem (REST) |
| PUT/PATCH | `/api/test-items/{test_item}` | `api` | `TestApiController@update` | Actualizar ítem (REST) |
| DELETE | `/api/test-items/{test_item}` | `api` | `TestApiController@destroy` | Eliminar ítem (REST) |
| GET | `/api/endpoint/{id}` | `api` | `LaboratoryEndpointController@show` | Obtener notificación lab por ID |
| GET | `/api/laboratory/test` | `api` | `LaboratoryEndpointController@test` | Health check laboratorio |
| GET | `/api/laboratory/create-test` | `api` | `LaboratoryEndpointController@createTest` | Crear notificación de prueba |
| GET | `/api/laboratory/notifications` | `api` | `LaboratoryEndpointController@index` | Listar notificaciones GDA (REST) |
| POST | `/api/laboratory/notifications` | `api` | `LaboratoryEndpointController@store` | Crear notificación (REST) |
| GET | `/api/laboratory/notifications/{notification}` | `api` | `LaboratoryEndpointController@show` | Ver notificación (REST) |
| PUT/PATCH | `/api/laboratory/notifications/{notification}` | `api` | `LaboratoryEndpointController@update` | Actualizar notificación (REST) |
| DELETE | `/api/laboratory/notifications/{notification}` | `api` | `LaboratoryEndpointController@destroy` | Eliminar notificación (REST) |
| GET | `/api/laboratory/webhook/health` | `api` | `LaboratoryWebhookController@healthCheck` | Health check webhook GDA |
| POST | `/api/laboratory/webhook/test` | `api` | `LaboratoryWebhookController@testWebhook` | Payload de ejemplo GDA |
| POST | `/api/laboratory/webhook/notifications` | `api` | `LaboratoryWebhookController@handleNotification` | Webhook principal GDA (producción) |
| GET | `/api/efevoopay/health` | `api` | `Api\EfevooPayController@healthCheck` | Health check EfevooPay |
| POST | `/api/efevoopay/tokenize` | `api` | `Api\EfevooPayController@tokenizeCard` | Tokenizar tarjeta |
| GET | `/api/efevoopay/tokens` | `api` | `Api\EfevooPayController@getUserTokens` | Listar tokens del usuario |
| DELETE | `/api/efevoopay/tokens/{token}` | `api` | `Api\EfevooPayController@deleteToken` | Desactivar token |
| POST | `/api/efevoopay/payment` | `api` | `Api\EfevooPayController@processPayment` | Procesar pago |
| POST | `/api/efevoopay/refund` | `api` | `Api\EfevooPayController@refund` | Reembolso |
| POST | `/api/efevoopay/transactions/search` | `api` | `Api\EfevooPayController@searchTransactions` | Buscar transacciones |

> **Health global:** `GET /up` (definido en `bootstrap/app.php`, fuera de `api.php`).

---

## Tabla 2 — Rutas JSON/AJAX vía `routes/web.php` (archivos incluidos)

Estas rutas usan middleware **`web`** (sesión, cookies, CSRF). No están bajo `/api/*`.

### Webhooks e integraciones externas

| Método | Endpoint | Middleware | Controlador | Descripción |
|--------|----------|------------|-------------|-------------|
| POST | `/paypal/webhook` | `web` | `PayPalController@webhook` | Webhook PayPal → JSON |
| POST | `/apigda/webhook/notification` | `web` | `WebHook\GDAController@saveNotification` | Webhook GDA legacy (test) |
| POST | `/apigda/webhook/results` | `web` | `WebHook\GDAController@handleResults` | Resultados GDA legacy |
| POST | `/test-gda-simple` | `web` | Closure | Test GDA → JSON |
| POST | `/gda-emergency/notification` | `web` | Closure | Ruta emergencia GDA → JSON |
| POST | `/efevoo/webhook` | `web` (sin auth) | `EfevooWebhookController@handle` | Webhook EfevooPay → JSON |

### Pagos (AJAX desde checkout web)

| Método | Endpoint | Middleware | Controlador | Descripción |
|--------|----------|------------|-------------|-------------|
| POST | `/paypal/create-order` | `web`, `auth`, `documentation`, `verified`, `phone-verified`, `customer` | `PayPalController@createOrder` | Crear orden PayPal → JSON |
| POST | `/paypal/capture-order` | (igual) | `PayPalController@captureOrder` | Capturar pago PayPal → JSON |
| GET | `/payment-methods/3ds-status/{sessionId}` | `web`, `auth`, `documentation`, `verified`, `customer` | `PaymentMethodController@check3dsStatus` | Polling estado 3DS → JSON |

> **Rutas rotas:** `PATCH /payment-methods/{token}/alias`, `POST /payment-methods/3ds/callback` y `POST /payment-methods/3ds/cancel/{sessionId}` están registradas en `routes/settings.php` pero **los métodos no existen** en `PaymentMethodController`.

### Laboratorio — OTP y resultados (AJAX)

| Método | Endpoint | Middleware | Controlador | Descripción |
|--------|----------|------------|-------------|-------------|
| GET | `/otp/status/{laboratory_purchase}` | `web`, `auth`, `documentation`, `redirect-incomplete-user`, `verified`, `phone-verified`, `customer`, `throttle:12,1` | `LaboratoryResultsOtpController@status` | Estado OTP → JSON |
| POST | `/otp/send/{laboratory_purchase}` | (igual) | `LaboratoryResultsOtpController@send` | Enviar OTP → JSON |
| POST | `/otp/resend/{laboratory_purchase}` | (igual) | `LaboratoryResultsOtpController@resend` | Reenviar OTP → JSON |
| POST | `/otp/verify/{laboratory_purchase}` | (igual) | `LaboratoryResultsOtpController@verify` | Verificar OTP → JSON |
| POST | `/laboratory-purchases/{id}/results-automatic-fetch` | `web`, `auth`, …, `EnsureLabResultsOtpVerified` | `LaboratoryResultsController@fetch` | Obtener PDF base64 GDA → JSON |
| POST | `/laboratory-results/notification/{id}/mark-read` | `web`, `auth`, … | `LaboratoryResultController@markAsRead` | Marcar leída → JSON |
| POST | `/laboratory-results/notification/{id}/refresh` | (igual) | `LaboratoryResultController@refreshResults` | Refrescar resultados GDA → JSON |
| GET | `/laboratory-results/debug/{notificationId}` | (igual) | `LaboratoryResultController@debugNotification` | Debug notificación → JSON |

### Perfil / checkout (AJAX)

| Método | Endpoint | Middleware | Controlador | Descripción |
|--------|----------|------------|-------------|-------------|
| POST | `/checkout/addresses` | `web`, `auth`, `documentation`, `verified`, `customer` | `Checkout\AddressController` | Crear dirección en checkout → JSON `{ address: id }` |
| POST | `/checkout/contacts` | (igual) | `Checkout\ContactController` | Crear contacto en checkout → JSON |
| POST | `/tax-profiles/extract-data` | (igual) | `TaxProfileController@extractData` | Extraer datos de constancia fiscal PDF → JSON |

### Rutas web que NO devuelven JSON

La mayoría de `web.php` + `auth.php` + `laboratories.php` + `settings.php` responden **Inertia (HTML)** o redirects: login, carrito, checkout, membresías, etc.

`LabResultsAccessController` (`/lab-results/*`) responde Inertia/redirect/PDF, no JSON puro.

---

## Controlador huérfano (sin ruta)

| Controlador | Métodos JSON | Estado |
|-------------|--------------|--------|
| `Api\PaymentController` | `tokenize`, `process`, `processAsync` | **No registrado en rutas** |

---

## 1. APIs listas para Flutter

Ninguna está **completamente lista** para consumo móvil con token Bearer. Lo más cercano:

| Endpoint | Notas |
|----------|-------|
| `GET /api/test` | Health check de prueba |
| `GET /api/laboratory/test` | Health check laboratorio |
| `GET /api/efevoopay/health` | Health check EfevooPay |
| `GET /api/laboratory/webhook/health` | Health check webhook GDA |
| `POST /api/laboratory/webhook/notifications` | Webhook **entrante** para GDA, no para la app |
| Webhooks PayPal/GDA/Efevoo | Servidor-a-servidor, no cliente móvil |

**Conclusión:** no hay capa API de producto lista para Flutter. Solo infraestructura de prueba, webhooks e integraciones B2B.

---

## 2. APIs incompletas (requieren adaptación)

| Área | Endpoints | Problemas para Flutter |
|------|-----------|------------------------|
| **EfevooPay** | `/api/efevoopay/*` | Sin `auth:sanctum`; `$request->user()` será `null`; Sanctum no configurado en `User` |
| **Notificaciones lab (CRUD)** | `/api/laboratory/notifications` | Públicas, sin auth, orientadas a integración/debug |
| **PayPal checkout** | `/paypal/create-order`, `/paypal/capture-order` | JSON correcto pero requiere **sesión web + CSRF** |
| **OTP resultados** | `/otp/*` | JSON usable pero sesión web; no token API |
| **Resultados GDA** | `results-automatic-fetch`, `mark-read`, `refresh` | Sesión + middleware OTP; respuestas ad hoc |
| **3DS pagos** | `/payment-methods/3ds-status/{id}` | Polling JSON pero sesión; rutas callback/cancel rotas |
| **Checkout auxiliar** | `/checkout/addresses`, `/checkout/contacts` | JSON mínimo; sesión + CSRF |
| **Tax extract** | `/tax-profiles/extract-data` | JSON funcional; sesión web |
| **Test CRUD** | `/api/test-items` | Scaffold de prueba, no dominio Famedic |

**Acción recomendada:** extraer lógica de Actions/Services, añadir `auth:sanctum`, versionar (`/api/v1/`), eliminar CSRF en rutas API y estandarizar respuestas JSON.

---

## 3. APIs que deben construirse desde cero

| Módulo | Endpoints a crear |
|--------|-------------------|
| **Autenticación** | login, register, logout, refresh, me, forgot/reset password, verify email/phone, Google token |
| **Usuario / perfil** | profile CRUD, complete-profile, documentation accept |
| **Direcciones / contactos** | CRUD completo vía API |
| **Catálogo laboratorio** | brands, tests, stores |
| **Carrito lab** | get/add/remove items |
| **Checkout lab** | draft, appointment, create order |
| **Citas lab** | CRUD appointments |
| **Cotizaciones** | create/list/show quotes |
| **Órdenes** | list/show lab + farmacia + suscripciones |
| **Membresías** | status, subscribe, trial, cancel, family |
| **Farmacia** | products, cart, checkout (módulo deshabilitado en web) |
| **Notificaciones in-app** | list, unread count, mark read |
| **Push devices** | register/unregister FCM token |
| **Pagos móvil** | payment-methods CRUD, charge, 3DS flow nativo/WebView |
| **Doctores** | módulo inexistente en backend |

---

## Diagrama de arquitectura actual

```
Cliente Flutter (futuro)
        │
        ▼
   /api/*  ──────────► Sin auth Sanctum
        │              Solo EfevooPay + lab webhooks + tests
        │
Cliente Web (Inertia)
        │
        ▼
   /* (web) ─────────► Sesión + CSRF
        │              Inertia HTML + AJAX JSON puntual
        │
Proveedores externos
        │
        ▼
   Webhooks ─────────► GDA, PayPal, Efevoo
```

---

## Referencias de archivos

| Archivo | Rol |
|---------|-----|
| `routes/api.php` | Definición de rutas `/api/*` |
| `routes/web.php` | Punto de entrada web + `require` de sub-rutas |
| `routes/settings.php` | Pagos, perfil, checkout AJAX |
| `routes/laboratories.php` | OTP, PayPal lab, resultados AJAX |
| `routes/webhooks.php` | PayPal, GDA webhooks |
| `bootstrap/app.php` | Registro de rutas + `/up` |
| `app/Http/Kernel.php` | Grupo middleware `api` |
