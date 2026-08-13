# Famedic Akubica — Postman

Colección y environments para la API Akubica (`/api/v1`).

- **Rama de referencia:** `feature/apis-akubica-billing-audit`
- **Actualizado:** 2026-08-13 (TI-09 — cierre técnico Postman/bundle)
- **Schemas:** Collection v2.1.0 · Environment v2.1.0
- **OpenAPI:** `docs/Akubica/akubica-openapi.yaml` **v1.2.3** (61 operaciones contractuales)
- **Runtime validado:** Forge release `75401580`, SHA `0b46a096a3902630e6ae0e3088c266c790d31b0f`
- **Regresión final:** `767/767 PASS`, `3969 assertions`

## Alcance

- Contrato público: **61 operaciones** (núcleo, auxiliares, seguridad avanzada).
- La colección contiene escenarios negativos, replay y utilitarios además de las operaciones contractuales; esos extras no son endpoints nuevos ni forman parte del contrato OpenAPI.
- Incluye **secure links** y **descargas Bearer PDF** de resultados/facturas.
- **XML** no existe en API V1 → fuera de alcance.
- **Business Audit** y el canal interno `web_checkout` **no** son endpoints públicos.
- Vocabulario público de beneficios: códigos **`COUPON_*`** (`COUPON_NOT_FOUND`, `COUPON_EXPIRED`, `COUPON_NOT_APPLICABLE`).
  La implementación interna puede hablar de “crédito”; el consumidor debe programar contra `COUPON_*`.

## Orden de importación

1. Open Postman → **Import**
2. Importar `Famedic-Akubica-API-v1.postman_collection.json`
3. Importar **`Famedic-Akubica-QA.postman_environment.json`** (recomendado para UAT)
4. Opcional: Local / Staging / Production
5. Seleccionar el environment **Famedic Akubica QA**

| Archivo | Uso |
|---------|-----|
| `Famedic-Akubica-API-v1.postman_collection.json` | Colección |
| `Famedic-Akubica-QA.postman_environment.json` | QA/UAT sobre staging (sin secretos) |
| `Famedic-Akubica-Local.postman_environment.json` | Local `http://localhost:8080/api/v1` |
| `Famedic-Akubica-Staging.postman_environment.json` | Staging (legado; preferir QA) |
| `Famedic-Akubica-Production.postman_environment.json` | Production (guard técnico activo) |

## Environment recomendado

Usar **Famedic Akubica QA**.

- `base_url`: `https://staging.famedic.com.mx/api/v1`
- Tokens, OTP, teléfonos y URLs firmadas: **vacíos** (secret / placeholders)
- No copiar valores desde `.env` ni versionar datos reales

## Flujo recomendado de pruebas

1. **01** Registro OTP SMS (+ **01.7 resend**)
2. **02** Login OTP SMS (+ revoke opcional)
3. **03** Perfil usuario
4. **04** Catálogo (público)
5. **05–06** Carrito / cupones
6. **07–08** Contactos / direcciones (incl. PUT/DELETE)
7. **09–11** Checkout / citas (incl. DELETE) / payment link
8. **12–15** Pedidos / resultados / facturas / tax profiles + solicitud factura
9. **16** Descargas Bearer-native
10. **18** Resultados step-up + secure links + enforcement
11. **19** Facturas step-up + secure links + enforcement
12. **20** Idempotency / Replay (flag ON)
13. **90** Casos negativos (ownership, cupones, grants)
14. **17** Features desactivadas (503)
15. **99** Legacy email login (solo flags OFF)

## Correlation ID (`X-Correlation-ID`)

- Header opcional en las requests (`{{correlation_id}}`).
- Si la variable está vacía, el servidor genera un UUID opaco.
- Toda respuesta `/api/v1` incluye `X-Correlation-ID`.
- En errores JSON: `error.correlation_id` + `error.retryable`.
- El script de colección captura el header y valida el envelope de error.
- **No** regenerar correlation antes de un replay idempotente (el servidor conserva el original en replay).

