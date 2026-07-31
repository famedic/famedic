# Famedic–Akubica — Documentación técnica API V1

**Rama de referencia:** `feature/apis-akubica`  
**Contrato HTTP:** `/api/v1`  
**OpenAPI:** [`akubica-openapi.yaml`](./akubica-openapi.yaml) (v1.2.0)  
**Postman (canónico en git):** `/postman/` — ver [`postman/README.md`](../../postman/README.md)

Esta carpeta documenta el contrato real para integración **Akubica / LeoV**. No modifica comportamiento de negocio.

## Índice operativo

| Documento | Contenido |
|-----------|-----------|
| [`akubica-openapi.yaml`](./akubica-openapi.yaml) | Especificación OpenAPI 3.1 alineada a rutas reales |
| [`api-v1-route-matrix.md`](./api-v1-route-matrix.md) | Matriz ruta × OpenAPI × Postman × tests × auth |
| [`api-v1-feature-flags.md`](./api-v1-feature-flags.md) | Flags P0-A/B/C, defaults, staging/prod, rollback |
| [`api-v1-errors.md`](./api-v1-errors.md) | Códigos HTTP y de dominio reales |
| [`openapi-changelog-v1.2.0.md`](./openapi-changelog-v1.2.0.md) | Cambios OpenAPI v1.1.0 → v1.2.0 |
| [`p0-a-registro-sms-fallback.md`](./p0-a-registro-sms-fallback.md) | Registro OTP SMS + fallback email |
| [`p0-a-login-sms.md`](./p0-a-login-sms.md) | Login OTP SMS |
| [`p0-b1-step-up-grants.md`](./p0-b1-step-up-grants.md) | Step-up OTP + grants |
| [`p0-b2-results-secure-links.md`](./p0-b2-results-secure-links.md) | Secure links resultados |
| [`p0-b3-invoice-step-up-secure-links.md`](./p0-b3-invoice-step-up-secure-links.md) | Step-up + secure links facturas |
| [`p0-b4-bearer-step-up-enforcement.md`](./p0-b4-bearer-step-up-enforcement.md) | Enforcement Bearer `X-Step-Up-Grant` |
| [`p0-c1-sanctum-token-expiration.md`](./p0-c1-sanctum-token-expiration.md) | Expiración Sanctum 180 min |
| [`p0-c2-otp-pruning-maintenance.md`](./p0-c2-otp-pruning-maintenance.md) | Pruning OTP (runbook; **no** es endpoint HTTP) |

## Fuente canónica de rutas

1. `routes/api/v1.php`
2. Tests `tests/Feature/Api/V1/*`
3. OpenAPI v1.2.0
4. Colección Postman `/postman/Famedic-Akubica-API-v1.postman_collection.json`

## Autenticación (resumen)

| Tipo | Endpoints |
|------|-----------|
| Público | `auth/login/*`, `auth/register*`, `catalog/*`, `GET secure-downloads/{token}` |
| Bearer Sanctum | Resto (incluye `DELETE auth/token`; customer requerido salvo revoke) |
| Bearer + `X-Step-Up-Grant` | Descargas PDF resultados/facturas cuando enforcement ON |
| Token opaco en path | `GET /secure-downloads/{token}` — **no** es API key reutilizable |

## Seguridad documental

- No incluir OTP, teléfonos reales, correos reales, Bearer tokens, secure URLs, API keys ni IDs de pacientes reales.
- Secure links son secretos temporales: HTTPS, no logs, no persistencia, consumo limitado según `OTP_P0A_SECURE_LINK_MAX_OPENS`.
- Variable inválida/obsoleta: `OTP_P0A_SMS_DELIVERY_PROVIDER` (ignorada por el código; usar `OTP_P0A_DELIVERY_DRIVER`).

## Fuera de versionado git (siguen ignorados)

Informes históricos de sprint, `.docx`, `.rar`, carpetas `evidencias/`, `Entregables/`, `Docs akubica/`, `guias-y-postman/` y demás bajo `/docs/*` no listados en la excepción de `.gitignore`.
