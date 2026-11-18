# Changelog OpenAPI RC2

## Compatibilidad

OpenAPI 1.2.3 histórico permanece sin cambios. RC2 usa el contrato principal de 76 operaciones derivado del paquete RC1.1 reconciliado. R30 se conserva en un documento first party separado de cuatro operaciones.

## Batch 1 · tareas 1–2 (2026-11-18)

Cambio sólo documental según el plan de cierre por batches v1.0: conteos recalculados directamente sobre los artefactos (OpenAPI RC2: 76 operaciones en 64 paths; suplemento R30: 4 operaciones; colección `Famedic-Akubica-v1.3.0-rc2-90.postman_collection.json`: 92 solicitudes) y referencias de rama actualizadas a `prep/akubica-technical-integration-rc11`. Sin cambios en el contrato, las colecciones ni el código.

## Batch 1 · tarea 8 (2026-11-18)

Nueva `RC2-MATRIZ-ESCENARIOS-CLASIFICACION.csv` / `.md`. Los 46 escenarios quedan así: 45 OBLIGATORIO_LANZAMIENTO, 1 NO_APLICABLE (B26, activación first-party) y 1 DIFERIDO adicional (X01, cupones aplicados por Leo). Cada uno se asigna a una fila S01–S16 de la tabla 8 o a UAT (R08) y conserva su estado histórico.

El README y el manifiesto corrigen la ruta del CSV de trazabilidad (`RC2-PREPARACION-QA/A-DOCUMENTACION/`) y la referencia a checksums, y apuntan el candidato al paquete de entrega de Batch 1.

## Batch 1 · tarea 7 (2026-11-18)

Se agregan esquemas explícitos para Leo (`additionalProperties=false`) y se cierra el criterio pendiente de T3: ninguna operación obligatoria de Leo queda marcada como native only.

- Esquemas nuevos: `LeoOrdersIndexResponse`, `LeoOrderStatusResponse`, `LeoOrderProductsResponse`, `LeoResultsIndexResponse`, `LeoOrderResultsResponse`, `LeoInvoicesIndexResponse`, `LeoOrderInvoicesResponse`, `LeoInvoiceRequestStatusResponse`, `LeoFamilyResponse` y `LeoTaxProfilesResponse` (D-1), con sus elementos y `LeoPagination`. Se cablean con `anyOf` junto a la respuesta nativa.
- `x-consumer-allowlist` pasa de `[native]` a `[leo, native]` en 17 operaciones (L07–L10, L12–L14 y L13b), con `x-leo-matrix-row` y `x-leo-implementation-status=BATCH_2`.
- Siguen `[native]`, con nota de 403 para Leo: descargas (L11, L15), cancelación y escrituras de perfil fiscal.
- `x-leo-capabilities` (`cart:laboratory:*`, L06) en `cart*` y `checkoutPrepare`.
- Newman: R01, R02a, R03a y R04 verifican el contrato (200, datos mínimos, sin descarga ni nacimiento/género) con la nueva precondición `leo_batch2_contract`, que en la plantilla va vacía. R02a/R03a dejan de esperar 403 porque la matriz sustituye a D5. R04 deja de aceptar 200/403 ambiguo.
- rc2-90: perfil `leo|native` por fila de la matriz.
- Sin cambio de código; las operaciones siguen siendo 76.

## Batch 1 · tarea 6 (2026-11-18)

B02 (S05) se evalúa exactamente con siete puntos: nombre, marca, `item_type`, precio público, precio FAMEDIC, ahorro y detalle.

- En Newman, B02 y B02r separan esas aserciones; `composition_status`, impuestos y vigencia pasan a ser informativos (`console.log`).
- No se exige `composition_status=validated`.
- OpenAPI `LaboratoryPackage` documenta el criterio (`x-acceptance-criterion-b02`) sin cambiar el schema.
- La nota B02 suma el criterio vigente y conserva el histórico.

## Batch 1 · tarea 5 (2026-11-18)

`POST /checkout/payment-link` se conserva en Postman/Newman sólo como **prueba negativa de seguridad** (B25/S14). Leo lo ejecuta únicamente para comprobar HTTP 403 y que no haya liga ni cobro.

- Newman B25 conserva sus aserciones (403 y ninguna URL) y suma una: la respuesta no contiene llaves de liga, intento de pago ni checkout.
- Los casos PAYMENT-LINK-* de rc2-90 se rotulan como negativos.
- OpenAPI agrega una descripción y `x-leo-test-usage=NEGATIVE_SECURITY_TEST_ONLY`.
- Las guías se reconcilian: consumo, checklist, staging-flow, README, los LEEME y el checklist QA.
- No se debilitó ninguna aserción ni cambió el código.

## Batch 1 · tarea 4 (2026-11-18)

Se congela la semántica de checkout (plan v1.0, tabla 4):
- `assistant_can_pay=false`: Leo nunca paga.
- `can_continue_on_platform=true`: el usuario continúa en FAMEDIC.
- `can_continue_to_payment_platform=false`: sin pasarela para el asistente.
- `continuity_url`: liga first-party de un uso, nunca de pago, sólo en staging.

