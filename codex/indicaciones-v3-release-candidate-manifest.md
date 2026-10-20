# FAMEDIC — Manifiesto propuesto de release: Indicaciones IA v3

Estado: análisis; no es un artefacto desplegable ni autorización de corte. Fecha: 2026-10-14.

## Identidad Git

- Rama actual: `description-ia`.
- HEAD: `ed82e41a159a97bd8f3c37661ff2c1cbbe1790ed`.
- Merge-base con `main`: `20109d3c17561db656e7620d7bba641fc07f98ca`.
- `main...description-ia`: 380 paths, aproximadamente 62,297 inserciones y 1,309 eliminaciones; 11 commits de primer padre. El diff no es un release limitado a Indicaciones IA: mezcla resultados estructurados, explicación de resultados, admin, checkout y cambios de infraestructura.
- El working tree tiene 26 archivos tracked modificados y, al inicio del inventario, 76 untracked (70 logs y 6 archivos adicionales). Este manifiesto agrega un séptimo archivo untracked no-log. Hay archivos ignorados relevantes. HEAD no identifica el conjunto exacto que produjo las pruebas recientes.

## A. Archivos de Indicaciones IA v3

Los siguientes paths del diff de rama pertenecen al módulo o a sus pruebas directas:

```text
app/Http/Controllers/Admin/LaboratoryPreparationMonitorController.php
app/Jobs/GenerateLaboratoryPurchasePreparationSummaryJob.php
app/Models/AiExecution.php
app/Models/AiPrompt.php
app/Models/LaboratoryPurchasePreparationSummary.php
app/Notifications/LaboratoryPreparationSummaryAvailable.php
app/Services/LaboratoryPreparation/Admin/LaboratoryPreparationMonitorAccess.php
app/Services/LaboratoryPreparation/Admin/LaboratoryPreparationMonitorService.php
app/Services/LaboratoryPreparation/Compatibility/AgeConditionResolver.php
app/Services/LaboratoryPreparation/Compatibility/FastingHourInterval.php
app/Services/LaboratoryPreparation/Compatibility/FastingRestrictionCollector.php
app/Services/LaboratoryPreparation/Compatibility/GeneralPreparationCompatibilityEvaluator.php
app/Services/LaboratoryPreparation/Compatibility/HydrationCompatibilityEvaluator.php
app/Services/LaboratoryPreparation/Compatibility/OperationalCompatibilityEvaluator.php
app/Services/LaboratoryPreparation/Compatibility/SampleCompatibilityEvaluator.php
app/Services/LaboratoryPreparation/Compatibility/UninterpretedInstructionGuard.php
app/Services/LaboratoryPreparation/LaboratoryInstructionParser.php
app/Services/LaboratoryPreparation/LaboratoryPreparationDecision.php
app/Services/LaboratoryPreparation/LaboratoryPreparationFidelityValidationException.php
app/Services/LaboratoryPreparation/LaboratoryPreparationOpenAiRedactor.php
app/Services/LaboratoryPreparation/LaboratoryPreparationPatientContext.php
app/Services/LaboratoryPreparation/LaboratoryPreparationPresenter.php
app/Services/LaboratoryPreparation/LaboratoryPreparationRedactionPayloadBuilder.php
app/Services/LaboratoryPreparation/LaboratoryPreparationRedactionResult.php
app/Services/LaboratoryPreparation/LaboratoryPreparationRedactionValidator.php
app/Services/LaboratoryPreparation/LaboratoryPreparationResponseFidelityValidator.php
app/Services/LaboratoryPreparation/LaboratoryPreparationRuleEngine.php
app/Services/LaboratoryPreparation/LaboratoryPreparationSource.php
app/Services/LaboratoryPreparation/LaboratoryPreparationSummaryNotificationService.php
app/Services/LaboratoryPreparation/LaboratoryPreparationSummaryService.php
app/Services/LaboratoryPreparation/Parsing/LaboratoryInstructionCategory.php
app/Services/LaboratoryPreparation/Parsing/LaboratoryInstructionOfficialSourceSplitter.php
app/Services/LaboratoryPreparation/Parsing/LaboratoryInstructionParseResult.php
app/Services/LaboratoryPreparation/Parsing/LaboratoryInstructionParseStudyResult.php
app/Services/LaboratoryPreparation/Parsing/LaboratoryInstructionRecognitionStatus.php
app/Services/LaboratoryPreparation/Parsing/LaboratoryInstructionRecognizerRules.php
app/Services/LaboratoryPreparation/Parsing/LaboratoryInstructionRequirement.php
app/Services/LaboratoryPreparation/Parsing/LaboratoryInstructionTextNormalizer.php
resources/js/Pages/Admin/LaboratoryPreparation/Monitor.jsx
resources/js/Pages/Admin/LaboratoryPreparation/MonitorDetail.jsx
tests/Feature/Admin/LaboratoryPreparationMonitorTest.php
tests/Feature/LaboratoryPreparationChannelRenderTest.php
tests/Feature/LaboratoryPreparationDecisionPersistenceTest.php
tests/Feature/LaboratoryPreparationGenerationDispatchTest.php
tests/Feature/LaboratoryPreparationOpenAiRedactorTest.php
tests/Feature/LaboratoryPreparationPresenterTest.php
tests/Feature/LaboratoryPreparationResponseValidationTest.php
tests/Feature/LaboratoryPreparationSummaryServiceTest.php
tests/JavaScript/laboratoryPreparationExperience.test.mjs
tests/Support/LaboratoryPreparation/casos_prueba_indicaciones_v3.php
tests/Support/LaboratoryPreparation/casos_prueba_oficiales_v3.json
tests/Support/LaboratoryPreparation/casos_prueba_oficiales_v3.php
tests/Support/LaboratoryPreparation/parser_escenarios_regresion_v3.php
tests/Unit/LaboratoryPreparation/LaboratoryInstructionFastingHourAbbreviationTest.php
tests/Unit/LaboratoryPreparation/LaboratoryInstructionGda520794Test.php
tests/Unit/LaboratoryPreparation/LaboratoryInstructionOfficialCasesTest.php
tests/Unit/LaboratoryPreparation/LaboratoryInstructionParserTest.php
tests/Unit/LaboratoryPreparation/LaboratoryPreparationFragmentSecurityTest.php
tests/Unit/LaboratoryPreparation/LaboratoryPreparationRuleEngineAuditTest.php
tests/Unit/LaboratoryPreparation/LaboratoryPreparationRuleEngineTest.php
```

