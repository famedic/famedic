# Colección Newman ejecutable RC2 (A01–A12, B01–B26, R01–R08)

Archivos: `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` y `Famedic-Akubica-v1.3.0-rc2-NEWMAN.environment.template.json` (plantilla **sin secretos**).

## Guardas
- Bloquea cualquier host fuera de `allowed_hosts` y cualquier dominio de producción (`famedic.com.mx`, `famedic.mx`).
- Exige `expected_release_sha` (40 hex); cada aserción lleva el prefijo del SHA → evidencia ligada al release.
- Casos sin precondiciones se **omiten** (`SKIPPED_PRECONDITION`) y nunca cuentan como PASS.
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