Cambios en el OpenAPI:
- Se actualizan las descripciones en `MedicalServiceCheckoutPrepareData`, `LeoOpaque*` y `LaboratoryCheckoutPrepareData`; este último queda como DTO sólo para consumidores nativos.
- `can_continue_to_payment_platform` deja de estar `deprecated` en el carrito médico, porque el plan le da un significado contractual.
- Se añaden `LeoLaboratoryCheckoutPrepareData/Response` (D-2, continuidad de laboratorio; `x-implementation-status: BATCH_2`) y `x-checkout-semantics` en `checkoutPrepare`.
- Se actualiza `x-continuity-state`, que ya no reflejaba la bandera B24 de staging.

El número de operaciones no cambia (76), ni el formato de `X-Correlation-ID`. Newman refuerza B23 y B24a con aserciones de `can_continue_on_platform=true` y `can_continue_to_payment_platform=false`, que el runtime médico ya emite; ninguna aserción de seguridad se relaja.

## Batch 1 · tarea 3 (2026-11-18)

Nueva `RC2-MATRIZ-PERMISOS-LEO.md`, sólo documental, según las tablas 2, 3 y 6 del plan v1.0. Leo obtiene el carrito de laboratorio por línea y marca, pedidos y familiares propios, metadatos y step-up con liga segura de resultados y facturas, y la solicitud de factura: todo **pendiente de implementar en Batch 2**. Siguen prohibidos el pago, el payment link (sólo como prueba negativa) y la descarga directa de PDF (403). La aplicación directa de cupones y saldo por Leo queda **diferida** y no es una capacidad disponible. El OpenAPI no cambia en esta tarea; la alineación de `x-consumer-allowlist` queda pendiente en Batch 1.

## Correcciones y límites

- Se documenta `applied_benefits` en los totales del carrito médico.
- ATIX mantiene respuesta contractual HTTP 202 y no emite OTP ni Bearer.
- Se conservan Bearer, step-up y vinculación a consumidor, canal y delegación.
- Se documentan comparación de precios, Plan Básico, altas/retiros idempotentes y separación de carritos.
- `checkout/prepare` mantiene continuidad preparada, pero `assistant_can_pay=false`; B24 no está activado.
- Leo no recibe pagos, payment links, cupones, saldo, beneficios legacy ni capacidades R30.
- B02 local propone composición híbrida: estudios referenciados por ID y descriptores comerciales con `id=null`; `composition_status=incomplete` impide presentar como validada una composición con descriptores pendientes o rechazados. Los descriptores no son productos ni líneas de carrito. Requiere aceptación contractual de Leo antes de publicar en QA.

## Referencias de validación

La sintaxis JSON del contrato principal y del suplemento R30 se valida en el informe de cierre local. La validación oficial de OpenAPI y las pruebas HTTP de staging permanecen pendientes.

## 1.3.0-rc2-review-package-02 (LOCAL, rama rc2/cierre-anexos, 2026-11-16)

Cambios aditivos; no se elimina ni se renombra ningún campo existente. Pendiente de aceptación contractual antes de publicarse en QA.

- **A10**: `LoginTokenData` incluye `otp_verified=true` y `assurance_level=otp_verified` en el login OTP normal (sin contexto ATIX). Respuestas con contexto ATIX y Bearer existentes no cambian.
- **B24**: `MedicalServiceCheckoutPrepareData` admite `continuity_url` y `continuity_expires_at` (dependientes entre sí). Solo se emiten con la bandera `continuity.medical_services` habilitada y certificada (apagada por defecto). Liga de un solo uso hacia el checkout FAMEDIC con sesión FAMEDIC; no es liga de pago. Nuevo error `503 CONTINUITY_UNAVAILABLE`. `x-continuity-state=IMPLEMENTADO_LOCAL_APAGADO`.
- **B22**: `POST /cart/items` documenta `503 PHARMACY_SUSPENDED` (`retryable=false`) para intención de farmacia.
- **B19–B21**: `DELETE /cart/items/{id}` documenta los parámetros opcionales `service_line=medical_services` y `brand`, y los errores `404 CART_ITEM_NOT_FOUND` y `409 CART_SERVICE_LINE_MISMATCH`.
- **B01/B02**: `item_type` (`study|package`) aditivo en `LaboratoryTestListItem`, `LaboratoryTest` y `LaboratoryPackage`.
- Se retira `x-frozen-sha256` porque el documento deja de ser el congelado de review-package-01.
- R01/R04/R05: la anotación de allowlist de Leo NO se modifica; queda como propuesta contractual C1.
- B21 (Leo): `DELETE /cart/items/{cart_item_id}` con permiso membership-only queda fijo a `medical_services`; `brand` o `service_line` de otra línea → 403 `CONSUMER_NOT_AUTHORIZED` sin efectos (no ampliación del perfil Leo).
- B24 (QA): habilitación acotada a `APP_ENV=staging` + `AKUBICA_B24_MEDICAL_CONTINUITY_ENABLED=true` (por defecto apagado; producción siempre apagado). Rechazos de ligas usadas, vencidas o con flag apagado quedan registrados (`akubica_medical_continuity_rejected`, sólo `link_id` y `reason`). Sin cambio de contrato HTTP.
- Newman RC2 (QA, lote T54U1116A): B17 — la aserción común de correlation id ahora sigue el contrato de idempotencia (replay exitoso = X-Correlation-ID ORIGINAL + Idempotency-Replayed: true); prueba de regresión Rc2B17ReplayCorrelationTest. B23 — la verificación de continuidad depende de la bandera B24 (apagada: ausente; habilitada: liga https /checkout/continue/<token>, nunca de pago, assistant_can_pay=false). Sin cambio de implementación ni de contrato.