`AiPrompt`/`AiExecution` y su almacenamiento son infraestructura compartida con funciones de resultados AI. Su API/modelos genéricos son dependencia, pero no se debe incorporar por esta vía el resto de extracción, publicación o explicación de resultados.

## B. Integraciones necesarias; integrar solo los fragmentos indicados

| Archivo | Fragmento necesario para este módulo | Límite de corte |
|---|---|---|
| `app/Actions/Laboratories/FulfillLaboratoryCartOrderAction.php` | Inyección y llamada post-commit a `dispatchLaboratoryPreparationSummaryGeneration`; helper que valida items/hash, respeta flags y encola el job. | Conservar el punto post-commit. No trasladar ni cambiar creación de compra, transacción, cobro, GDA, citas o clearing del carrito. |
| `app/Http/Controllers/LaboratoryPurchaseController.php` | En `show`, inyectar presenter y pasar `preparation` a la vista. | Excluir cambios de resultados, factura, checkout y reformat global. |
| `app/Actions/Laboratories/LaboratoryPurchaseConfirmationViewData.php` | Resolver `preparation` con presenter y mantener `studies` desde esa respuesta para plantillas de confirmación/correo. | No alterar datos financieros ni contrato de compra. |
| `app/Http/Controllers/SharedLaboratoryOrderController.php` | Carga opcional de `preparationSummary`/`aiExecution` si existe la tabla. | Conservar validación del token de compartir y demás relaciones; no incorporar APIs de resultados estructurados. |
| `app/Http/Resources/SharedLaboratoryPurchaseResource.php` | Añadir el resultado del presenter bajo `preparation`. | No incorporar campos de resultados AI no requeridos por el resumen. |
| `app/Models/LaboratoryPurchase.php` | Relación `preparationSummary()`. | Excluir `resultReports()` y otros cambios del módulo de resultados. |
| `app/Notifications/LaboratoryAppointmentUpdatedByConcierge.php` | Preparación desde presenter para plantilla de correo de compra/cita. | Mantener intactas reglas de cita y destinatarios. |
| `app/Models/InAppNotification.php` | Fillable `action_url` requerido por notificación de resumen. | Campo genérico requiere migración compatible. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Exponer `type` y `action_url` en el feed de notificaciones al cliente. | Props globales compartidas; portar solo esos campos. |
| `resources/js/Components/NotificationBell.jsx` | Solo soporte del tipo `laboratory_preparation_summary_ready` y navegación segura a `action_url`. | El diff actual rediseña globalmente filtros, layout y comportamiento de notificaciones; no incluir el archivo entero sin aprobación de ese rediseño. Extraer fragmento mínimo o decidir eliminar navegación de la notificación IA. |
| `resources/js/Pages/LaboratoryPurchase.jsx` | Prop `preparation` al componente que representa indicaciones. | No incluir por arrastre cambios de resultados estructurados en páginas compartidas. |
| `resources/js/Pages/Shared/LaboratoryOrder.jsx` | Componentes/bloque de preparación y consumo de `laboratoryOrder.preparation`. | Archivo contiene una superficie grande compartida; portar solo componentes y punto de render IA, excluir cambios de resultados/consentimiento. |
| `resources/views/emails/laboratory/components/preparation.blade.php` | Compatibilidad de datos legacy y presenter AUTO/FALLBACK. | Conservar originales cuando v3 está OFF/FALLBACK. |
| `resources/views/emails/laboratory/preparation-summary-available.blade.php` | Correo de notificación de summary disponible. | Solo plantilla de preparación. |
| `resources/views/emails/laboratory/appointment-updated-by-concierge.blade.php` y `purchase-without-appointment.blade.php` | Paso de `preparation` a partial existente. | No cambiar otras secciones/copy de correo. |
| `resources/views/laboratory/preparation/email-studies.blade.php` | Render de instrucciones por estudio. | Dependencia de la partial de correo. |
| `app/Notifications/LaboratoryPreparationSummaryAvailable.php` | Notificación email/in-app y `action_url`. | Depende de schema `notifications.action_url` y queue worker. |
| `resources/views/pdfs/laboratory-purchase-order.blade.php` | Presentación de summary, especiales, individuales y fallback a estudios fuente. | Archivo PDF contiene lógica de órdenes/resultados; portar únicamente el bloque IA. |
| `resources/views/laboratory/preparation/pdf-formatted-content.blade.php` | Formato de contenido IA usado por PDF. | Partial exclusiva del summary. |
| `app/Actions/Laboratories/ResolveLaboratoryPurchasePdfPath.php` | Hash/invalidación del PDF cuando cambian los datos visibles de preparación. | HEAD añade la respuesta completa del presenter al hash; el working tree tiene ajuste local para hashear solo campos visibles. Seleccionar conscientemente esta versión e incluir su test; no asumir que HEAD contiene el fix validado. |
| `app/Services/OpenAi/OpenAiClient.php` | `chatCompletionWithMetadata` con Structured Outputs (`jsonSchema`, `schemaName`) que usa el redactor. | API OpenAI es compartida con resultados AI; extraer solo la extensión genérica necesaria y revisar compatibilidad de todos sus consumidores. |
| `app/Providers/AppServiceProvider.php` + `routes/admin.php` | Gate `view-laboratory-preparation-monitor`, rutas `index/show` del monitor. | Archivos contienen bindings/rutas ajenas; portar solo Gate y dos rutas de monitor. |
| `app/Http/Middleware/EnsureUserHasAdminAccount.php` | Solo el item de navegación `Indicaciones IA` condicionado por `LaboratoryPreparationMonitorAccess`. | El middleware también recibe links de resultados/cupones; excluir esos hunks. |
| `config/services.php` + `.env.example` | Dos flags con default false. | No copiar otros settings o credenciales. `.env` real nunca se incluye. |