## Idempotency-Key (9 operaciones)

Solo donde el código registra `api.idempotency` y el flag `API_V1_IDEMPOTENCY_ENABLED=true`:

1. `POST /auth/login/request-code`
2. `POST /auth/register`
3. `POST /checkout/payment-link`
4. `POST /laboratory-appointments`
5. `POST …/results/step-up/request`
6. `POST …/results/secure-link`
7. `POST …/invoices/{id}/step-up/request`
8. `POST …/invoices/{id}/secure-link`
9. `POST …/invoice-request`

Formato key: 8–128 chars `[A-Za-z0-9._-]`.

Los requests duplicados de replay/negativos pueden repetir `Idempotency-Key` únicamente cuando representan una de estas 9 operaciones contractuales.

### Cómo probar replay (carpeta 20)

1. **20.0** regenera `idempotency_key` / `idempotency_original_key`
2. **20.1** primera llamada payment-link
3. **20.2** misma key + mismo body → espera `Idempotency-Replayed: true`
4. **20.3** documental: si aparece `IDEMPOTENCY_OPERATION_UNCERTAIN` → `retryable=false` (usar key nueva)

No ejecutar mutaciones reales en staging sin control UAT.

## OTP (manual)

1. Ejecutar request `request` / `request-code` / step-up request.
2. Leer SMS en dispositivo de prueba controlado.
3. Pegar en `correct_otp` / `login_correct_otp` / `results_correct_otp` / `invoice_correct_otp`.
4. **Nunca** leer OTP desde BD, logs ni responses JSON.
5. No versionar OTP.

## Tokens

- Tras verify login/register se guarda `access_token` (si el script de request lo hace).
- Con Sanctum 3h ON: `expires_in` ≈ 10800. **Sin refresh** — repetir login OTP.
- Revoke: `02.8 DELETE /auth/token`.

## Step-up y secure links vs Bearer

| Mecanismo | Auth | Notas QA |
|-----------|------|----------|
| Step-up OTP | Bearer | challenge → grant (`*_grant_id`) |
| Secure link | URL opaca, **sin** Bearer | Contrato/runtime staging para ligas nuevas: TTL **60 min**, `max_opens` **3** |
| Bearer download PDF | Bearer + `X-Step-Up-Grant` si enforcement ON | Solo PDF; XML fuera de API V1 |

Flags relevantes: `OTP_P0A_STEP_UP_*`, `OTP_P0A_SECURE_LINKS_*`, `OTP_P0A_STEP_UP_BEARER_*`.
Si están OFF → muchas requests de 18/19 responden **503 `FEATURE_DISABLED`**.

Semántica actual validada en TI-06:

- 3 consumos exitosos.
- El cuarto consumo responde `410 SECURE_LINK_CONSUMED`.
- `HEAD` actualmente consume una apertura.
- `Range` actualmente devuelve `200` completo; no hay soporte `206` explícito.
- Preview actual: PDF con `Content-Disposition: inline`.
- Un override local/UAT `5 min / max_opens 1` puede usarse para acelerar pruebas, pero no es el valor contractual ni el runtime staging validado.

## Catálogo laboratorio

Brands válidas:

- `olab`
- `swisslab`
- `jenner`
- `liacsa`
- `azteca`

Variable recomendada: `brand`, con default sintético seguro `olab`.

Filtros contractuales:

| Endpoint | Filtros |
|----------|---------|
| `GET /catalog/laboratory-brands` | `state` |
| `GET /catalog/laboratory-tests` | `brand`, `search`, `category_id`, `requires_appointment`, `page`, `per_page` |
| `GET /catalog/laboratory-test-categories` | `brand` |
| `GET /catalog/laboratory-stores` | `brand`, `state` |

Campos relevantes:

