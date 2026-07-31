# Matriz rutas Laravel × OpenAPI × Postman × Tests

**Fecha:** 2026-07-31 · **Fuente canónica:** `routes/api/v1.php`  
**OpenAPI:** v1.2.0 · **Postman:** `/postman/Famedic-Akubica-API-v1.postman_collection.json`  
**Prefijo:** `/api/v1`

Leyenda **Estado**:
- `OK` — documentada y correcta
- `ACT` — documentada pero desactualizada (corregida en v1.2.0)
- `NEW` — no documentada antes; agregada en v1.2.0 / Postman
- `POST` — en Postman; OpenAPI parcial o reciente
- `OUT` — documentada en OpenAPI histórico sin ruta real (ninguna detectada en v1.2)
- Auth: `PUB` pública · `BEAR` Bearer+customer · `TOK` Bearer sin customer · `OPAQUE` token opaco path · `GRANT` requiere `X-Step-Up-Grant` si enforcement ON

| Ruta real | Auth | OpenAPI | Postman | Tests | Estado |
|-----------|------|---------|---------|-------|--------|
| `POST /auth/login/request-code` | PUB | Sí (SMS+legacy) | 02.1/02.6 | LoginOtpP0a4, Auth | OK |
| `POST /auth/login/verify-code` | PUB | Sí | 02.2–02.4/02.7 | LoginOtpP0a4, Auth, SanctumP0c1 | OK |
| `POST /auth/login/resend-code` | PUB | Sí | 02.5 | LoginOtpP0a4 | NEW |
| `POST /auth/register` | PUB | Sí (SMS+legacy) | 01.1/01.5 | RegisterP0a* | OK |
| `POST /auth/register/verify-code` | PUB | Sí | 01.2–01.4/01.6 | RegisterP0a* | OK |
| `POST /auth/register/resend-code` | PUB | Sí | (colección 01; resend vía request) | RegisterP0a* | NEW |
| `DELETE /auth/token` | TOK | Sí | 02.8 | Auth, Infra | OK |
| `GET /secure-downloads/{token}` | OPAQUE | Sí | 18.4–18.6, 19.4–19.6 | ResultsSecureLinks, InvoicesStepUp | NEW |
| `GET /catalog/laboratory-brands` | PUB | Sí | 04 | CatalogDiscovery | NEW |
| `GET /catalog/laboratory-tests` | PUB | Sí | 04 | CatalogDiscovery | NEW |
| `GET /catalog/laboratory-test-categories` | PUB | Sí | 04 | CatalogDiscovery | NEW |
| `GET /catalog/laboratory-stores` | PUB | Sí | 04 | CatalogDiscovery | NEW |
| `GET /catalog/laboratory-tests/{id}` | PUB | Sí (auth corregida) | 04 | CatalogDiscovery | ACT |
| `GET /catalog/medications/{id}` | PUB | Sí (503) | 17 | CatalogDiscovery | ACT |
| `GET /cart` | BEAR | Sí | 05 | CartCatalog | OK |
| `GET /cart/totals` | BEAR | Sí | 05 | CheckoutPreparation | NEW |
| `POST /cart/items` | BEAR | Sí | 05 | CartCatalog | OK |
| `DELETE /cart/items/{id}` | BEAR | Sí | 05 | CartCatalog | OK |
| `DELETE /cart` | BEAR | Sí | 05 | CheckoutPreparation | NEW |
| `GET /cart/coupon` | BEAR | Sí | 06 | CartCoupon | NEW |
| `POST /cart/coupon` | BEAR | Sí | 06 | CartCoupon | NEW |
| `DELETE /cart/coupon` | BEAR | Sí | 06 | CartCoupon | NEW |
| `GET /checkout/prepare` | BEAR | Sí | 09 | CheckoutPreparation | NEW |
| `POST /checkout/draft` | BEAR | Sí | 09 | CheckoutPreparation | NEW |
| `POST /checkout/payment-link` | BEAR | Sí | 11 | CheckoutPaymentLink | NEW |
| `GET /laboratory-appointments/requirements` | BEAR | Sí | 10 | LaboratoryAppointments | NEW |
| `GET /laboratory-appointments` | BEAR | Sí | 10 | LaboratoryAppointments | NEW |
| `POST /laboratory-appointments` | BEAR | Sí | 10 | LaboratoryAppointments | NEW |
| `DELETE /laboratory-appointments/{id}` | BEAR | Sí | 10 | LaboratoryAppointments | NEW |
| `GET /orders` | BEAR | Sí | 12 | OrdersBasic | OK |
| `GET /orders/results` | BEAR | Sí | 13 | OrdersResultsInvoices | OK |
| `GET /orders/invoices` | BEAR | Sí | 14 | OrdersResultsInvoices | OK |
| `GET /orders/{id}/products` | BEAR | Sí | 12 | OrdersBasic | OK |
| `GET /orders/{id}/invoices` | BEAR | Sí | 14 | OrdersResultsInvoices | OK |
| `GET /orders/{id}/results` | BEAR | Sí | 13 | OrdersResultsInvoices | OK |
| `GET /orders/{id}/status` | BEAR | Sí | 12 | OrdersBasic | OK |
| `PUT /orders/{id}/cancel` | BEAR | Sí (503) | 17 | OrdersBasic | ACT |
| `GET /orders/{id}/results/download` | BEAR+GRANT* | Sí | 16, 18.7–18.9 | OrderDocumentDownload, BearerStepUp | NEW |
| `GET /orders/{id}/invoices/{inv}/download` | BEAR+GRANT* | Sí | 16, 19.7–19.9 | OrderDocumentDownload, BearerStepUp | NEW |
| `POST /orders/{id}/results/step-up/request` | BEAR | Sí | 18.1 | StepUpResultsP0b1 | NEW |
| `POST /orders/{id}/results/step-up/verify` | BEAR | Sí | 18.2 | StepUpResultsP0b1 | NEW |
| `POST /orders/{id}/results/secure-link` | BEAR | Sí | 18.3 | ResultsSecureLinksP0b2 | NEW |
| `POST /orders/{id}/invoices/{inv}/step-up/request` | BEAR | Sí | 19.1 | InvoicesStepUpP0b3 | NEW |
| `POST /orders/{id}/invoices/{inv}/step-up/verify` | BEAR | Sí | 19.2 | InvoicesStepUpP0b3 | NEW |
| `POST /orders/{id}/invoices/{inv}/secure-link` | BEAR | Sí | 19.3 | InvoicesStepUpP0b3 | NEW |
| `GET /orders/{id}/invoice-request/status` | BEAR | Sí | 15 | TaxProfilesInvoiceRequest | NEW |
| `POST /orders/{id}/invoice-request` | BEAR | Sí | 15 | TaxProfilesInvoiceRequest | NEW |
| `GET /user/family` | BEAR | Sí | 03 | UserEndpoints | OK |
| `GET /user/tax-profiles` | BEAR | Sí | 03 | UserEndpoints | OK |
| `POST /user/tax-profiles` | BEAR | Sí | 03 | TaxProfiles | NEW |
| `PUT /user/tax-profiles/{id}` | BEAR | Sí | 03 | TaxProfiles | NEW |
| `DELETE /user/tax-profiles/{id}` | BEAR | Sí | 03 | TaxProfiles | NEW |
| `GET /user/addresses` | BEAR | Sí | 08 | UserEndpoints | OK |
| `POST /user/addresses` | BEAR | Sí | 08 | UserEditable | NEW |
| `PUT /user/addresses/{id}` | BEAR | Sí | 08 | UserEditable | NEW |
| `DELETE /user/addresses/{id}` | BEAR | Sí | 08 | UserEditable | NEW |
| `GET /user/payment-methods` | BEAR | Sí | 03 | UserEndpoints | OK |
| `GET /user/contacts` | BEAR | Sí | 07 | UserEditable | NEW |
| `POST /user/contacts` | BEAR | Sí | 07 | UserEditable | NEW |
| `PUT /user/contacts/{id}` | BEAR | Sí | 07 | UserEditable | NEW |
| `DELETE /user/contacts/{id}` | BEAR | Sí | 07 | UserEditable | NEW |

\* `GRANT`: solo cuando flags `OTP_P0A_STEP_UP_BEARER_*` efectivos están ON. Con flags OFF la ruta es Bearer-only.

## Clasificación auth (detalle)

| Clase | Rutas |
|-------|-------|
| Pública | Auth OTP, catálogo completo, secure-downloads |
| Bearer | Cart, checkout, appointments, orders (salvo downloads con enforcement), user |
| Pública + token opaco | `GET /secure-downloads/{token}` |
| Bearer + X-Step-Up-Grant (condicional) | `.../results/download`, `.../invoices/{id}/download` |

## No documentar en OpenAPI

| Tema | Dónde |
|------|-------|
| `akubica:prune-otp` / cleanup | Runbook `p0-c2-otp-pruning-maintenance.md` |
| Web checkout link `/akubica/checkout/{token}` | Fuera de `/api/v1` |

## Conteos

| Métrica | Valor |
|---------|------:|
| Rutas Laravel `/api/v1` | 61 |
| Operaciones OpenAPI v1.1.0 (previas) | 22 |
| Operaciones OpenAPI v1.2.0 (objetivo) | 61 |
| Carpetas Postman step-up/secure/enforcement | 18, 19 |