### Migraciones indispensables

1. `database/migrations/2026_09_19_000001_create_ai_prompts_table.php` — tabla compartida y prompt legacy v1 requerido cuando v3 está OFF.
2. `database/migrations/2026_09_19_000002_create_ai_executions_table.php` — tabla compartida de ejecuciones.
3. `database/migrations/2026_09_19_000003_create_laboratory_purchase_preparation_summaries_table.php` — persistencia del módulo.
4. `database/migrations/2026_09_19_000004_laboratory_preparation_summary_prompt_v2.php` y `...000005_laboratory_preparation_summary_prompt_v3.php` — evolución del prompt legacy.
5. `database/migrations/2026_09_23_160000_add_notification_timestamps_to_laboratory_purchase_preparation_summaries.php` — idempotencia de notificaciones.
6. `database/migrations/2026_09_23_160100_add_action_url_to_notifications_table.php` — columna genérica usada por el enlace de notificación; no es exclusiva de IA.
7. `database/migrations/2026_10_08_000001_add_decision_contract_to_laboratory_purchase_preparation_summaries.php` — contrato AUTO/FALLBACK.
8. `database/migrations/2026_10_08_000002_add_laboratory_preparation_redactor_prompt.php` — prompt redactor v1.

La migración `000001` crea una tabla AI genérica y siembra el prompt legacy de preparación; sus tablas/modelos se comparten con resultados AI. Las operaciones `down()` de tablas compartidas no deben usarse como rollback rutinario de aplicación. Validar dependencias y rollback de esquema en entorno descartable; no se ejecutaron migraciones para esta auditoría.

