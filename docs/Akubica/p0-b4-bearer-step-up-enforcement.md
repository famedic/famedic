# P0-B4 — Enforcement gradual de step-up en descargas Bearer

## Objetivo

Exigir un grant step-up válido en las descargas Bearer de resultados y facturas cuando el enforcement esté activo, sin retirar rutas ni URLs legacy.

## Endpoints

| Método | Ruta | Auth |
|--------|------|------|
| GET | `/orders/{order_id}/results/download` | Bearer + customer |
| GET | `/orders/{order_id}/invoices/{invoice_id}/download` | Bearer + customer |

## Header

```
X-Step-Up-Grant: <grant_public_id>
```

No se acepta el grant por query string.

## Flags

| Env | Default | Efecto |
|-----|---------|--------|
| `OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED` | false | Master: activa results **e** invoices |
| `OTP_P0A_STEP_UP_BEARER_RESULTS_ENABLED` | false | Solo results |
| `OTP_P0A_STEP_UP_BEARER_INVOICES_ENABLED` | false | Solo invoices |

Efectivo:

- results ON = results_specific **OR** master
- invoices ON = invoices_specific **OR** master

Rollout sugerido: results específico → invoices específico → (opcional) master.

## Orden de validación

1. `auth:sanctum`
2. `api.customer`
3. Ownership del order (404 `ORDER_NOT_FOUND`)
4. Ownership de invoice si aplica (404 `INVOICE_NOT_FOUND`)
5. Flag de enforcement
6. Presencia del header
7. Validación del grant (user, PAT, purpose, resource, activo)
8. Resolución del PDF
9. Respuesta

## Consumo del grant

El grant es **reutilizable** durante su TTL (default 10 minutos).  
No se consume por descarga Bearer.  
Las secure links mantienen su propia política de `max_opens`.

## Errores (enforcement ON)

| Código | HTTP | Caso |
|--------|------|------|
| `STEP_UP_REQUIRED` | 403 | Header ausente |
| `STEP_UP_GRANT_INVALID` | 403 | Inexistente / binding incorrecto |
| `STEP_UP_EXPIRED` | 403 | Expirado (binding OK) |
| `STEP_UP_REVOKED` | 403 | Revocado (binding OK) |
| `RESULT_NOT_READY` / `INVOICE_NOT_READY` | 409 | Tras grant válido |
| Ownership ajeno | 404 | Antes de evaluar grant |

## Metadata

Con enforcement activo, `download.requires_step_up: true` (además de campos legacy `download.url` / `download_url`).

## Rollback

Apagar los flags de enforcement (master y/o específicos). Bearer vuelve a ownership-only.

## Riesgos

- Clientes que usen Bearer sin step-up fallarán al activar el flag.
- Grant usable tras logout solo dentro del TTL (mismo riesgo aceptado que secure links con TTL corto).
- Transición futura: retirar `download_url` web y opcionalmente Bearer ungated.

## Compatibilidad

Secure links (P0-B2/B3) no requieren `X-Step-Up-Grant`.  
Rutas Bearer y campos legacy permanecen.
