# Guía de consumo — Akúbica v1.3.0-rc1

Para integradores Leo / ATIX y QA de contrato.

## 1. Qué incluye RC1

- **OpenAPI** `akubica-openapi-v1.3.0-rc1.yaml` — 67 `operationId` (mismo set que v1.2.3), extensiones RC1 (ATIX lookup, auth contextual, carrito `medical_services`, checkout Leo).
- **Postman** — flujo mínimo 19 escenarios + orquestación Fase A (OTP) y Fase B (Bearer) en staging.
- **No** sustituye `akubica-openapi.yaml` (v1.2.3 congelado).

## 2. Importar y configurar

1. Importar `Famedic-Akubica-v1.3.0-rc1.postman_collection.json`.
2. Duplicar `Famedic-Akubica-v1.3.0-rc1.postman_environment.template.json` → entorno local (gitignored).
3. Completar **solo en el entorno**, nunca en Git:
   - `base_url` (p. ej. `https://staging.famedic.com.mx/api/v1`)
   - `atix_integration_key_id`, `atix_hmac_secret` (sandbox)
   - `phone`, `unknown_phone` (E.164)
   - `otp_code` (solo para verify válido)

## 3. Orden de ejecución (Leo RC1)

1. **Fase A:** `POST /integrations/atix/phone-lookups` → `POST /auth/login/request-code` (contextual).
2. Obtener OTP por canal acordado (SMS / revelación staging admin — fuera del paquete).
3. **Fase B:** `POST /auth/login/verify-code` → Bearer → membresía → carrito médico → beneficios → `GET /checkout/prepare` → aislamiento lab/farmacia.

Detalle endpoint / `operationId`: `endpoint-matrix-v1.3.0-rc1.md` y `CHECKLIST-endpoint-operationId-v1.3.0-rc1.md`.

## 4. Newman (referencia)

Los scripts de orquestación viven en el repositorio FamedIC (`scripts/run-newman-rc1-staging-phase-a.sh`, `phase-b.sh`). Runbook: `NEWMAN-staging-local-runbook.md`.

Evidencia staging sanitizada: `EVIDENCIA-NEWMAN-STAGING-v1.3.0-rc1.md`.

## 5. Validación de integridad

Desde la carpeta de entrega:

```bash
sha256sum -c SHA256SUMS
```

En el monorepo (desarrollo):

```bash
python3 scripts/validate-akubica-rc1-artifacts.py
```

## 6. Errores frecuentes

| Síntoma | Causa habitual |
|---------|----------------|
| `422` en lookup ATIX | Teléfono no E.164 |
| `422 ACCOUNT_CONTEXT_REQUIRED` | request-code sin lookup previo |
| `401` en replay ATIX | Nonce/idempotency reutilizado sin par 5a/5b |
| `409 CART_SERVICE_LINE_MISMATCH` | `service_line=pharmacy` o mezcla con `brand` en GET cart |
| `404 LAB_TEST_NOT_FOUND` | `laboratory_test_id` inexistente en catálogo del entorno |

## 7. Soporte

Contrato: OpenAPI RC1 + changelog. Evidencia QA: evidencia Newman staging en este paquete.