## C. Pendiente clínico

- R07: el diff local hace AUTO solo si min/max de abstinencia son idénticos y FALLBACK para rangos diferentes, incluidos intervalos solapados. Coincide con el criterio comunicado; la modificación está sin commit. Leo debe confirmar si se incluye en el corte.
- R18: el diff local separa los bloques de heces y semen (muestras distintas), pero clasifica fingerprints distintos en el mismo estudio como conflicto. El caso orina 24 h + recipiente estéril + preservador produce FALLBACK/originales, aunque la incompatibilidad no está demostrada. Mantener la decisión abierta; no modificar el evaluador hasta respuesta de Leo.
- Paths locales: `app/Services/LaboratoryPreparation/Compatibility/SampleCompatibilityEvaluator.php`, `GeneralPreparationCompatibilityEvaluator.php`, `tests/Unit/LaboratoryPreparation/LaboratoryPreparationRuleEngineAuditTest.php`.

Alternativa 1: retener la conducta conservadora actual (FALLBACK/originales) y aprobar clínicamente su clasificación y alcance. Alternativa 2: ajustar R18 después de la respuesta de Leo con tabla de compatibilidad por espécimen y casos aprobados. No elegir ni implementar en esta preparación.

## D. Fuera del release

Excluir los demás paths del diff acumulado que no forman parte de la allowlist anterior, especialmente:

