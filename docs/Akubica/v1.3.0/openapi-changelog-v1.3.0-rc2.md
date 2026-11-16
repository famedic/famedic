# Changelog OpenAPI RC2

## Compatibilidad

OpenAPI 1.2.3 histórico permanece sin cambios. RC2 usa el contrato principal de 74 operaciones derivado del paquete RC1.1 reconciliado. R30 se conserva en un documento first party separado de cuatro operaciones.

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
