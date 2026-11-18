# Colección Newman ejecutable RC2 (A01–A12, B01–B26, R01–R08)

Archivos: `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` y `Famedic-Akubica-v1.3.0-rc2-NEWMAN.environment.template.json` (plantilla **sin secretos**).

## Artefacto funcional y antecedente offline (Batch 1 · rev2)

| Colección | Solicitudes | Papel | Guarda |
|---|---|---|---|
| `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` + `…-rc2-NEWMAN.environment.template.json` | 69 (A, B y R) | **Funcional**: la única que se ejecuta en QA, y sólo con autorización | Guardas de ejecución (abajo) |
| `Famedic-Akubica-v1.3.0-rc2-90.postman_collection.json` | 92 (76 operaciones + 4 R30) | **Antecedente offline**: revisión de cobertura del contrato | Reescribe toda URL a `https://rc11.invalid/blocked` y llama `skipRequest()`. **Omite siempre las 92**, por diseño; no hay bandera que lo habilite |

Guardas de la colección funcional (prerequest de colección), en orden:

1. El host de `base_url` debe estar en `allowed_hosts`.
2. Se bloquea `*.famedic.com.mx` y `*.famedic.mx`, salvo el host QA oficial `staging.famedic.com.mx`.
3. `expected_release_sha` debe tener 40 hex.

Si falla cualquiera de ellas, queda una aserción `GUARDA …` en FAIL, el request se omite (no sale tráfico) y la corrida termina con exit ≠ 0. Después de las guardas se evalúan las precondiciones `requires:`: si falta una variable, el request queda en `SKIPPED_PRECONDITION`, que nunca cuenta como PASS.

Qué omite cada precondición (generado desde la colección):

| Motivo de omisión si falta la variable | Solicitudes | IDs |
|---|---|---|
| No ejecutable en Newman por diseño (`*_never_set`) | 3 | B18, R05, R08 |
| Contrato Leo de Batch 2 (`leo_batch2_contract=1` sólo sobre el build de Batch 2) | 4 | R01, R02a, R03a, R04 |
| SMS u OTP real (`sms_authorized`, `otp_code`, `otp_wrong_code_authorized`): requiere autorización de Seguridad | 4 | A08, A09, A11a, A10 |
| Continuidad B24 (`b24_enabled`, `continuity_url`): sólo staging con la bandera | 2 | B24a, B24b |
| Credenciales y Bearer de prueba inyectados en runtime (`machine_credential`, `native_*`, `bearer_*`, `atix_*`) | 20 | A12, B08, B09, B10, B11, B12, B12r, B13r, B23, B25, B26, B16r, B14, B15, B19, B21r, B22, R07a, R07a2, R07b |
| Fixtures QA o datos encadenados (ids de estudio/paquete/pedido, teléfonos sintéticos, valores de pasos previos) | 34 | A01, A02, A03, A04, A05a, A05b, A06a, A06b, A07, A10r, A11b, A11c, B01, B01r, B02, B02r, B03, B03r, B04, B05, B06a, B06b, B06r, B17, B13, B16, B20, B19r/B20r, B21, B21x, R01b, R02b, R02c, R03b |
| Sin precondiciones (corre siempre que pasen las guardas) | 2 | B07, R06 |

Ejecución bajo guardas: usar el comando de la sección "Ejecución (QA, solo con autorización)" con `allowed_hosts` y `expected_release_sha` del build verificado. **En Batch 1 no se ejecutó en QA.**

## Guardas
- Bloquea cualquier host fuera de `allowed_hosts` y cualquier dominio de producción (`*.famedic.com.mx`, `*.famedic.mx`), salvo el host QA oficial exacto `staging.famedic.com.mx` (alias QA equivalente: `famedic-otp.on-forge.com`). Ante cualquier fallo de guarda el request se omite (`pm.execution.skipRequest()`, no sale tráfico) y queda una aserción fallida `GUARDA …` visible en el reporte.
- Exige `expected_release_sha` (40 hex); cada aserción lleva el prefijo del SHA → evidencia ligada al release.
- Casos sin precondiciones se **omiten** (`SKIPPED_PRECONDITION`) y nunca cuentan como PASS.
- R01, R02a, R03a y R04 verifican el contrato Leo de Batch 1 · T7 (datos mínimos, sin descarga). Sólo corren con `leo_batch2_contract=1` sobre el build de Batch 2; si no, quedan en `SKIPPED_PRECONDITION`.
- B25 es una **prueba negativa de seguridad** (Batch 1 · T5, S14): `POST /checkout/payment-link` con credencial Leo sólo para comprobar HTTP 403 y la ausencia de liga o cobro. Nunca se corre con credencial native de pagos.
- B18 (concurrencia), R05 (sesión FAMEDIC en navegador) y R08 (UAT conversacional) no son ejecutables en Newman; quedan marcados.

## Aserciones comunes
HTTP esperado · resultado funcional · readback (B01r, B06r, B12r, B13r, B16r, B19r/B20r, B21r, A10r) · autorización (Leo vs native) · `X-Correlation-ID` devuelto igual al enviado · errores tipados (`ApiErrorResponse` con `correlation_id` = header) · sin datos protegidos (OTP, secretos, teléfonos completos, credenciales del entorno) · SHA del release.

## ATIX (A01–A07)
La firma HMAC se calcula en un pre-request desde `atix_key_id` y `atix_hmac_secret`, inyectados en tiempo de ejecución. El cuerpo se fija ya resuelto antes de firmar. Nunca usar credenciales de producción.

## Ejecución (QA, solo con autorización)
```bash
read -r -s -p "HMAC QA: " ATIX_HMAC_SECRET; echo
newman run Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json \
  -e Famedic-Akubica-v1.3.0-rc2-NEWMAN.environment.template.json \
  --env-var expected_release_sha=<SHA verificado en QA> --env-var run_id=QA-$(date +%Y%m%d%H%M) \
  --env-var atix_key_id=<id QA> --env-var atix_hmac_secret="$ATIX_HMAC_SECRET" \
  --env-var phone_registered=<tel sintético QA> --env-var phone_unknown=<tel sintético no registrado> \
  --folder "Fase A (A01–A12)" --reporters cli,junit --reporter-junit-export newman-QA-faseA.xml
unset ATIX_HMAC_SECRET
```
No usar el reporter `json`: incluye los valores del entorno. A08–A11 requieren `sms_authorized=1` (autorización de Seguridad) y `otp_code` tecleado (`read -s`), nunca guardado.