- `app/Services/LaboratoryResults/**`, extracción, GDA analytes, structured QA, explicaciones de resultados, publicación y permisos relacionados.
- `app/Http/Controllers/Admin/LaboratoryResults/**`, APIs y recursos de resultados estructurados, factories/seeders GDA y comandos de extracción/reclasificación.
- Componentes/páginas de resultados (`resources/js/Components/Admin/LaboratoryResultsCenter/**`, `LaboratoryOrderDetail/**`, consentimientos y paneles structured results), salvo fragmento de presentación de preparación descrito en B.
- Flujos/resultados GDA, publicación, resultados PDF y rutas/acciones administrativas no necesarios para el resumen.
- Cambios de checkout, PayPal, GDA uncertain, cupones/saldo, citas y otras funcionalidades fuera del módulo.
- `composer.json` únicamente agrega scripts QA; no hay dependencia de paquete nueva declarada. No incluir scripts de auditoría, prompts de agente, outputs, JSON diagnóstico o snapshots temporales en el artefacto runtime.
- Cambio acumulado de `.gitignore` para `/storage/tmp/*` y `/tmp/*` no es requisito de runtime IA.

Cambios locales actuales fuera del módulo que no deben entrar por arrastre incluyen `ResolveLaboratoryCheckoutResumeLinkAction.php`, `Documentation.php`, fixtures/tests de cupones/resultados/checkout/admin y `tests/JavaScript/laboratoryOrderShare.test.mjs`. La infraestructura local `.env.testing`, `phpunit.xml`, `tests/TestCase.php` y ambos `scripts/run-isolated-pest*.sh` solo son tooling de validación, no producto.

Inventario exacto de los 26 paths tracked modificados al iniciar este manifiesto:

```text
.env.testing
app/Actions/Laboratories/ResolveLaboratoryCheckoutResumeLinkAction.php
app/Actions/Laboratories/ResolveLaboratoryPurchasePdfPath.php
app/Models/Documentation.php
app/Services/LaboratoryPreparation/Admin/LaboratoryPreparationMonitorService.php
app/Services/LaboratoryPreparation/Compatibility/GeneralPreparationCompatibilityEvaluator.php
app/Services/LaboratoryPreparation/Compatibility/SampleCompatibilityEvaluator.php
phpunit.xml
tests/Feature/Admin/CouponAuthorizationInboxTest.php
tests/Feature/Admin/LaboratoryBilling/LaboratoryBillingModuleIsolatedTest.php
tests/Feature/Admin/LaboratoryPreparationMonitorTest.php
tests/Feature/Admin/LaboratoryResultsCenterTest.php
tests/Feature/CartJourneyCheckoutFlowTest.php
tests/Feature/Coupons/CouponRevocationAdminIsolatedTest.php
tests/Feature/Coupons/couponIsolatedSchema.php
tests/Feature/Laboratory/GdaResultsNotAvailableTest.php
tests/Feature/Laboratory/GdaResultsPurchaseViewPhase5Test.php
tests/Feature/Laboratory/GdaResultsQaReviewIsolatedTest.php
tests/Feature/Laboratory/GdaResultsStorageIsolatedSchema.php
tests/Feature/Laboratory/LaboratoryCheckoutAppointmentFirstPhase4Test.php
tests/Feature/Laboratory/StructuredResultsIsolatedSchema.php
tests/Feature/LaboratoryCheckoutResumeLinkTest.php
tests/JavaScript/laboratoryOrderShare.test.mjs
tests/TestCase.php
tests/Unit/LaboratoryNotificationPdfRefreshTest.php
tests/Unit/LaboratoryPreparation/LaboratoryPreparationRuleEngineAuditTest.php
```

Untracked no logs al iniciar este inventario: `codex/description-ia-release-blocker-audit.md`, `codex/famedic-p0-payment-gda-r18-decision.md`, ambos runners aislados y `tests/Unit/CartJourneyDatabaseConfigurationTest.php`, `tests/Unit/ResolveLaboratoryPurchasePdfPathHashTest.php`; este manifiesto agrega un path más. Hay 70 logs de ejecución bajo `codex/`; no incluirlos en el runtime.

Ignorados relevantes: `.env` local contiene `LAB_PREPARATION_DETERMINISTIC_V3_ENABLED=true` y shadow false; no copiarlo. `docs/indicaciones-v3/` es ignorado por la regla global preexistente `/docs/*`. Los tres reportes de entrega permanecen en disco, no están versionados. También hay JUnit ignorados en `storage/logs/`.

