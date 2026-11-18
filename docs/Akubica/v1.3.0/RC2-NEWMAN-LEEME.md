# Colección Newman ejecutable RC2 (A01–A12, B01–B26, R01–R08)

Archivos: `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` y `Famedic-Akubica-v1.3.0-rc2-NEWMAN.environment.template.json` (plantilla **sin secretos**).

## Artefactos funcionales y antecedente offline (Batch 1 · rev2; colección Leo agregada en rev3; B20/B21x conciliados en rev4; cuerpos conformes en rev5)

| Colección | Solicitudes | Papel | Guarda |
|---|---|---|---|
| `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` + `…-rc2-NEWMAN.environment.template.json` | 69 (A, B y R) | **Funcional** A/B/R: se ejecuta en QA sólo con autorización. Sin cambios en rev3. Rev4: sólo B20/B21x (ver "B20/B21x en rev4") | Guardas de ejecución (abajo) |
| `Famedic-Akubica-v1.3.0-rc2-LEO-FUNCIONAL.postman_collection.json` + `…-rc2-LEO-FUNCIONAL.environment.template.json` | 48 (flujos obligatorios de Leo, contrato de Batch 2) | **Funcional diferenciada de Leo** (Batch 1 · rev3): step-up, ligas seguras, solicitud y estado de factura, lectura fiscal mínima (D-1), pedidos, resultados, facturas, carrito y continuidad de laboratorio (D-2) y negativos de alcance. Sólo sobre el build de Batch 2 y con autorización | Guardas propias, más estrictas (ver `RC2-LEO-FUNCIONAL-LEEME.md`) |
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

Las operaciones de Leo que esta colección no incluye (step-up, ligas seguras, solicitud y estado de factura, lectura fiscal mínima, pedidos y resultados por id, carrito de laboratorio) están en la colección funcional diferenciada `…-rc2-LEO-FUNCIONAL` (48 solicitudes; cobertura en `RC2-LEO-FUNCIONAL-LEEME.md` y `RC2-LEO-FUNCIONAL-COBERTURA.csv`). Antes sólo aparecían en la rc2-90, que las omite siempre.

### B20/B21x en rev4 (OBS-NEWMAN-QUANTITY y OBS-NEWMAN-B21X)

| ID | Perfil | Capacidades | Línea | Marca | Cuerpo | Esperado | Traza |
|---|---|---|---|---|---|---|---|
| B20 | native (`native_machine_credential` + `native_bearer_token`) | Usuario nativo | `laboratory` | `lab_brand` | `{"laboratory_test_id": <entero>, "brand": …}` (`LaboratoryCartItemCreateLegacy`, sin `quantity`) | 201/409 | S08 |
| B21x | leo (`machine_credential` + `bearer_none`) | El binding **no** concede `cart:laboratory:write` para esa marca (antes de Batch 2 no hay ninguna capacidad de laboratorio; `cart:write` siempre denegado) | `laboratory` | `lab_brand_not_granted`: `LaboratoryBrand` válida y distinta de `lab_brand` | Igual que B20 | 403 sin efecto | L06/C1 · S08 |
| LF-L06b (`…-rc2-LEO-FUNCIONAL`) | leo (`machine_credential` + `bearer_token`) | `cart:laboratory:write` ligada a `laboratory` y `lab_brand` (Batch 2) | `laboratory` | `lab_brand` | Igual que B20 | 201 | L06 · S07 (positivo autorizado) |

- Ninguna escritura positiva de Leo se agrega a esta colección. El positivo vive en la colección de Leo, con sus guardas y `qa_writes_authorized=1`.
- B20 y B21x omiten la solicitud (`SKIPPED_PRECONDITION`) si `test_id_available` no es entero. B21x también la omite si `lab_brand_not_granted` no es una `LaboratoryBrand` válida o coincide con `lab_brand`: en ese caso no sería el caso no autorizado.
- Variable nueva en la plantilla: `lab_brand_not_granted` (vacía).
- Rev4 no cambia la clasificación ni el estado histórico de B20/B21x.

### Cuerpos en rev5 (IT-0026)

Revisión de los cuerpos de las 69 solicitudes contra el `requestBody` del OpenAPI. Las variables se resuelven con valores sintéticos y se consideran los pre-request; además se capturan sin red los cuerpos reales. Ver `RC2-INVENTARIO-CUERPOS.md` / `.csv`. Resultado: 24 CONFORME, 4 INVALIDO_DELIBERADO, 0 DISCREPANCIA_PENDIENTE y 41 sin cuerpo (N/A).

| ID | Perfil | Cuerpo rev5 | Esperado | Clasificación |
|---|---|---|---|---|
| B06b | native | `{"laboratory_test_id": <entero>, "brand": …}` (`LaboratoryCartItemCreateLegacy`, sin `quantity`); se omite si el id no es entero o la marca no es una `LaboratoryBrand` | 201 | CONFORME |
| B25 | leo (`machine_credential` + `bearer_none`), nunca native | `{"brand": "{{lab_brand}}"}`, válido para `checkoutPaymentLink`. El 403 sólo puede venir de la autorización; la ausencia de efectos se comprueba fuera de Newman en Batch 2 | 403 sin liga, intento de pago ni checkout | CONFORME |
| A07 | ATIX (HMAC) | `channel: "sms"`, `audience: "otro"`, fuera de los enum, sin cambios | 422 | INVALIDO_DELIBERADO: negativo intencional de validación |
| B22 | leo | Intención de farmacia, sin cambios | 503 PHARMACY_SUSPENDED | INVALIDO_DELIBERADO |
| R07a | leo | `membership_plan_code: "plan_inexistente"`, sin cambios | 422 | INVALIDO_DELIBERADO |
| R07a2 | leo | Sin `item_type`, sin cambios | 403 (autorización antes que validación) | INVALIDO_DELIBERADO |

No cambia ninguna aserción, credencial ni código esperado. B25 sigue en el grupo de credenciales de la tabla de precondiciones y ahora también requiere `lab_brand`.

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
La firma HMAC se calcula en un pre-request desde `atix_key_id` y `atix_hmac_secret`, inyectados en tiempo de ejecución. El cuerpo se fija ya resuelto antes de firmar. A07 es un **negativo intencional de validación**: su cuerpo es INVALIDO_DELIBERADO (canal y audiencia fuera de enum) y se espera 422. Nunca usar credenciales de producción.

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
