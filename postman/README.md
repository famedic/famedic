# Famedic Akubica — Postman

Colección y environments para la API Akubica (`/api/v1`).

- **Rama:** `feature/apis-akubica`
- **Actualizado:** 2026-07-31 (P0-D1)
- **Schemas:** Collection v2.1.0 · Environment v2.1.0
- **OpenAPI:** `docs/Akubica/akubica-openapi.yaml` v1.2.0

## Orden de importación

1. Open Postman → **Import**
2. Importar `Famedic-Akubica-API-v1.postman_collection.json`
3. Importar **Famedic-Akubica-Staging.postman_environment.json** (recomendado)
4. Opcional: Local / Production
5. Seleccionar el environment en el selector superior derecho

| Archivo | Uso |
|---------|-----|
| `Famedic-Akubica-API-v1.postman_collection.json` | Colección |
| `Famedic-Akubica-Local.postman_environment.json` | Local `http://localhost:8080/api/v1` |
| `Famedic-Akubica-Staging.postman_environment.json` | Staging `https://staging.famedic.com.mx/api/v1` |
| `Famedic-Akubica-Production.postman_environment.json` | Production (guard técnico activo) |

## Environment recomendado

Usar **Famedic Akubica - Staging** para QA.

## Orden completo de pruebas

1. **01** Registro OTP SMS
2. **02** Login OTP SMS (+ revoke opcional)
3. **03** Perfil usuario
4. **04** Catálogo (público)
5. **05–06** Carrito / cupones
6. **07–08** Contactos / direcciones
7. **09–11** Checkout / citas / payment link
8. **12–15** Pedidos / resultados / facturas / solicitud factura
9. **16** Descargas Bearer-native
10. **18** Resultados step-up + secure links + enforcement
11. **19** Facturas step-up + secure links + enforcement
12. **17** Features desactivadas (503)

## Cómo capturar OTP manual

1. Ejecutar request de `request` / `request-code` (01.1, 02.1, 18.1, 19.1).
2. Leer el SMS en el dispositivo de prueba controlado.
3. Pegar dígitos en `correct_otp` / `login_correct_otp` / `results_correct_otp` / `invoice_correct_otp`.
4. **Nunca** leer OTP desde BD, logs de aplicación ni responses JSON.
5. No commitear valores de OTP.

## Cómo usar grants

1. Completar step-up verify (18.2 / 19.2) → se guarda `results_step_up_grant_id` o `invoice_step_up_grant_id`.
2. Usar el grant en `secure-link` body `{ "grant_id": "..." }`.
3. Para Bearer download con enforcement ON: header `X-Step-Up-Grant: {{…_grant_id}}` (no query string).
4. El grant es **reutilizable** dentro de su TTL (default 10 min); no se consume por descarga Bearer.

## Cómo abrir secure links

1. Tras 18.3 / 19.3, la URL queda en `results_secure_download_url` / `invoice_secure_download_url` (secret).
2. Abrir con GET **sin Bearer** (18.4 / 19.4).
3. Tratar la URL como secreto temporal: HTTPS, no logs, no persistencia.
4. Con `max_opens=1` (staging), reabrir → 410 `SECURE_LINK_CONSUMED`.

## Cómo probar enforcement

Carpetas **18.7–18.9** y **19.7–19.9** alineadas con OpenAPI:

| Caso | Flag | Expectativa |
|------|------|-------------|
| Sin header | OFF | 200 PDF (si listo) |
| Sin header | ON | 403 `STEP_UP_REQUIRED` |
| Grant válido | ON | 200 PDF |
| Grant cross-purpose | ON | 403 `STEP_UP_GRANT_INVALID` |

Flags: `OTP_P0A_STEP_UP_BEARER_RESULTS_ENABLED`, `…_INVOICES_ENABLED`, master `…_DOWNLOADS_ENABLED`.

## Expiración de 3 horas

Con `OTP_P0A_SANCTUM_3H_ENABLED=true`: `expires_in` ≈ **10800** (180 min).
Vars: `akubica_token_expires_in` / `akubica_token_expires_at`.
**Sin refresh token** — repetir login OTP al expirar.

## Variables Vonage (entorno servidor, no Postman)

```
OTP_P0A_DELIVERY_DRIVER=vonage
OTP_P0A_SMS_DELIVERY_ENABLED=true
VONAGE_KEY=
VONAGE_SECRET=
VONAGE_SMS_FROM=
```

**No** usar `OTP_P0A_SMS_DELIVERY_PROVIDER` (obsoleta / ignorada).

## Guard técnico Production

- Variable `allow_production_writes` default **`false`** en Production.
- Prerequest de colección: si el environment contiene `production`/`prod`, bloquea
  `POST|PUT|PATCH|DELETE` y GET sensibles (`/download`, `/secure-downloads/`)
  salvo `allow_production_writes=true`.
- GET seguros (catálogo, listados de lectura) siguen permitidos.
- Staging/Local pueden llevar `allow_production_writes=true` (el guard solo aplica a Production).

## Variables QA

Ver secciones previas: registro (`test_*`), login (`login_*`), resultados (`results_*`), facturas (`invoice_*`).
Secrets vacíos en environments trackeados. Production: valores sensibles vacíos.

## Errores comunes

| Síntoma | Causa probable |
|---------|----------------|
| `503 FEATURE_DISABLED` | Flag OFF |
| `503 DELIVERY_FAILED` | Vonage / driver / Redis |
| `503 OTP_CONFIGURATION_INVALID` | Anti-abuse u otros deps OFF |
| `422 INVALID_CODE` | OTP mal / challenge señuelo / consumido |
| `403 STEP_UP_REQUIRED` | Enforcement ON sin header |
| `409 RESULT_NOT_READY` / `INVOICE_NOT_READY` | PDF aún no disponible |
| `404 ORDER_NOT_FOUND` | Soft-deny ownership |
| Guard Production throw | Escritura/sensible sin `allow_production_writes` |

## Rollback por flags

Apagar el flag correspondiente (`OTP_P0A_*_ENABLED=false`) + `php artisan optimize:clear` + `config:cache`.
Sin deploy de código. Ver `docs/Akubica/api-v1-feature-flags.md`.

## P0-C2 — Retención

`akubica:prune-otp` es ops; **no** hay requests admin en esta colección. No afecta grants/links activos.

## Seguridad

- No PII real en git; secrets vacíos en environments trackeados
- Stop conditions: HTTP 500, `DELIVERY_FAILED`, OTP leak en response body
- Nunca `console.log` de OTP, teléfono completo, token o secure URL

## Copias

- **Canónica (git):** `/postman/`
- Docs versionables: `docs/Akubica/` (excepción `.gitignore` P0-D1)
- Espejos históricos bajo `docs/Akubica/guias-y-postman/` pueden seguir ignorados