## Dependencias de ejecución y órdenes

- Persistencia: tablas `ai_prompts`, `ai_executions`, `laboratory_purchase_preparation_summaries`; relaciones de `LaboratoryPurchase`; campos `decision_status`, `rules_version`, hashes y JSON del contrato.
- Job: worker que procese `GenerateLaboratoryPurchasePreparationSummaryJob`; usa `afterCommit`, retry 3 y backoff 60/300/900. Dispatch solo se añade tras persistir purchase/transaction y fuera de la transacción de fulfillment.
- Límite de confirmación GDA: la elegibilidad IA ahora exige estado explícito `confirmed`, además de folio/consecutivo válidos, y el Job vuelve a consultar el mismo guard. Sin embargo, este checkout no contiene la columna/cast/enum de GDA del SHA base; por ello las pruebas persistidas de estados confirmados/no confirmados siguen pendientes de un candidato basado en `main`.
- OpenAI: key/model/timeout existentes y soporte genérico Structured Outputs de `OpenAiClient`; AUTO requiere OpenAI, FALLBACK no. No validar conectividad mediante llamada real durante el corte.
- Canales: presenter debe consumirse en portal, orden compartida, correo de cita/compra y PDF; requiere vistas/partials y el ajuste del hash de PDF.
- Monitor: Gate `view-laboratory-preparation-monitor`, rol `Administrador`, rutas admin y dos páginas React.
- Flags del dispatch IA: ambos OFF hacen short-circuit antes de encolar; shadow-only usa evaluación determinística sin redactor ni persistencia visible; V3 ON prevalece sobre shadow. El Job revalida flags al ejecutar. Esta integración debe portarse como hunk sobre fulfillment de `main`, nunca reemplazando ese archivo completo.

## Pruebas del candidato exacto

Las evidencias actuales (IA 296/4,875; monitor 6/6; JS 30/30; shadow previo 11/40) son del working tree, no se suman como prueba del SHA limpio. Tras autorizar el corte, ejecutar secuencialmente sobre el artefacto final y DB descartables separadas:

1. Motor/parser: R01–R18 aprobadas, QA01–QA40, asserts críticos, parser oficial, seguridad de fragmentos.
2. Redactor/validator: AUTO estructurado, omisiones/invenciones, OpenAI mock, FALLBACK sin proveedor, persistencia y hashes.
3. Job/dispatch: compra con `gda_status=confirmed` y pago elegible; negativas para GDA pending/failed/uncertain/ausente, GDA incompleto y pagos no confirmados; source hash, retry idempotente, fallo de queue sin afectar compra, flags y shadow sin cambios visibles. Ejecutar sobre schema descartable derivado del SHA base.
4. Portal/orden compartida, correo, PDF/hash visible y rollback de presenter.
5. Monitor autorizado/denegado y JS de preparación.
6. Pint focal solo en PHP incluidos y `git diff --check`.

No correr suite global como parte de este corte ni usar proveedores reales.

## Estrategia de corte propuesta (no ejecutada)

1. Leo aprueba el alcance clínico y la lista de paths/hunks, especialmente R18, el hash PDF y el soporte mínimo de `NotificationBell`.
2. Aprobar SHA de `main` como base de release.
3. Con autorización explícita, crear worktree independiente desde ese SHA y rama de release. No copiar el working tree sucio ni usar `git stash/reset/clean`.
4. Integrar el módulo desde commits/rangos y paths permitidos, pero dividir los commits mixtos (`a5e55738` incluye QA temporal/infra; commits posteriores tocan resultados AI); aplicar hunks mínimos en archivos compartidos. No cherry-pick ciego de la rama completa.
5. Revisar diff allowlist, migraciones, dependencias y secretos; fijar flags OFF; ejecutar pruebas del candidato limpio.
6. Solo tras aprobación, registrar SHA/manifiesto y hacer commit/PR en la rama de release. Ninguna acción de corte se realizó aquí.

