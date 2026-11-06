# Akúbica API RC1.1 — Fase 1 contractual

Fecha: 5 de noviembre de 2026. Resultado: **candidato independiente preparado y validaciones locales del alcance PASS**. No es aprobación de liberación ni certificación de todo el runtime. La Fase 0 administrativa sigue **ABIERTA**.

Referencias de trabajo: diagnóstico `DIAGNOSTICO-AKUBICA-v1.3.0-rc1.1.md`, `FASE-0-SEGURIDAD.md` y `PROCEDIMIENTO-ADMINISTRATIVO-FASE-0.md`, contrastados con los contratos y código del repositorio.

## Entregables

- `../akubica-openapi-v1.3.0-rc1.1.yaml`: candidato OpenAPI 3.1.0, versión 1.3.0-rc1.1, 67 operaciones.
- `MATRIZ-DIFERENCIAS-CONTRACTUALES.md`: procedencia, hashes, operaciones y schemas agregados/modificados, dependencias y riesgos.
- `FASE-1-VALIDACION.json`: resultado reproducible del validador; diffs completos por nombre; aprobación histórica y release pendientes.
- `PROPUESTA-BENEFICIOS-OPACOS.md`: diseño separado, operaciones tentativas, DTO, errores y diez decisiones de negocio pendientes; no incorporado a paths del candidato.
- `scripts/tests/test_akubica_contract_rc11.py` y `scripts/tests/akubica_contract_fixtures.py` desde la raíz del repo: pruebas y fixtures sintéticos, sin datos del incidente.

## Cambios contractuales implementados

| Observación | Corrección en candidato | Resultado / límite |
|---|---|---|
| OBS-01 | E.164 parseado tiene una sola barra de escape antes de `+`; rechaza espacios en blanco mediante restricción explícita. | Matriz normativa PASS. Regex principal coincide con FormRequest; alineación completa PENDIENTE por LF final que PHP acepta. No se cambió Laravel. |
| OBS-02 | ContextualOtpVerifiedTokenResponse usa allOf abierto por rama y cierre externo unevaluatedProperties:false. Conserva TokenResponse sin modificación. Wrappers disjuntos de login contextual/no contextual; personalización y perfil tipados. | Positivos y negativos PASS: campos Bearer válidos, campos nuevos obligatorios, datos extras rechazados. null se expresa como unión de tipos en personalización corregida. |
| OBS-03 | Respuestas específicas de alta/baja/consulta/totales/checkout por línea; sin ApiSuccessResponse universal como alternativa de oneOf. Beneficios médicos y cupones legacy de laboratorio tipados, incluido removed. | 20 fixtures de respuestas y 30 ejemplos embebidos PASS. Son fixtures construidos desde serializers/actions leídos, no capturas nuevas de staging. |
| OBS-04 | Se documentan capacidad del asistente, navegación FAMEDIC y flag legacy como conceptos diferentes. Se conserva el flag legacy realmente devuelto por checkout médico. | Matriz de estados sintéticos PASS. No se modifica readiness ni enforcement de payment links. |

También se corrigió en el candidato un solapamiento de **request-code**: el payload contextual cumplía LoginRequestCodeSms y LoginRequestCodeContextual a la vez. Nuevos wrappers excluyen la conversación del SMS no contextual y el teléfono del branch legacy email. Los esquemas heredados permanecen disponibles; no se cambió el FormRequest ni la lógica de login.

### Recursos tipados y fuente del comportamiento

| Operación | Variantes de respuesta | Fuente local |
|---|---|---|
| POST /cart/items | LaboratoryCartItemAddedResponse / MedicalServiceCartResponse | CartController::store, CartItemResource, CartResource, MedicalServiceCartResource |
| GET /cart | LaboratoryCartResponse / MedicalServiceCartResponse | CartController::index |
| GET /cart/totals | LaboratoryCartTotalsResponse / MedicalServiceCartTotalsResponse | CartController::totals y CartCouponSupport::buildTotalsWithCoupon |
| DELETE /cart/items/{cart_item_id} | LaboratoryCartItemRemovedResponse / MedicalServiceCartResponse | CartController::destroy |
| GET /checkout/prepare | LaboratoryCheckoutPrepareResponse / MedicalServiceCheckoutPrepareResponse | CheckoutPreparation::prepare, CheckoutController::prepare y recursos Contact/Address/PaymentMethod |
| GET/POST /cart/medical-benefit | MedicalServiceBenefitResponse | MedicalServiceBenefitController::show/apply |
| DELETE /cart/medical-benefit | MedicalServiceBenefitRemovedResponse | MedicalServiceBenefitController::remove |
| GET/POST/DELETE /cart/coupon | LaboratoryCouponResponse / LaboratoryCouponAppliedResponse / LaboratoryCouponRemovedResponse | CartCouponController y acciones Apply/RemoveAkubicaCartCouponAction |
| POST /auth/login/verify-code | LoginVerifiedResponse con LoginTokenData o ContextualOtpVerifiedTokenResponse | LoginController::verifyCodeP0a y respuesta legacy |

