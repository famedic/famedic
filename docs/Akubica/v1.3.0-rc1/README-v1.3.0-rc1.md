# FAMEDIC × Akúbica — entrega v1.3.0-rc1 (Fase 3)

Contrato OpenAPI y colección Postman para el flujo Leo RC1 ya implementado en runtime (Fases 1–2).

## Artefactos

| Archivo | Descripción |
|---------|-------------|
| `../akubica-openapi-v1.3.0-rc1.yaml` | OpenAPI 3.1 independiente (`info.version: 1.3.0-rc1`). **No** sustituye `akubica-openapi.yaml` (v1.2.3). |
| `Famedic-Akubica-v1.3.0-rc1.postman_collection.json` | 19 escenarios mínimos con tests de contrato. |
| `Famedic-Akubica-v1.3.0-rc1.postman_environment.template.json` | Plantilla de entorno (sin secretos). |
| `openapi-changelog-v1.3.0-rc1.md` | Delta respecto a v1.2.3. |
| `endpoint-matrix-v1.3.0-rc1.md` | Matriz endpoint / operationId / caso Postman. |
| `CHECKLIST-endpoint-operationId-v1.3.0-rc1.md` | Checklist imprimible endpoint / operationId. |
| `MANIFEST-v1.3.0-rc1.md` | Lista de archivos de la entrega. |
| `SHA256SUMS` | Hashes de integridad. |
| `newman-report-template-sanitized.md` | Plantilla de reporte Newman sin PII ni tokens. |

Colección canónica: `postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json` (espejo en esta carpeta).

## Flujo documentado

1. `POST /integrations/atix/phone-lookups` — reconocimiento HMAC, sin OTP/SMS/Bearer.
2. `POST /auth/login/request-code` — contextual con binding obligatorio; `422 ACCOUNT_CONTEXT_REQUIRED` sin lookup previo.
3. `POST /auth/login/verify-code` — Bearer Sanctum + `otp_verified` + `assurance_level=otp_verified`.
4. Membresía, carrito `medical_services`, beneficios, `checkout/prepare` con Bearer.

## Ejecución Postman / Newman

1. Importar colección y plantilla de entorno.
2. Configurar en entorno (nunca commitear valores reales):
   - `base_url`
   - `atix_hmac_secret` (sandbox)
   - `atix_integration_key_id`
   - `phone`, `unknown_phone`, `otp_code` (sandbox)
3. Ejecutar carpetas en orden o `newman run ... -e entorno.json`.

## Validaciones locales

```bash
python3 scripts/validate-akubica-rc1-artifacts.py
git diff --check
```

## Brechas conocidas (runtime vs contrato RC1)

- `checkout/prepare` (`medical_services`): el runtime puede exponer `can_continue_to_payment_platform` en lugar de `can_continue_on_platform` y omitir `assistant_can_pay`. OpenAPI RC1 documenta el contrato objetivo Leo (`assistant_can_pay=false`, `can_continue_on_platform`).

Ver `endpoint-matrix-v1.3.0-rc1.md` y el informe de validación.