- Listado de tests: `is_available`.
- Detalle de test: `available`.
- Paginación: `current_page`, `last_page`, `per_page`, `total`.

## Perfiles fiscales y factura

1. `POST /user/tax-profiles` → `tax_profile_id`
2. `PUT` / `DELETE` del perfil (ownership)
3. `GET …/invoice-request/status`
4. `POST …/invoice-request` (idempotente)

## Cupones (`COUPON_*`)

Happy path: carpeta **06**.
Negativos: **90.4–90.6** (`COUPON_NOT_FOUND` / `EXPIRED` / `NOT_APPLICABLE`).
Variables: `coupon_code`, `coupon_code_unknown`, `coupon_code_expired`, `coupon_code_not_applicable`.

## Datos que Famedic debe proporcionar (UAT)

- Usuario(s) controlados y pedidos/facturas de prueba propios
- Al menos un `foreign_order_id` / recursos ajenos controlados
- Cupones de prueba (válido, vencido, no aplicable)
- Confirmación de flags ON en QA

## Datos que Leo debe proporcionar

- Teléfonos capaces de recibir SMS
- Responsable con acceso al dispositivo/buzón durante UAT

## Prohibiciones

No versionar: tokens, OTP, teléfonos reales, URLs firmadas, cookies, secretos Vonage, IDs de pacientes reales.

## Flags QA (resumen)

| Flag | Uso en UAT |
|------|------------|
| `OTP_P0A_INFRASTRUCTURE_ENABLED` | ON |
| `OTP_P0A_SMS_DELIVERY_ENABLED` + driver vonage | ON |
| `OTP_P0A_AKUBICA_LOGIN_ENABLED` / `REGISTER` | ON |
| `OTP_P0A_ANTI_ABUSE_ENABLED` | ON |
| `OTP_P0A_SANCTUM_3H_ENABLED` | ON (recomendado) |
| Step-up / secure links / bearer | ON según escenario |
| `API_V1_IDEMPOTENCY_ENABLED` | ON para carpeta 20 |
| `API_V1_AUDIT_ENABLED` / `BUSINESS_AUDIT_ENABLED` | Internos Famedic |

Secure links runtime staging: `OTP_P0A_SECURE_LINK_TTL_MINUTES=60`, `OTP_P0A_SECURE_LINK_MAX_OPENS=3`.

Override local/UAT opcional para pruebas aceleradas: `OTP_P0A_SECURE_LINK_TTL_MINUTES=5`, `OTP_P0A_SECURE_LINK_MAX_OPENS=1`; no usarlo como contrato vigente.

## Cierre y gates

TI-01..TI-09 cubren el cierre técnico principal. TI-10 fixtures/control de staging pre-UAT y TI-11 observabilidad pre-UAT siguen siendo gates separados si continúan abiertos.

Este material no autoriza UAT por sí mismo, no autoriza producción y no implica que commits documentales posteriores al SHA runtime hayan sido desplegados.

## Troubleshooting

| Síntoma | Qué revisar |
|---------|-------------|
| 401 | Token vacío/expirado; repetir login |
| 404 en recurso propio | ID incorrecto; soft-deny ownership |
| 409 idempotencia | Misma key/payload distinto; UNCERTAIN → nueva key |
| 422 | Body/validación |
| 429 | Antiabuso/cooldown OTP |
| 503 | Flag OFF (`FEATURE_DISABLED`) |
| PDF vacío / JSON parse error | Content-Type binario — scripts de colección lo omiten |

## Carpetas principales

`01` Registro · `02` Login · `03` Perfil · `04` Catálogo · `05` Carrito · `06` Cupones · `07` Contactos · `08` Direcciones · `09` Checkout · `10` Citas · `11` Payment link · `12` Pedidos · `13` Resultados · `14` Facturas · `15` Solicitud factura · `16` Bearer downloads · `17` 503 · `18`/`19` Step-up/secure · `20` Idempotency · `90` Negativos · `99` Legacy