No se abren indiscriminadamente propiedades para obtener PASS. Cada variante corregida tiene campos requeridos y cierre explícito; donde se usa oneOf hay exactamente una coincidencia para el fixture válido. Se rechazan formas híbridas médico/laboratorio y propiedades inesperadas en envelope, data y recursos anidados probados.

Detalles que se preservan deliberadamente:

- El recurso de membresía toma precio configurado: el candidato deja de fijar unit_price/subtotal a un único precio de ejemplo. No se modifica el catálogo ni el precio de negocio.
- El beneficio legacy devuelve code nullable y mantiene coupon_id/ID internos porque así funciona hoy. El DTO opaco propuesto es independiente; esta fase no sustituye el flujo legacy.
- Los totales médicos **siempre devuelven applied_benefits:[] en el controlador actual**. El candidato lo admite y documenta; no afirma que refleje los beneficios del recurso de carrito. La coherencia funcional debe corregirse después.
- El retiro médico devuelve removed true/false. El retiro de cupón laboratorio incluye totals solo cuando removed=true; ambas variantes se validan con condiciones estrictas.
- Descuento legacy de laboratorio se calcula como público menos FAMEDIC y puede ser negativo si los datos son inconsistentes. No se inventa una regla funcional de corrección o recorte.

## Continuidad: significados y estados actuales

| Línea / estado | assistant_can_pay | can_continue_on_platform | can_continue_to_payment_platform | Interpretación |
|---|---|---|---|---|
| Médico vacío | false | true | false | Se permite navegación a FAMEDIC; has_items=false. No significa checkout listo. |
| Médico con ítems | false | true | false | El usuario puede continuar en FAMEDIC, sujeto al proceso y validaciones de plataforma. |
| Laboratorio vacío | No emitido | No emitido | false | Campo legacy calculado a partir de existencia de ítems. |
| Laboratorio con ítems | No emitido | No emitido | true | Solo acredita ítems, no contacto/dirección/cita completos ni autorización de pago del asistente. |

`assistant_can_pay=false` es la declaración de capacidad del asistente y no una prueba de que todas las rutas estén protegidas contra Leo. La operación heredada `/checkout/payment-link` queda sin cambios, y existe una prueba que comprueba igualdad semántica con RC1. La restricción funcional se mantiene pendiente para una fase autorizada posterior.

No se crea un nuevo campo checkout_ready ni se cambia la verdad de los flags actuales. Producto debe decidir si en el futuro se separa navegación/readiness con una ampliación explícita compatible.

## Baseline y comparación

Se preservaron byte a byte el archivo actual etiquetado v1.2.3 y RC1; no se restauró ni sobrescribió la revisión histórica. La referencia Git de 61 operaciones sigue pendiente de confirmación como contrato formalmente aprobado.

- Histórica v1.2.3: commit `6ffb35c7cdaee26e8deb09398c85ff9bcf7c46e5`, 61 operaciones, SHA-256 `6ace97823aa528171f0d5c34c8ec9674d5730c429db2a95c18dc0c560f5b3749`.
- Archivo actual etiquetado v1.2.3: 67 operaciones, SHA-256 `c46ec1f832f2c8a22bdc9cefc2341ab1afc63e01f94dc6cd3946a5ae34e4e2d2`.
- RC1: 67 operaciones, SHA-256 `bbf110ffb55f9092b7a9678561509e11bfb0eb644251efc2b44ceec3a8d1398b`.

Histórica→RC1 añade seis operaciones; RC1→RC1.1 no añade ni retira operaciones y no cambia operationId. Hay 12 operaciones con cambios directos, dos afectadas solo por componentes, 30 schemas agregados y 11 modificados frente a RC1. La matriz enumera nombres y separa modificación directa de dependencias. Los conteos no significan por sí mismos compatibilidad completa.

La revisión histórica de 61 contiene dos referencias no resueltas a respuestas: ForbiddenError y TooManyRequestsError. No se cambian para hacer pasar validaciones. El diff identifica esta condición por separado al expandir dependencias.

## Validaciones y resultados verificables

Ejecuciones exclusivamente locales, sin bootstrap Laravel ni bases de datos:

```text
python3 -B -m unittest discover -s scripts/tests -p 'test_*.py' -q
node --test scripts/tests/test-newman-secure.cjs
python3 -B scripts/validate-akubica-rc1-artifacts.py --report docs/Akubica/v1.3.0-rc1.1/FASE-1-VALIDACION.json
git diff --check
```