La opción técnica preferible es worktree separado basado en `main` y extracción selectiva, porque `description-ia` contiene 380 paths mezclados y el working tree principal está sucio. Crear el worktree, rama o commits requiere autorización posterior.

## Documentación ignorada: versionado selectivo

No cambiar `.gitignore` globalmente. Los tres reportes están bajo `docs/*` ignorado; al aprobar la documentación, añadir solo estos paths con fuerza selectiva:

```bash
git add -f -- \
  docs/indicaciones-v3/entrega-leo/FAMEDIC_Indicaciones_IA_V3_Reporte_Final_Ejecucion.md \
  docs/indicaciones-v3/entrega-leo/FAMEDIC_Indicaciones_IA_V3_Reporte_Validacion.md \
  docs/indicaciones-v3/entrega-leo/FAMEDIC_Indicaciones_IA_V3_Decisiones_Pendientes.md
```

No ejecutar el comando ahora. Revisar staged paths antes de commit; no añadir directorio `docs/indicaciones-v3/` completo, Excel, ZIP, prompts o otros archivos ignorados.

## Autorizaciones pendientes

1. Leo/Clínica: aceptar una de las dos alternativas R18, compatibilidad estéril+preservador y asignación estudio→muestra.
2. Leo: incluir o excluir el diff R07 local (el criterio coincide con la instrucción recibida).
3. Responsable del módulo: aprobar el fragmento de `NotificationBell` y confirmar si el hash PDF local se incorpora.
4. Release owner: aprobar SHA base, allowlist/hunks, migraciones compartidas AI/notifications y creación de worktree/rama independiente.
5. Operaciones: confirmar que con ambos flags OFF el legacy existente puede seguir llamando OpenAI; definir cómo aislarlo si el preflight debe ser sin consumo. Shadow requiere autorización separada y no es globalmente “sin OpenAI” en el flujo actual.
6. Leo/Release owner: autorizar posteriormente versión selectiva de tres documentos ignorados; no alterar `.gitignore` global.
7. Leo/propietario de checkout-GDA: decidir el contrato de confirmación y recuperación `uncertain`, incluyendo si Indicaciones IA debe quedar bloqueada hasta folio/consecutivo válidos. El dispatcher IA actual no prueba esa condición.

## Estado

Manifiesto propuesto. No se modificó código, flags, Git index, rama, worktree, base de datos ni proveedores; no se ejecutaron pruebas/migraciones ni se preparó artefacto.

## Addendum — elegibilidad GDA de Indicaciones IA v3

La elegibilidad debe exigir `gda_status=confirmed` además de estudios, evidencia positiva de pago, folio GDA válido y consecutivo positivo. El motivo estable de rechazo es `gda_not_confirmed`. El guard se consulta tanto en el dispatch post-commit como al iniciar el Job.

En el SHA base `80602665d7cc947516f5ad252ccf80252f71119b`, `GdaOrderStatus`, el cast de `LaboratoryPurchase` y la migración `2026_09_22_000000_add_gda_status_to_laboratory_purchases_table.php` ya existen. El flujo base confirma el estado y valida folio/consecutivo antes de completar el carrito; esos controles y el manejo uncertain deben preservarse completos al portar el hunk de IA.

En `description-ia` actual no están el enum, el cast ni la columna/migración de `gda_status`. La elegibilidad usa el enum cuando está disponible y rechaza cualquier estado ausente; no añade ni altera el esquema financiero. Por ello, los tests persistidos de pending/failed/uncertain/confirmed y sus positivos por método (PayPal, EfevooPay, Odessa y saldo/cupón) deben ejecutarse en un candidato/worktree sobre `main`. No simular el atributo en memoria para declarar esa cobertura validada. La suite de esta rama no puede acreditar esos escenarios hasta contar con el esquema base correcto.
