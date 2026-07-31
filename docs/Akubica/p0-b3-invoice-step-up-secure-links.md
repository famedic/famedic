# P0-B3 — Step-up OTP y secure links para facturas

## Objetivo

Extender la infraestructura P0-B1/B2 a **facturas**, con propósitos y flags separados de resultados.

## Endpoints

| Método | Ruta | Auth |
|--------|------|------|
| POST | `/orders/{order_id}/invoices/{invoice_id}/step-up/request` | Bearer + customer |
| POST | `/orders/{order_id}/invoices/{invoice_id}/step-up/verify` | Bearer + customer |
| POST | `/orders/{order_id}/invoices/{invoice_id}/secure-link` | Bearer + customer |
| GET | `/secure-downloads/{token}` | Público (mismo que results) |

## Ownership

1. Order owned (`customer->laboratoryPurchases`, 404 `ORDER_NOT_FOUND`)
2. Invoice morph-bound a ese order (404 `INVOICE_NOT_FOUND`)
3. Sin SMS si ownership falla

## Challenge / grant

- `purpose`: `step_up_invoices`
- `context_type` / `resource_type`: `invoice`
- `context_id` / `resource_id`: `invoice_id`
- `meta.order_id`: refuerzo de binding al pedido
- PAT Sanctum ligado al grant (bind flag)
- Cross-purpose (results/login/register) → `INVALID_CODE` / `STEP_UP_GRANT_INVALID`

## Secure link

Reutiliza tabla `otp_secure_download_links`. Resolver elige PDF por `purpose`/`resource_type` del link (no inputs del cliente).

Staging target: TTL **5**, max_opens **1** (defaults código 60/5).

Política grant↔link: igual que results (revoke cascada; expiry de grant no rompe link; logout no invalida link).

## Flags

| Flag | Default |
|------|---------|
| `OTP_P0A_STEP_UP_INVOICES_ENABLED` | false |
| `OTP_P0A_SECURE_LINKS_INVOICES_ENABLED` | false |
| `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED` | false (sin enforcement) |

## Metadata

Con flags ON, invoices issued añaden:

- `requires_step_up`
- `secure_link_supported`

Legacy `download.url` (bearer) y `download_url` (web) **permanecen** (deprecación futura).

## Reenvío

Reutilizar `request` (cooldown 60s, invalidatePreviousActive=superseded). Sin endpoint resend dedicado.

## Errores descarga

Mismos códigos genéricos de link + `INVOICE_NOT_READY` / `DOCUMENT_STORAGE_UNAVAILABLE`.

## Staging / rollback

1. Migraciones ya presentes (grants + links)
2. Activar flags invoices + TTL/max_opens staging
3. Rollback: flags OFF (Bearer intacto)

## Riesgos

- Link usable tras logout dentro del TTL
- Docs gitignored bajo `/docs/*`