| Control | Resultado |
|---|---|
| Pruebas Python | **56 PASS**, cero fallos: 25 de Fase 1 y 31 de Fase 0 |
| Pruebas Node offline de Fase 0 | **4 PASS**, cero fallos; no se invocó Newman |
| Total regresión local | **60 PASS**, cero fallos |
| Parseo, refs locales, estructura básica OpenAPI e IDs | PASS, 67 operaciones, IDs preservados |
| Metaschema JSON Schema 2020-12 de componentes e inline | PASS |
| Ejemplos embebidos | **30 válidos, cero fallos** |
| Fixtures de respuestas por schema/operación | **20 válidos**, con variantes disjuntas y negativos adicionales |
| Colección Postman existente | 21 requests, 11 operaciones cubiertas, métodos+rutas resueltos; espejo completo idéntico |
| Integridad de históricos y HEAD | PASS; sin commits nuevos |
| Consistencia E.164 contrato/FormRequest | Coincide regex principal y casos ordinarios probados; **PARCIAL** por LF final |
| Códigos/datos de reportes comprometidos | No utilizados; pruebas solo con fixtures sintéticos |
| Validación integral oficial OpenAPI | **PENDIENTE**: paquete de metaschema/validador oficial no disponible en el entorno inspeccionado; no se descargó |
| Liberación, staging o cierre administrativo | **NO APROBADOS / NO EJECUTADOS** |

Las pruebas reproducen los fallos de RC1 y prueban sus correcciones; no solo comprueban que el candidato se parsea. Hay negativos de campos inesperados, tipos/fechas inválidos, missing required, respuestas híbridas, oneOf solapado, estructura/ref externa, espejo diferente y ejemplo inválido. El CLI devuelve error ante fallo y no imprime el payload. Los ejemplos negativos permanecen en pruebas, no como ejemplos exitosos del contrato.

El validador existente se fortaleció para comparar contra la revisión histórica real y contra la base actual, recorrer aliases, detectar refs rotas, validar schemas/ejemplos y no confundir las 21 requests con cobertura completa. La recuperación de referencias externas está bloqueada incluso durante pruebas negativas.

### Límites importantes de validación

El control OpenAPI implementado es estructural y se complementa con JSON Schema; **no se presenta como validación exhaustiva del metaschema oficial OpenAPI**. El checker instalado no incluía comprobador opcional de date-time; se agregó uno explícito para timestamps con segundos y zona, como emite el runtime. No certifica todas las formas RFC3339 con segundos intercalares.

Permanecen **26 anotaciones nullable heredadas fuera de los schemas corregidos**, por ejemplo en membresías, pricing y documentos. En JSON Schema 2020-12 la palabra nullable no habilita null. No se normalizaron globalmente ni se relajaron todos los tipos en esta fase. La lista exacta queda en `FASE-1-VALIDACION.json`: requiere decisión explícita de alcance y pruebas adicionales antes de afirmar compatibilidad integral con todas las respuestas del contrato.

Las comparaciones se basan en documentos parseados, no en un analizador formal de todos los posibles cambios incompatibles. Los fixtures legacy cubren formas reales leídas del código; no prueban todos los registros posibles ni equivalencia con un staging actualmente desplegado. No se accedió al servidor ni se usaron capturas con credenciales para completar esta validación.

## Archivos modificados en esta fase

- Agregado: `docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml`.
- Agregado: `scripts/akubica_contract_validation.py`.
- Modificado: `scripts/validate-akubica-rc1-artifacts.py`; ahora valida por defecto el candidato RC1.1 y permite `--contract` para inspecciones locales explícitas.
- Agregados: `scripts/tests/akubica_contract_fixtures.py` y `scripts/tests/test_akubica_contract_rc11.py`.
- Modificado: `.gitignore`, solo excepciones precisas para los entregables revisables de Fase 1; los raw y el estado operativo siguen excluidos.
- Agregados: los cuatro entregables documentales/JSON enumerados arriba.

Se conservaron los cambios previos de Fase 0 y la modificación preexistente del README RC1. No se modificaron OpenAPI históricos, `app/`, `routes/`, `config/`, `database/` ni colecciones Postman. Tampoco se construyó un ZIP RC1.1.

## Riesgos y siguiente decisión

1. Confirmar revocación selectiva del PAT y contención HMAC: Fase 0 permanece abierta y bloquea pruebas externas y distribución.
2. Confirmar qué revisión de 61 operaciones fue formalmente aprobada; revisar los refs históricos rotos sin alterar evidencia.
3. Revisar el caso E.164 LF final y el alcance de migración de nullable heredados. Las pruebas que documentan la diferencia no equivalen a que runtime y contrato ya coincidan completamente.
4. Completar validación oficial OpenAPI en un entorno con herramienta aprobada disponible y probar clientes generados; tipar respuestas antes genéricas cambia el contrato de esos clientes aunque el JSON de la aplicación no cambie.
5. Resolver las diez decisiones de `PROPUESTA-BENEFICIOS-OPACOS.md`, especialmente acumulación, consumo/reserva, confirmación y vigencias, antes de incorporar endpoints definitivos.
6. Autorizar expresamente una fase funcional posterior para beneficios, coherencia de totales, colisiones de IDs y permisos de payment-link; esta fase no implementó esas correcciones.

**Detenerse para revisión. No ejecutar Fase 2, staging, paquete final ni cambios funcionales automáticamente.**
