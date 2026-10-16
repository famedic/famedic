# Auditoria de bloqueantes de liberacion - description-ia

Fecha local: 2026-10-14

## Estado auditado

- Rama: `description-ia`
- SHA: `ed82e41a159a97bd8f3c37661ff2c1cbbe1790ed`
- Merge-base con `main`: `20109d3c17561db656e7620d7bba641fc07f98ca`
- Working tree al auditar: sucio, con cambios no committeados en:
  - `app/Models/Documentation.php`
  - `app/Services/LaboratoryPreparation/Admin/LaboratoryPreparationMonitorService.php`
  - `app/Services/LaboratoryPreparation/Compatibility/GeneralPreparationCompatibilityEvaluator.php`
  - `app/Services/LaboratoryPreparation/Compatibility/SampleCompatibilityEvaluator.php`
  - `tests/Feature/Admin/LaboratoryBilling/LaboratoryBillingModuleIsolatedTest.php`
  - `tests/Feature/Admin/LaboratoryPreparationMonitorTest.php`
  - `tests/Feature/Laboratory/GdaResultsStorageIsolatedSchema.php`
  - `tests/Feature/Laboratory/StructuredResultsIsolatedSchema.php`
  - `tests/JavaScript/laboratoryOrderShare.test.mjs`
  - `tests/Unit/LaboratoryPreparation/LaboratoryPreparationRuleEngineAuditTest.php`
- No se ejecuto suite en `main`: no se comprobo una base de pruebas independiente y la instruccion prohibe compartir `famedic_test` o arriesgar datos reales.

## Evidencia revisada

- `scripts/agent-qa-steps-output.txt`: validacion focalizada de Indicaciones IA v3 aprobada:
  - Step 1: `44 passed (3320 assertions)`.
  - Step 2: `83 passed (319 assertions)`.
  - Pint dirty: `PASS 0 files`.
  - `git diff --check`: exit code 0.
- `test-output.txt`: suite JS con `75` tests, `73` pass, `1` fail y `1` skip por falta de Chromium.
- `storage/logs/global-test-run.log`: log parcial de PHPUnit/Pest, sin resumen final, con 7 tests fallidos visibles.
- `storage/logs/junit-global.xml`: archivo vacio.
- `git show main:test-run-combined.log`: evidencia historica en `main` de 27 fallos por SQLite corrupto.
- `git diff main...HEAD`: cambios transversales en ambiente de pruebas, checkout de laboratorio, PDFs de compra/resultados, admin, rutas, migraciones, AI/OpenAI, resultados estructurados y UI de indicaciones.

## Limitaciones

No existe en el arbol un reporte parseable con las 104 trazas completas mencionadas en el objetivo. La auditoria clasifica lo observable y marca como `no comprobado` lo que depende de las trazas ausentes. No se declara preexistencia por pertenecer a otro modulo; solo se marca preexistente cuando hay evidencia directa en `main` o en artefactos historicos.

## Matriz de clasificacion

| Suite/test | Error | Causa raiz | Presente en main | Relacion con Indicaciones IA | Impacto | Severidad | Recomendacion | Bloqueante staging |
|---|---|---|---|---|---|---|---|---|
| `Tests\Unit\LaboratoryPreparation\LaboratoryPreparationRuleEngineTest` en `scripts/agent-pest-3c*.txt` | 32, 27, 19, 11 fallos historicos; `AUTO_CONSOLIDATED` vs `FALLBACK_ORIGINAL`, reglas R03/R04/R09 ausentes | Iteraciones previas del motor deterministico v3; ya cerradas por `scripts/agent-qa-steps-output.txt` con 44/44 pass | No aplica; tests nuevos de la rama | Directa | Riesgo clinico si reaparece, pero la evidencia final local lo muestra resuelto | Alta historica, baja actual | Mantener estos logs como historial; usar solo `agent-qa-steps-output.txt` como evidencia final de v3 | No, segun evidencia final |
| `tests/JavaScript/laboratoryPreparationExperience.test.mjs` | Regex esperaba `specialInstructions.filter((instruction) => ... String(instruction?.content` y el componente usa otra forma equivalente | Test fragil acoplado a texto fuente, no a comportamiento renderizado | No comprobado; test nuevo/modificado de la rama | Directa, UI de Indicaciones IA | No bloquea backend ni flujo clinico, pero deja QA frontend incompleto | Media | Reescribir el test para ejecutar/renderizar comportamiento o actualizar expectativa al contrato actual | Por determinar; no bloquear si QA manual portal/correo/PDF ya cubre el comportamiento |
| `Tests\Unit\ActiveCampaign\MarketingIntelligenceConsolidationTest` | 1 fallo visible: filtro dashboard limita rango a 90 dias | Traza incompleta; probable deuda de ActiveCampaign/marketing o dependencia de fecha actual | No comprobado | Indirecta; OpenAI/IA no toca ese filtro, pero la rama si toca catalogos de workspace/AC en otros archivos | Bajo para Indicaciones IA; potencial medio para admin marketing | Media | Reejecutar aislado contra base limpia; si falla en main, clasificar preexistente; si solo falla aqui, revisar cambios de catalogos/workspace | No para Indicaciones IA; por determinar para staging general |
| `Tests\Unit\LaboratoryNotificationPdfRefreshTest` | 5 fallos visibles en refresco de PDF cacheado, badge de resultados actualizados y notificaciones de resultados | Cambio de `ResolveLaboratoryPurchasePdfPath` agrega `preparation` al hash de PDF; `RecordGdaResultPdfVersionAction` agrega dispatch de extraccion estructurada y eventos | No comprobado | Indirecta/adyacente: PDF de compra usa resumen de preparacion; resultados estructurados pertenecen a v3 resultados | Puede regenerar PDFs o alterar semantica de frescura/badge si el hash cambia por estado AI | Alta | Revisar si el hash debe excluir estado volátil de preparacion o estabilizar payload; reejecutar suite en DB limpia antes de staging | Si, hasta explicar o corregir |
| `Tests\Unit\LaboratoryPurchasesSheetCommissionTest` | 1 fallo visible en exportacion de comision EfevooPay | Traza incompleta; no hay cambio obvio de comisiones en diff de Indicaciones IA | No comprobado | Ninguna directa | Impacto financiero/reporting si reproduce | Alta | Reejecutar aislado; comparar contra main en worktree con DB independiente; revisar factories compartidas si falla solo en rama | Por determinar |
| `Tests\Feature\CartPreCheckoutTraceabilityTest` en `main:test-run-combined.log` | 14 fallos por `SQLSTATE[HY000]: General error: 11 database disk image is malformed` | Infraestructura/aislamiento: SQLite de test corrupto en `main` | Si, demostrado en `main` | Ninguna directa | La suite no mide producto; mide DB rota | Media | Regenerar DB SQLite o migrar a DB limpia por corrida; no usar como evidencia funcional | No |
| `Tests\Feature\AdminCartTraceabilityTest` en `main:test-run-combined.log` | 7 fallos por SQLite corrupto | Infraestructura/aislamiento preexistente | Si, demostrado en `main` | Ninguna directa | Igual que arriba | Media | Igual que arriba | No |
| `Tests\Feature\ReconcileMultibrandCartsCommandTest` en `main:test-run-combined.log` | 6 fallos por SQLite corrupto | Infraestructura/aislamiento preexistente | Si, demostrado en `main` | Ninguna directa | Igual que arriba | Media | Igual que arriba | No |
| Regresion amplia reportada: 74 fallos GDA/resultados/admin | Trazas completas no localizadas en artefactos actuales | Causa no comprobada. La rama agrega resultados estructurados, centro admin, migraciones, gates de publicacion, AI explanation y dispatch post-GDA; tambien cambia DB de tests a MySQL | No comprobado | Alta relacion por superficie modificada | Alto: GDA/resultados/admin son parte del alcance de esta rama | Alta | Obtener log completo; agrupar por SQL/schema/rutas/autorizacion/jobs/external mocks; ejecutar solo suites relevantes con DB limpia independiente | Por determinar; tratar como bloqueante hasta clasificar |
| Regresion amplia reportada: 30 fallos checkout/citas/cupones/mail/PayPal | Trazas completas no localizadas en artefactos actuales | Causa no comprobada. Cambio relevante: `FulfillLaboratoryCartOrderAction` ahora inyecta y encola preparacion post-commit; `phpunit.xml` y `TestCase.php` cambian el backend de pruebas a MySQL `famedic_test` | Parcialmente si para familia cart: 27 fallos historicos por SQLite corrupto en `main`, pero no cubre las 30 trazas reportadas | Indirecta para checkout de laboratorio; baja para PayPal/mail/cupones salvo efectos por DI/factories/DB | Medio-alto si reproduce en checkout real | Alta | Separar fallos funcionales de fallos de DB; verificar que el servicio de preparacion nunca bloquee checkout; mocks estrictos para colas/correos/gateways | Por determinar |
| Pint global con deuda de estilo | Reporte completo no localizado; evidencia focalizada `pint --dirty` paso con 0 files | Deuda global no comprobada en esta auditoria | No comprobado | Baja | Bajo salvo CI obligatorio | Baja/media | Adjuntar reporte Pint global o correr en entorno seguro sin modificar archivos | No, salvo regla CI estricta |
| `phpunit.xml` / `tests/TestCase.php` | Cambio de SQLite a MySQL `famedic_test` y credenciales fijas | Riesgo de aislamiento: puede explicar fallos amplios si la DB no esta migrada/limpia o si se comparte entre ramas | No; este cambio es de la rama | Indirecta; soporte de QA de Indicaciones IA | Alto para confiabilidad de regresion | Alta | Parametrizar DB por rama/corrida; nunca usar `famedic_test` compartida; documentar setup | Si para aceptar resultados de regresion; no necesariamente para producto |

## Totales por causa

- Evidencia final Indicaciones IA v3 aprobada: 127 tests backend focalizados y Pint/diff-check focalizados sin fallos.
- Fallos historicos de Indicaciones IA ya resueltos: 32/27/19/11 segun corrida intermedia; 0 persistentes en la evidencia final focalizada.
- Fallos visibles actuales con traza parcial en la rama: 8 tests (`1` JS, `1` ActiveCampaign, `5` PDF refresh, `1` comision export).
- Fallos demostrados preexistentes en `main`: 27, todos por SQLite corrupto.
- Fallos reportados pero sin trazas localizadas: 104, desglosados por el objetivo en 74 GDA/resultados/admin y 30 checkout/citas/cupones/mail/PayPal.

## Fallos atribuibles a la rama

- Confirmados: el fallo JS de `laboratoryPreparationExperience.test.mjs` es atribuible al cambio de UI/test de Indicaciones IA.
- Probables: los 5 fallos de `LaboratoryNotificationPdfRefreshTest`, porque la rama modifica el hash/generacion de PDF de compra y el flujo post-GDA de versiones/resultados.
- Indeterminados pero de alta sospecha: los 74 GDA/resultados/admin reportados, por la amplitud de cambios nuevos en esa superficie.
- No atribuibles con la evidencia disponible: los 27 fallos de `main:test-run-combined.log`, por corrupcion SQLite preexistente.

## Riesgos no resueltos

- No hay trazas completas de los 104 fallos reportados.
- No se comprobo `main` en una corrida nueva por falta de garantia de DB independiente.
- El cambio a MySQL `famedic_test` en tests puede invalidar comparaciones si se comparte base entre ramas.
- La ruta post-checkout encola preparacion IA; aunque esta envuelta en `try/catch`, debe comprobarse que no toca servicios externos reales y no bloquea checkout.
- Los cambios de PDF/hash pueden causar regeneracion o badges inesperados si el estado AI entra al contenido hash.
- El estado sucio del working tree impide asegurar que el SHA solo explique lo auditado.

## Correcciones minimas recomendadas

1. Adjuntar o regenerar el reporte completo de los 104 fallos en entorno seguro, con DB aislada por rama.
2. Corregir o justificar `LaboratoryNotificationPdfRefreshTest`: estabilizar hash de PDF frente a estados volatiles de preparacion o ajustar contrato si el cambio es deliberado.
3. Rehacer el test JS de special instructions para validar comportamiento y no regex fragil del source.
4. Aislar DB de tests por rama/corrida; no usar `famedic_test` compartida.
5. Reejecutar solo suites afectadas: GDA/resultados/admin, checkout laboratorio, mail safety, coupons, PayPal con mocks y sin servicios externos reales.
6. Limpiar o separar los cambios no committeados antes de pedir aprobacion de release.

## Veredicto

NO-GO para staging como release general en este estado.

Motivo: aunque Indicaciones IA v3 tiene evidencia focalizada fuerte, la regresion amplia reportada no esta trazablemente clasificada y hay al menos una familia de fallos visible en PDF/resultados con relacion plausible a la rama. Para un GO condicionado se necesita demostrar que los 74 + 30 fallos son infraestructura/preexistentes o no impactan flujos staging, con logs completos y DB aislada.

## Informacion necesaria para excepcion o aceptacion de riesgo

- Log completo de la corrida con 104 fallos, idealmente JUnit no vacio.
- Config exacta de DB usada por cada corrida (`DB_CONNECTION`, `DB_DATABASE`, host, si fue limpia, si hubo paralelismo).
- Evidencia de que no se llamaron GDA, PayPal, ActiveCampaign, Vonage ni OpenAI real durante regresion.
- Resultado de `main` solo si puede ejecutarse en worktree temporal con DB independiente.
- Lista firmada de fallos excluidos por infraestructura, con prueba de reproduccion fuera de la rama.
- Confirmacion funcional/manual de portal, correo y PDF tras estabilizar hash/refresco de resultados.

---

# Cierre P1 - PDF de laboratorio y aislamiento

Fecha local: 2026-10-14

## Estado al cierre

- Rama: `description-ia`
- SHA: `ed82e41a159a97bd8f3c37661ff2c1cbbe1790ed`
- Working tree: sucio. Cambios aplicados en este cierre:
  - `.env.testing`
  - `phpunit.xml`
  - `tests/TestCase.php`
  - `scripts/run-isolated-pest.sh`
  - `app/Actions/Laboratories/ResolveLaboratoryPurchasePdfPath.php`
  - `tests/Unit/LaboratoryNotificationPdfRefreshTest.php`
  - `tests/Unit/ResolveLaboratoryPurchasePdfPathHashTest.php`
  - `codex/pest-famedic_test_description_ia_*.log`
- Cambios preexistentes no atribuidos a este cierre:
  - `app/Models/Documentation.php`
  - `app/Services/LaboratoryPreparation/Admin/LaboratoryPreparationMonitorService.php`
  - `app/Services/LaboratoryPreparation/Compatibility/GeneralPreparationCompatibilityEvaluator.php`
  - `app/Services/LaboratoryPreparation/Compatibility/SampleCompatibilityEvaluator.php`
  - `tests/Feature/Admin/LaboratoryBilling/LaboratoryBillingModuleIsolatedTest.php`
  - `tests/Feature/Admin/LaboratoryPreparationMonitorTest.php`
  - `tests/Feature/Laboratory/GdaResultsStorageIsolatedSchema.php`
  - `tests/Feature/Laboratory/StructuredResultsIsolatedSchema.php`
  - `tests/JavaScript/laboratoryOrderShare.test.mjs`
  - `tests/Unit/LaboratoryPreparation/LaboratoryPreparationRuleEngineAuditTest.php`

## Causa raiz confirmada de los fallos PDF

| Suite/test | Error observado | Causa raiz confirmada | Presente en main | Relacion con Indicaciones IA | Impacto | Severidad | Recomendacion | Bloqueante staging |
|---|---|---|---|---|---|---|---|---|
| `Tests\Unit\LaboratoryNotificationPdfRefreshTest` | Fallos por FK al insertar notificacion con `laboratory_purchase_id = 100` | Fixture invalida: el test creaba una notificacion ligada a una compra inexistente bajo MySQL con constraints reales | No comprobado | Indirecta; el test cubre PDF/resultados tocados por la rama, pero el fallo era de fixture | La prueba no media el producto; bloqueaba evidencia de regresion | Media | Crear una `LaboratoryPurchase` valida por prueba y usar `RefreshDatabase` | No, corregido |
| `Tests\Unit\LaboratoryNotificationPdfRefreshTest` | Fallo al crear `LaboratoryPurchase::factory()->create(['results' => ...])` | Factory sin defaults obligatorios para MySQL strict: faltaban campos como `gda_order_id` | No comprobado | Indirecta | Invalida la prueba bajo base real; no demuestra bug funcional | Media | Reemplazar por helper de compra de laboratorio completo | No, corregido |
| `Tests\Unit\LaboratoryNotificationPdfRefreshTest` | Badges de resultado actualizado no quedaban verificables tras cambiar hash PDF | Defecto de cobertura/aislamiento: no habia una compra valida y estable para separar hash de compra vs version de resultado | No comprobado | Indirecta | Riesgo de falso positivo sobre resultados actualizados | Alta mientras no habia evidencia | Agregar cobertura especifica de que solo cambiar `pdf_hash` de compra no marca resultados como actualizados | No, cubierto |
| `ResolveLaboratoryPurchasePdfPath` | El hash de PDF de compra podia cambiar por metadatos de preparacion no renderizados | `calculateContentHash()` incluia todo `LaboratoryPreparationPresenter::present()`, con metadatos volatiles como `source_hash`, `generated_at`, `prompt_version`, `has_ai_summary` y `source` | No comprobado | Directa: es el puente de Indicaciones IA hacia PDF de compra | Regeneracion innecesaria de PDF cacheado; no se comprobo notificacion indebida de resultado | Alta | Hashear solo campos visibles en el PDF: `ai_status`, `summary`, `studies` | No, corregido con cobertura |
| `RecordGdaResultPdfVersionAction` / estados de resultado | Posible notificacion de resultado actualizado cuando solo cambia preparacion | No reproducido. Las suites focalizadas de GDA/resultados pasaron y la nueva prueba confirma que cambiar solo el `pdf_hash` de compra no marca resultado actualizado | No comprobado | Indirecta | Riesgo teorico cerrado para el caso cubierto; versionamiento de resultado sigue separado del PDF de compra | Media | Mantener semantica de version/resultados; no cambiar notificaciones para hacer tests verdes | No, sin evidencia de regresion |

## Correcciones realizadas

- Se estabilizo el hash de PDF de compra en `ResolveLaboratoryPurchasePdfPath`: el hash ahora usa solo contenido de preparacion renderizado en el PDF y excluye metadatos no visibles de IA.
- Se corrigio `LaboratoryNotificationPdfRefreshTest` para correr con `RefreshDatabase` y fixtures validas de `LaboratoryPurchase`.
- Se agrego cobertura en `ResolveLaboratoryPurchasePdfPathHashTest`:
  - el hash permanece estable si solo cambian metadatos no renderizados de preparacion;
  - el hash rota si cambia contenido visible de preparacion.
- Se agrego cobertura en `LaboratoryNotificationPdfRefreshTest` para demostrar que modificar solo `pdf_hash` de compra no marca resultados como actualizados.
- No se cambiaron reglas clinicas R01-R18.
- No se cambio semantica de versiones de resultados ni notificaciones de GDA para forzar tests verdes.

## Aislamiento de base de datos validado

- `phpunit.xml` ya no fuerza `famedic_test`; define defaults de testing en `famedic_test_description_ia` y permite override por entorno.
- `.env.testing` apunta a MySQL de pruebas y documenta el uso preferido de bases descartables por corrida.
- `tests/TestCase.php` obtiene `DB_CONNECTION`/`DB_DATABASE` desde `TEST_DB_CONNECTION`/`TEST_DB_DATABASE` y conserva guardas contra bases no seguras.
- `tests/TestCase.php` intenta serializar ejecuciones que comparten base mediante `GET_LOCK`; se adquiere en `setUp()`, despues de que el ciclo de vida de Laravel puede haber ejecutado `RefreshDatabase` y migraciones. Por eso no constituye por si solo una garantia frente a dos ejecuciones directas concurrentes.
- `scripts/run-isolated-pest.sh` crea una DB descartable unica `famedic_test_description_ia_<timestamp>_<pid>`, otorga privilegios al usuario de pruebas, ejecuta Pest con JUnit no vacio, captura log completo y elimina la DB al salir.
- Las tres corridas documentadas se ejecutaron por ese script; `SHOW DATABASES LIKE 'famedic_test_description_ia_%';` no devolvio bases residuales. Esto confirma el aislamiento de esas corridas, sin demostrar que suites lanzadas directamente o por otro wrapper no compartan `famedic_test_description_ia`.

## Evidencia reproducible

| Bloque | Comando | Resultado | Duracion | Evidencia |
|---|---|---:|---:|---|
| PDF/hash | `bash scripts/run-isolated-pest.sh tests/Unit/LaboratoryNotificationPdfRefreshTest.php tests/Unit/ResolveLaboratoryPurchasePdfPathHashTest.php` | `8 passed (8 assertions)` | `79.31s` | JUnit: `storage/logs/junit-famedic_test_description_ia_20261014084203_250142.xml`; log: `codex/pest-famedic_test_description_ia_20261014084203_250142.log` |
| GDA/resultados relacionados | `bash scripts/run-isolated-pest.sh tests/Feature/Laboratory/GdaResultsPdfFreshnessIsolatedTest.php tests/Feature/Laboratory/GdaResultsStoragePhase3PatientAutocureIsolatedTest.php tests/Feature/Laboratory/GdaResultsStoragePhase4PatientAccessSemanticsIsolatedTest.php tests/Feature/Laboratory/GdaResultsStoragePhase5OtpAccessSemanticsIsolatedTest.php tests/Feature/Laboratory/GdaResultVersioningPhase1Test.php tests/Feature/Laboratory/GdaResultRefreshPhase2Test.php tests/Feature/Laboratory/GdaResultCompletionGatePhase3Test.php tests/Feature/Laboratory/LaboratoryResultExtractionEligibilityDispatchTest.php` | `85 passed (397 assertions)` | `180.57s` | JUnit: `storage/logs/junit-famedic_test_description_ia_20261014084347_250537.xml`; log: `codex/pest-famedic_test_description_ia_20261014084347_250537.log` |
| Render/presenter preparacion | `bash scripts/run-isolated-pest.sh tests/Feature/LaboratoryPreparationChannelRenderTest.php tests/Feature/LaboratoryPreparationPresenterTest.php` | `33 passed (162 assertions)` | `78.74s` | JUnit: `storage/logs/junit-famedic_test_description_ia_20261014084657_251331.xml`; log: `codex/pest-famedic_test_description_ia_20261014084657_251331.log` |
| JS indicaciones | `docker compose exec -T node bash -lc 'cd /var/www/html && npm test'` | `100 passed`, `0 failed`, `1 skipped` | `374.979391ms` | TAP en stdout; skip por Chromium sin `libnss3.so`; no JUnit JS generado |
| Sintaxis PHP | `php -l` sobre archivos modificados/nuevos | OK | n/a | `ResolveLaboratoryPurchasePdfPath.php`, `tests/TestCase.php`, `LaboratoryNotificationPdfRefreshTest.php`, `ResolveLaboratoryPurchasePdfPathHashTest.php` |
| Diff hygiene | `git diff --check` | OK | n/a | Solo warnings CRLF en scripts existentes no tocados por este cierre |

No se llamaron proveedores externos reales. Las corridas usaron Docker local, MySQL local de pruebas y Pest/Node locales.

## Fallos aun no clasificados

- Los 104 fallos de regresion amplia no fueron ejecutados como corrida masiva por instruccion expresa.
- Siguen sin clasificacion completa los 74 fallos reportados en GDA/resultados/admin fuera del lote focalizado que paso.
- Siguen sin clasificacion completa los 30 fallos reportados en checkout/citas/cupones/mail/PayPal.
- Quedan pendientes los fallos visibles historicos de ActiveCampaign marketing y comision de exportacion hasta correrlos en aislamiento.
- La suite JS conserva 1 skip por dependencia de Chromium (`libnss3.so`), no por Indicaciones IA.

## Riesgos actualizados

- PDF de compra: riesgo principal mitigado. Cambios de contenido visible de preparacion siguen regenerando PDF, como corresponde; cambios de metadatos no renderizados ya no rotan el hash.
- Resultados/GDA: sin evidencia actual de regresion en versionamiento o notificaciones; suites focalizadas pasaron y se agrego prueba contra falso resultado actualizado por `pdf_hash` de compra.
- Checkout: no fue cubierto por este cierre salvo por el aislamiento de DB; sigue pendiente verificar que el encolado de preparacion post-checkout no bloquee flujos ni toque proveedores externos.
- Base de pruebas: aislamiento validado para las corridas focalizadas lanzadas por `scripts/run-isolated-pest.sh`. Queda pendiente impedir o documentar como no soportadas las ejecuciones directas/concurrentes que usen el default fijo `famedic_test_description_ia`; el lock actual llega despues de posibles migraciones. La comparacion con `main` sigue pendiente y solo debe hacerse en worktree separado con DB descartable propia.
- Working tree: sigue sucio por cambios ajenos a este cierre; no pedir aprobacion de release sin separar o inventariar esos cambios.

## Siguiente lote minimo recomendado

1. Checkout/citas laboratorio: `LaboratoryCheckoutAppointmentFirstFlowTest`, `LaboratoryCheckoutFlowEligibilityTest`, `CartJourneyCheckoutFlowTest` y fases de checkout laboratorio modificadas por la rama.
2. Cupones/promos: suites de promo code/coupon aplicadas a checkout laboratorio.
3. Mail safety: suites de mail con fakes, sin envios reales.
4. PayPal: suites con mocks/fakes; no proveedores reales.
5. Admin/resultados: centro admin y resultados estructurados no incluidos en el lote focalizado.
6. Casos puntuales previos: `ActiveCampaign\MarketingIntelligenceConsolidationTest` y `LaboratoryPurchasesSheetCommissionTest`.

## Veredicto actualizado

GO condicionado para cerrar el bloque P1 de PDF/resultados y aislamiento de base de pruebas: los cinco fallos de `LaboratoryNotificationPdfRefreshTest` quedaron explicados o corregidos con evidencia, la regeneracion innecesaria por metadatos IA quedo mitigada, y las suites focalizadas pasan en DB descartable.

NO-GO para staging general todavia: los 104 fallos amplios no estan clasificados por causa raiz completa y falta ejecutar el siguiente lote minimo con la misma estrategia de aislamiento antes de solicitar excepcion o aceptacion de riesgo.

---

# Cierre de regresion por grupos A-E

Fecha local: 2026-10-14

## Identidad y seguridad de ejecucion

- Rama y SHA: `description-ia`, `ed82e41a159a97bd8f3c37661ff2c1cbbe1790ed`.
- Working tree: sucio antes de iniciar. Se preservaron todos los cambios existentes. Esta etapa agrega logs/JUnit y esta actualizacion del reporte.
- El runner se audito antes de correr. Sin `TEST_DB_DATABASE` override, crea un schema `famedic_test_description_ia_<timestamp>_<pid>`, concede acceso, pasa `APP_ENV=testing` y `DB_DATABASE`/`TEST_DB_DATABASE` al contenedor `app`, genera log/JUnit y elimina el schema al salir.
- Las conexiones MySQL de `config/database.php` no definen conexiones read/write separadas; el contenedor no expuso `DB_URL`/`DATABASE_URL`. Todas las corridas fueron secuenciales y cada una tuvo un nombre diferente. La consulta de schemas temporales no encontro residuos.
- La BD local `famedic` no fue destino de ninguna corrida; no hubo cambios de compras locales ni comandos destructivos. No se ejecuto comparacion en `main`.
- Limitacion del runner: permite que un llamador fuerce `TEST_DB_DATABASE`; por tanto, unicidad no esta garantizada si se invoca con override. En estas corridas no se uso.
- Riesgo de proveedor detectado: `.env.testing` deja `ACTIVE_CAMPAIGN_ENABLED=true` y `ACTIVECAMPAIGN_CART_OUTBOX_ENABLED=true`; el runner no los apaga. Aunque la URL configurada es `https://activecampaign.test/api/3` y no apunta al proveedor real, con `QUEUE_CONNECTION=sync` el job real de la aplicacion se ejecuto y fallo por contacto inexistente. No ejecutar casos que despachen ActiveCampaign sin fake o transporte bloqueado.
- PayPal solo se cubrio con rutas no autenticadas expresamente documentadas como sin sandbox real. Los tests GDA no realizaron llamadas de red; versionamiento fakea Notification/Bus. El monitor declara `Http::preventStrayRequests()` y mock de OpenAI.

## Resultados de las corridas

Todas las corridas fueron secuenciales por `scripts/run-isolated-pest.sh`, cada una con schema temporal propio. Las duraciones incluyen arranque y migraciones.

| Grupo | Cobertura ejecutada | Resultado | Duracion | Evidencia |
|---|---|---:|---:|---|
| A - Checkout/citas | 8 archivos: appointment-first flow/fases 4-5, elegibilidad, CartJourney, carrito vacio, resume link y dispatch preparacion | 102 passed, 7 failed (330 assertions) | 89.12 s | `codex/pest-famedic_test_description_ia_20261014085939_308416.log`; `storage/logs/junit-famedic_test_description_ia_20261014085939_308416.xml` |
| B - Cupones/saldo/promos | `tests/Feature/Coupons`, PromoCodeCheckout, admin coupons/OTP/authorization y `CouponServicesSmokeTest` | 124 passed, 98 failed (290 assertions) | 447.59 s | `codex/pest-famedic_test_description_ia_20261014090139_309080.log`; `storage/logs/junit-famedic_test_description_ia_20261014090139_309080.xml` |
| C - Pagos | PayPal routes, correlacion de intentos, payment method seleccionado y GDA result payload id | 26 passed (56 assertions) | 81.70 s | `codex/pest-famedic_test_description_ia_20261014090949_329511.log`; `storage/logs/junit-famedic_test_description_ia_20261014090949_329511.xml` |
| D - Correo/resultados | Versionamiento y notificacion de resultados; MailSafetyPolicy | 18 passed (38 assertions) | 11.89 s | `codex/pest-famedic_test_description_ia_20261014091129_330079.log`; `storage/logs/junit-famedic_test_description_ia_20261014091129_330079.xml` |
| E - Monitor IA | `LaboratoryPreparationMonitorTest` | 1 passed, 5 failed (8 assertions) | 15.14 s | `codex/pest-famedic_test_description_ia_20261014091146_330419.log`; `storage/logs/junit-famedic_test_description_ia_20261014091146_330419.xml` |
| E - Resultados admin | `LaboratoryResultsCenterTest` | 12 failed (0 assertions) | 46.46 s | `codex/pest-famedic_test_description_ia_20261014091203_330762.log`; `storage/logs/junit-famedic_test_description_ia_20261014091203_330762.xml` |
| E - Billing admin | `LaboratoryBillingModuleIsolatedTest` | 24 passed, 19 failed (170 assertions) | 92.77 s | `codex/pest-famedic_test_description_ia_20261014091302_331121.log`; `storage/logs/junit-famedic_test_description_ia_20261014091302_331121.xml` |

## Matriz final de resultados nuevos

| Grupo | Test o suite | Causa | Impacto | Relacion con Indicaciones IA | Estado | Bloqueante | Accion siguiente |
|---|---|---|---|---|---|---|---|
| A | `LaboratoryCheckoutAppointmentFirstPhase4Test`: dos URLs de pago | Tests esperan `step=payment`/`step=confirmation`, pero la accion devuelve URL opaca `/laboratory/checkout/resume/{token}`. La accion no difiere de `main`; no se corrio main | Contrato del enlace checkout no coincide con expectativa | Ninguna directa conocida | Si es preexistente: no comprobado | Si para cerrar checkout | Confirmar contrato funcional y ajustar test o accion solo con decision del responsable |
| A | Cuatro casos de fases 4-5 de cita | `QUEUE_CONNECTION=sync` ejecuta `DispatchActiveCampaignOutboundJob`; el test no fakea el dispatcher y el contacto de fixture no existe (`ActiveCampaignSyncException`) | La suite mezcla cita con integracion ActiveCampaign | Indirecta, marketing fuera de Indicaciones IA | Fuga de aislamiento confirmada; no demuestra regresion funcional | Si para aceptar el resultado del grupo | Fakear dispatcher/cola o bloquear transporte y repetir solo los casos |
| A | `CartJourneyCheckoutFlowTest::confirms_tests_run_only_against_testing_sqlite_database` | Asercion exige SQLite, mientras runner configura MySQL temporal | La suite no acepta el backend aislado | Infraestructura de pruebas | Incompatibilidad confirmada; no es defecto de checkout | No para producto; si para validar suite | Parametrizar driver/nombre o ejecutar en backend de pruebas aprobado |
| B | Cupones, beneficiarios, saldo y PromoCode: 98 fallos | Predominan SQLSTATE 42S02 (tablas ausentes) y 42S22 (columnas ausentes); fixtures minimos manuales no cubren relaciones, permisos y notificaciones. Algunos casos disparan ActiveCampaign con cola sync | No permite concluir comportamiento funcional de cupones; varios endpoints fallan en setup/schema | Sin relacion directa conocida | Fixture/aislamiento MySQL defectuoso; presencia en main no comprobada | Si, hasta repetir con setup valido | Completar schema/mocks y correr cada suite que manipula schema en proceso/schema propio |
| C | PayPal, correlacion de pagos, metodo de pago y resolucion id GDA | Sin fallos; PayPal solo prueba rutas no autenticadas, no sandbox | Sin regresion observada dentro del alcance | Ninguna directa | Passed | No para esta cobertura | Completar pago autenticado, doble cobro e incertidumbre con gateways fake |
| D | `GdaResultVersioningPhase1Test`, `MailSafetyPolicyTest` | Sin fallos; Notification/Bus fakeados y mail en array | Sin regresion observada dentro del alcance | Resultados son adyacentes; IA no interviene en esos casos | Passed | No para esta cobertura | Sin correccion indicada por esta evidencia |
| E | `LaboratoryPreparationMonitorTest` | 5 endpoints dan 500 porque schema de test carece de `notifications`; la prueba de control de acceso pasa | Monitor IA no tiene validacion funcional completa | Directa | Defecto de schema de test confirmado; producto no probado | Si para monitor IA | Completar schema local o usar schema completo y repetir |
| E | `LaboratoryResultsCenterTest` | 12 pruebas fallan al crear FK con nombre autogenerado superior al limite MySQL de 64 caracteres | Centro de resultados no llega a ejecutar aserciones | Superficie adyacente | DDL de test incompatible con MySQL confirmado; main no comprobado | Si para admin resultados | Asignar nombre corto a FK en fixture; no tocar esquema productivo sin evidencia |
| E | `LaboratoryBillingModuleIsolatedTest` | 19 respuestas fallan porque schema de test omite `laboratory_notifications`; 24 pasan | Admin billing parcialmente sin evidencia | Ninguna directa conocida | Fixture incompleto confirmado; main no comprobado | Por determinar para billing; bloquea evidencia completa | Completar schema local y repetir suite aislada |

## Totales y clasificacion

- Corridas A-E: **436 tests**, **295 passed**, **141 failed**, **892 assertions**. Los 141 fallos no son 141 defectos de producto; muchos ocurren antes de la logica por tablas/columnas ausentes o DDL MySQL invalido.
- Fallos funcionales confirmados atribuibles a Indicaciones IA v3 en estos grupos: **0**.
- Fallos preexistentes demostrados corriendo `main`: **0**. No se ejecuto main porque no se preparo worktree temporal con base independiente.
- Problemas de test/aislamiento confirmados: assert SQLite incompatible con MySQL; dispatch ActiveCampaign sin fake; schemas de prueba incompletos o incompatibles con MySQL en cupones, monitor, resultados y billing.
- El contrato de URL de pago no coincide con dos expectativas A. Sin comparacion de `main` o decision funcional, queda indeterminado, no se etiqueta preexistente ni regresion.
- Cada corrida tiene log completo y JUnit no vacio. La suite JS del cierre P1 no se repitio.

## Veredicto actualizado

**NO-GO para staging general.** C y D pasan en el alcance ejecutado y el bloque P1 previo de PDF/resultados sigue aprobado. A no tiene resultado limpio; B no aporta evidencia funcional confiable por fixtures/schema y ejecucion de ActiveCampaign sin fake; E no valida adecuadamente monitor, resultados admin y billing. No hay base para declarar los fallos preexistentes: `main` no se ejecuto.

No se aplicaron correcciones en esta etapa. Antes de cambiar producto, las acciones minimas son: fakear/bloquear ActiveCampaign en tests, corregir schemas de prueba MySQL (FK corto y tablas de notificaciones), aclarar el contrato de URLs de pago y repetir suites aisladas individualmente. Despues completar PayPal autenticado, proteccion de doble cobro/incertidumbre, correo de preparacion y admin de ordenes.

---

# Addendum: Aislamiento reforzado y suites corregidas

Fecha local: 2026-10-14. Este addendum reemplaza las limitaciones de runner/schema descritas arriba solo para las corridas repetidas aquí; no implica que se haya repetido el lote A-E completo.

## Seguridad y cambios de infraestructura

- Runner endurecido en `scripts/run-isolated-pest.sh`: rechaza `TEST_DB_DATABASE` heredada, crea un schema nuevo con timestamp/PID/aleatorio sin `IF NOT EXISTS`, solo lo elimina si esta corrida lo creó, y pasa argumentos de Pest por posición (sin interpolar `$*`). ActiveCampaign queda apagado, OpenAI sin clave, correo array y cola sync. La ejecución se invocó con `bash scripts/run-isolated-pest.sh` porque el archivo no tiene bit ejecutable.
- `tests/TestCase.php` exige la marca del runner, nombre único `famedic_test_description_ia_*`, backend MySQL y coincidencia del nombre real de la conexión antes del setup; bloquea HTTP saliente no fakeado mediante `Http::preventStrayRequests()`. El teardown restablece `RefreshDatabaseState` si un schema manual retiró la tabla `migrations`.
- `.env.testing` y `phpunit.xml` ahora deshabilitan ActiveCampaign. No se llamó a proveedores externos; los casos A de ActiveCampaign dejaron de fallar por ejecución síncrona/contacto ausente. No se aplicó `Queue::fake()` global.
- Schemas de test completados con `notifications`, `customers`, `administrators`, `laboratory_notifications` y columnas que el middleware/modelos consultan. El FK de AI explanations usa identificador MySQL corto. No hubo cambios productivos en checkout, reglas clínicas, resultados o cupones.
- Preflight post-corridas: no quedaron schemas temporales `famedic_test_description_ia_*`. Las bases `famedic` y `famedic_test` siguen existiendo y no fueron destino. No hay conexiones read/write separadas en `config/database.php`; ejecución secuencial, sin bases compartidas.

## Corridas posteriores

| Corrida | Resultado | Evidencia |
|---|---|---|
| Monitor + Results Center, primer intento tras FK corto | Monitor 6/6 passed; Results Center 11 failed por `notifications` faltante; confirmó segunda pieza incompleta del fixture | `codex/pest-famedic_test_description_ia_20261014092514_432445_21958.log`; JUnit homónimo en `storage/logs/` |
| Results Center aislado tras completar `notifications` | 12 passed, 151 assertions, 56.57 s | `codex/pest-famedic_test_description_ia_20261014092640_444557_6921.log`; JUnit homónimo |
| Billing, primer intento tras schema mínimo | 24 passed, 19 failed por columnas `user_id`/`read_at` faltantes en `laboratory_notifications` | `codex/pest-famedic_test_description_ia_20261014092743_454139_22566.log`; JUnit homónimo |
| Billing tras completar columnas | 43 passed, 401 assertions, 98.08 s | `codex/pest-famedic_test_description_ia_20261014092932_470106_10309.log`; JUnit homónimo |
| `tests/Feature/Coupons` completo con primer arreglo de fixtures | 152 passed, 3 failed, 419 assertions, 542.28 s. Fallos: `administrators` ausente en un helper y `laboratory_notifications` ausente en dos endpoints | `codex/pest-famedic_test_description_ia_20261014093117_485455_28572.log`; JUnit homónimo |
| Beneficiary Report + Coupon Revocation Admin después de agregar tablas | Report: 12/12 passed. En revocación, fallos de infraestructura sucesivos identificaron permisos nav requeridos por middleware compartido | `codex/pest-famedic_test_description_ia_20261014094038_511299_11245.log`; JUnit homónimo |
| Dos endpoints de revocación tras completar permisos/tablas | Ambos alcanzan respuesta HTTP 403; los tests esperan redirect (3xx). No es ya fallo de schema; no se cambió expectativa/controlador | `codex/pest-famedic_test_description_ia_20261014094410_543230_16200.log`; JUnit homónimo |
| Checkout Phase 4 + Phase 5 con AC deshabilitado y HTTP protegido | 23 passed, 2 failed, 52 assertions, 83.70 s. Phase 5 verde; Phase 4 falla solo en las dos expectativas de URL | `codex/pest-famedic_test_description_ia_20261014094426_546524_13290.log`; JUnit homónimo |

Los logs incluyen salida completa y las corridas produjeron JUnit no vacío. Cada invocación tuvo base temporal propia, borrada al finalizar; las verificaciones de sintaxis PHP y `git diff --check` pasaron. La instalación local no tiene `php` en el host, por lo que lint PHP se ejecutó dentro del contenedor `app`.

## Causas actualizadas y conteo

- 4 fallos de A por `ActiveCampaignSyncException`: aislados al apagar la integración de test; sus casos pasan en la repetición de Phase 4/5. El runner además bloquea futuras solicitudes HTTP no fakeadas.
- 2 fallos de A permanecen: expectativas `step=payment` y `step=confirmation` frente al enlace opaco.
- 1 fallo de A no repetido: `CartJourneyCheckoutFlowTest::confirms_tests_run_only_against_testing_sqlite_database`, que exige SQLite mientras este preflight ejecuta MySQL aislado.
- E Monitor: causa `notifications` faltante resuelta, 6/6 passed.
- E Results Center: FK autogenerado largo y luego tabla `notifications` ausente; corregidos en fixture, 12/12 passed.
- E Billing: tabla y columnas ausentes en `laboratory_notifications`; corregido en fixture, 43/43 passed.
- B Cupones: en la primera corrida del directorio completo hubo 3 fallos de setup entre 155: un schema de admin incompleto y dos endpoints que necesitaban laboratorio-notifications. El reporte de beneficiarios (12 tests) pasó al repetirlo. Tras reparar los schemas, dos pruebas de endpoints ahora llegan a 403 donde esperan 3xx. Se clasifica como discrepancia de contrato/expectativa a revisar, no como fallo de infraestructura ni regresión confirmada. No se volvió a correr todo el directorio tras el último cambio del helper.
- Errores funcionales de Indicaciones IA confirmados en las suites repetidas: 0. Preexistencia ejecutando `main`: no comprobada.

## Diagnóstico checkout

`PrepareLaboratoryCheckoutPaymentLinkAction` calcula el paso (`payment` o `confirmation`) y delega la creación del enlace. `GenerateLaboratoryCheckoutResumeLinkAction` genera un token aleatorio de 256 bits, persiste su hash/expiración y devuelve la ruta `laboratory.checkout.resume` (`/laboratory/checkout/resume/{token}`). `ResolveLaboratoryCheckoutResumeLinkAction` valida token/usuario/carrito y redirige al checkout con el paso del borrador. Por tanto, el contrato implementado actualmente es enlace opaco y la expectativa de query URL tradicional no describe este contrato. El diff estático `main...HEAD` no muestra cambios para esas acciones ni para los dos tests; esto descarta que esos archivos hayan sido introducidos por la rama, pero no equivale a ejecutar las pruebas en main. La navegación no se modificó y no se afirma ausencia absoluta de regresión dinámica.

## Archivos de esta etapa

- `scripts/run-isolated-pest.sh`
- `.env.testing`, `phpunit.xml`
- `tests/TestCase.php`
- `tests/Feature/Coupons/couponIsolatedSchema.php`
- `tests/Feature/Admin/LaboratoryPreparationMonitorTest.php`
- `tests/Feature/Admin/LaboratoryResultsCenterTest.php`
- `tests/Feature/Admin/LaboratoryBilling/LaboratoryBillingModuleIsolatedTest.php`

Los demás cambios ya presentes en el working tree se preservaron. No se hizo commit, push, deploy, cambio de flags fuera del entorno de tests ni ejecución en `main`.

## Veredicto y siguiente paso

**NO-GO para staging general.** Se recuperó evidencia útil de monitor (6/6), resultados admin (12/12), billing (43/43) y se aislaron las llamadas de ActiveCampaign. Siguen pendientes: la decisión sobre URLs checkout, el test CartJourney específico de SQLite, dos expectativas HTTP de cupones (`403` esperado implícitamente como redirect 3xx), y las suites B completas fuera del directorio Coupons / otros A-E aún no repetidos. No se puede inferir que los fallos amplios restantes sean preexistentes sin evidencia ejecutada en main.

Acción mínima: revisión del responsable sobre los contratos 403 y checkout; después corregir solo expectativas aprobadas y correr en secuencia el test CartJourney en backend compatible, los dos tests de revocación, los archivos A restantes y el resto de B contra DB descartables separadas. No hace falta volver a ejecutar monitor/results/billing que ya quedaron verdes salvo que cambien sus fixtures.

---

# Cierre focalizado de bloqueantes restantes para staging

Fecha local: 2026-10-14. Rama `description-ia`, SHA `ed82e41a159a97bd8f3c37661ff2c1cbbe1790ed`.

## Preflight y evidencia

- Se revisaron los runners MySQL y SQLite. Las corridas Pest/MySQL aquí registradas fueron secuenciales, con schema único `famedic_test_description_ia_<timestamp>_<pid>_<random>` por invocación; los runners rechazan `TEST_DB_DATABASE` externo y solo eliminan el schema creado por esa ejecución. El runner SQLite usa archivo temporal único y lo elimina al salir.
- `TestCase` exige marca del runner, entorno testing y una conexión cuyo nombre coincide con la BD temporal; `Http::preventStrayRequests()` bloquea HTTP no simulado. `.env.testing`/PHPUnit y el runner desactivan ActiveCampaign/OpenAI, el correo usa transporte array y la cola es síncrona. No se usó `Queue::fake()` global.
- No se invocaron proveedores reales ni se lanzaron comandos destructivos. No se modificaron compras locales. El preflight previo de schemas temporales no encontró residuos MySQL; la inspección local no encontró archivos SQLite temporales. No se pudo repetir aquí la consulta SQL de schemas por autenticación root del contenedor, así que esta última comprobación depende de los logs y garantías de cleanup del runner.
- `git diff --check`, `bash -n` de ambos runners y `php -l` de los tests modificados pasaron. Las corridas reportadas tienen log y JUnit propio no vacío. El `junit-global.xml` de cero bytes es previo y no se usa como evidencia.
- No se comparó dinámicamente contra `main`; no se preparó un worktree con base separada. El diagnóstico estático de las Actions de checkout no evidencia diferencias frente a `main`.

## Causas raíz confirmadas

| Tema | Diagnóstico y evidencia | Acción realizada | Resultado |
|---|---|---|---|
| Checkout, 2 expectativas Phase 4 | Producción genera enlace opaco, no URL tradicional con `step` en query. Se persiste SHA-256 de token aleatorio de 256 bits con expiración; el resolver valida token, usuario/cliente y carrito y redirige según el paso guardado. La suite resume cubre secreto del hash, expiración, token inválido/usuario ajeno y destino. Las Actions no difieren en el diff estático `main...HEAD`. | Se actualizaron solo las expectativas a la ruta opaca y se añadieron comprobaciones de token/hash/expiración y destino en appointment-first; sin tocar producción. | 2/2 pass, 13 assertions. La expectativa antigua era obsoleta respecto al contrato de enlace opaco. No prueba por sí sola el destino estándar con historial (riesgo abierto abajo). |
| Cupones, 2 revocaciones | Ambos endpoints llaman `authorize('update', $coupon)`. `CouponPolicy::update` requiere `cupones.edit` o `coupons.manage`; los usuarios de estos tests no tienen esos permisos. El 403 es la autorización vigente. | Se cambiaron únicamente las expectativas 3xx por `assertForbidden`; se preservaron asserts de que no se alteran los datos. Sin ampliar permisos. | 2/2 pass, 6 assertions. |
| Beneficiary report, posible intermitencia | Dos ejecuciones independientes con schema nuevo cada vez. | Sin cambio funcional. | Ambas 12/12 pass, 32 assertions: `codex/pest-famedic_test_description_ia_20261014100323_676292_31347.log` y `...20261014100413_684280_8410.log`. Reduce la hipótesis de contaminación entre corridas; no descarta toda dependencia de orden intra-suite. |
| CartJourney/SQLite | Una migración consulta `information_schema.TABLE_CONSTRAINTS`, incompatible con SQLite; el flujo funcional no requiere ese driver. | Se trasladó solo la aserción de configuración a `CartJourneyDatabaseConfigurationTest`, ejecutada por runner SQLite desechable. El feature sigue usando MySQL aislado. | CartJourney 14/14 (45 assertions, 83.71 s); config SQLite 1/1 (3 assertions, 0.30 s). El primer intento SQLite falló antes de asserts por migración incompatible; no cambió el contrato funcional. |
| Fixture Admin coupons | MySQL exige el morph `customerable`; la factory usada no creaba cuenta regular asociada. | Fixture actualizado a `Customer::factory()->withRegularAccount()`. | Intento inicial 12/13; tras el arreglo 13/13 (95 assertions). |

## Suites B fuera de la corrida anterior

Se ejecutaron individualmente con schemas nuevos los seis archivos no cubiertos por `tests/Feature/Coupons`:

| Suite | Resultado | Evidencia de log y JUnit |
|---|---:|---|
| `tests/Feature/Admin/CouponAssignCreditTypesTest.php` | 7 passed, 24 assertions | `codex/pest-famedic_test_description_ia_20261014100500_691759_134.log` y JUnit homónimo |
| `tests/Feature/Admin/CouponAssignOtpTest.php` | 14 passed, 32 assertions | `codex/pest-famedic_test_description_ia_20261014100627_703917_24965.log` y JUnit homónimo |
| `tests/Feature/Admin/CouponAuthorizationInboxTest.php` | 13 passed, 95 assertions tras arreglo de fixture | `codex/pest-famedic_test_description_ia_20261014100755_711714_32144.log` (fallo inicial) y `...20261014100925_712174_16488.log` (verde), JUnit homónimos |
| `tests/Feature/Admin/PromoCodeAdminTest.php` | 16 passed, 61 assertions | `codex/pest-famedic_test_description_ia_20261014101052_724678_31846.log` y JUnit homónimo |
| `tests/Feature/Laboratory/PromoCodeCheckoutTest.php` | 16 passed, 61 assertions | `codex/pest-famedic_test_description_ia_20261014101220_737700_25726.log` y JUnit homónimo |
| `tests/Unit/CouponServicesSmokeTest.php` | 1 passed, 3 assertions | `codex/pest-famedic_test_description_ia_20261014101347_750228_10445.log` y JUnit homónimo |

La suite de resume opaco pasó 12/12 (32 assertions). No se repitió todo el directorio Coupons de 155 pruebas; la corrida previa fue 152/155. Los dos casos 403 se aislaron y pasaron, sin evidencia de que los otros 153 casos hayan cambiado.

## Riesgo funcional abierto: reanudación con compras históricas

En el caso estándar con compras históricas, al seguir el enlace recién generado el resolver redirigió a `/laboratory-purchases/1`, no al checkout de confirmación guardado. `ResolveLaboratoryCheckoutResumeLinkAction` consulta `Cart::relatedLaboratoryPurchase()`. Si no hay asociación explícita ni fecha de compra del cliente, ese método usa como fallback la compra más reciente del cliente. `git blame` atribuye el fallback a `e10eaa54d` (mayo de 2026); el diff estático no lo identifica como cambio de esta rama.

No hay evidencia suficiente para saber si la asociación histórica global es intencional o si el enlace nuevo debe reanudar el carrito actual. No se cambió la lógica ni se añadió una expectativa que enmascare el destino. El responsable funcional debe elegir entre conservar el fallback legacy o limitarlo a compra explícitamente asociada/al carrito. Hasta esa decisión y verificación, la navegación estándar es riesgo funcional bloqueante.

## Matriz final

| Grupo | Test/suite | Causa | Impacto | Relación con Indicaciones IA | Estado | Bloqueante | Acción siguiente |
|---|---|---|---|---|---|---|---|
| A | Dos tests checkout Phase 4 | Expectativas de URL tradicional obsoletas | Contrato ahora comprobado con seguridad y paso esperado | Ninguna directa identificada | Tests actualizados, 2/2 | No | Mantener assertions de token, expiración y destino |
| A | Reanudación estándar con compras previas | Fallback de `relatedLaboratoryPurchase()` puede seleccionar compra histórica no asociada al carrito | Puede navegar fuera del checkout reanudado | Checkout adyacente; no directa | Intención indeterminada | Sí | Decisión funcional y test específico antes de modificar producto |
| B | Dos tests de revocación | Usuario sin permiso; policy retorna 403 | No debe ejecutarse revocación no autorizada | Ninguna directa identificada | Expectativas actualizadas, 2/2 | No | Conservar autorización vigente |
| B | Beneficiarios | Sospecha de intermitencia | Sin fallo en dos procesos aislados | Ninguna directa identificada | 12/12 en ambas | No según evidencia | Revisitar si reaparece en corrida completa |
| CartJourney | Configuración SQLite | Migraciones no compatibles con SQLite, no el flujo | Config separada del feature test | Checkout adyacente | 14/14 MySQL y config 1/1 SQLite | No | Mantener división |
| B | Seis suites fuera de Coupons | Un fixture morph inválido, corregido | Admin/checkout promo queda validado en alcance focalizado | Ninguna directa identificada | Todas verdes tras fixture | No | Sin corrección adicional |

## Totales y veredicto

- Corridas finales focalizadas: 122 ejecuciones de test aprobadas (incluye dos repeticiones independientes de los mismos 12 tests de beneficiarios). Hubo además un intento inicial de AuthorizationInbox con un fallo de fixture y un primer intento SQLite que falló antes de assertions; ambos tienen explicación y repetición verde.
- Fallos de Indicaciones IA confirmados en esta ronda: 0. Fallos demostrados ejecutando `main`: 0; no hubo corrida dinámica en main.
- Fallo funcional pendiente: el destino de reanudación estándar si el cliente tiene compras previas. La aserción del enlace opaco no resuelve esa cuestión.
- No se repitió el lote A-E completo ni toda la suite Coupons; los verdes focalizados no equivalen a aprobación completa de regresión.

**Veredicto: NO-GO para staging hasta decidir y validar el destino de reanudación estándar que hoy puede enviar al usuario a una compra histórica.** Después de la decisión, la acción mínima es añadir/ajustar el test de ese destino y ejecutar las suites completas directamente afectadas con runner descartable. No se modificaron permisos ni lógica de negocio, y no hubo commit, push, deploy, activación de flags o llamadas a proveedores reales.

---

# Diagnóstico reproducible del destino de reanudación

Fecha local: 2026-10-14. Rama `description-ia`, SHA `ed82e41a159a97bd8f3c37661ff2c1cbbe1790ed`. El working tree ya estaba modificado; en esta actividad solo se añadieron pruebas de reproducción y esta documentación. No se cambió lógica productiva.

## Reproducción aislada

Se añadieron dos tests a `tests/Feature/LaboratoryCheckoutResumeLinkTest.php`:

- `resumes a pending cart instead of redirecting to an unrelated historical purchase` crea un carrito activo con draft `payment`, una compra del mismo cliente sin `cart_id`, genera token válido e intenta reanudar. La expectativa es el checkout guardado. Falla actualmente y reproduce el defecto: esperaba `http://localhost/laboratory/olab/checkout?step=payment` y recibió `http://localhost/laboratory-purchases/1`.
- `prefers the explicitly associated purchase over a newer unrelated purchase` protege la precedencia de la compra cuyo `cart_id` sí coincide. Pasó.

Comando: `bash scripts/run-isolated-pest.sh tests/Feature/LaboratoryCheckoutResumeLinkTest.php`.

El runner creó la base `famedic_test_description_ia_20261014102053_812758_18051`, independiente y descartable; al terminar, la consulta de `information_schema.schemata` devolvió cero para ese nombre. No se conectó a `famedic` ni a `famedic_test`. El runner apagó ActiveCampaign/OpenAI, usó mail array y `Http::preventStrayRequests()`; no hubo llamadas externas.

Resultado: 13 passed, 1 failed, 48 assertions, 81.71 s. Log completo: `codex/pest-famedic_test_description_ia_20261014102053_812758_18051.log`. JUnit no vacío: `storage/logs/junit-famedic_test_description_ia_20261014102053_812758_18051.xml`. Los casos existentes de token aleatorio, manipulado, expirado y de otro cliente pasaron; el caso existente de compra explícita también pasó.

## Causa raíz y comparación con main

`ResolveLaboratoryCheckoutResumeLinkAction` llama a `$cart->relatedLaboratoryPurchase()` antes de comprobar si el carrito sigue pendiente. En ausencia de vínculo explícito, ese método busca la compra más reciente del customer cuando `completed_at` es null. Por eso una compra antigua sin `cart_id` hace que el resolver clasifique como completado el carrito nuevo y envíe al detalle de la compra histórica.

El análisis estático muestra que `main` tiene el mismo resolver y el mismo fallback. `git blame` sitúa el método original en `e10eaa54d`; el fallback no fue introducido por `description-ia`. La conclusión es una deficiencia preexistente demostrada estáticamente, no una regresión de Indicaciones IA.

Otros consumidores actuales de `relatedLaboratoryPurchase()` son `Admin\\CartController` (detalle/serialización de monitor, rutas de datos de carrito y síntesis de checkout completado), `Cart` internamente (presentación de citas y brands de compras completadas) y `AuditCartTraceabilityCommand`. El fallback tiene uso legacy fuera del resolver; cambiar el método global puede alterar asociación histórica en administración y auditoría, por lo que no se recomienda hacerlo como corrección mínima.

## Seguridad y efectos colaterales

- La validación de token hash, revocación/expiración, autenticación, customer propietario, carrito y usuario se ejecuta antes de decidir el destino. Las pruebas existentes correspondientes pasaron.
- `laboratory-purchases.show` aplica `authorize('view', $laboratoryPurchase)`. La policy permite al propietario customer y a administradores con permiso. Un enlace de otro usuario es rechazado antes de llegar al fallback; por tanto el defecto no demuestra acceso a la compra de otra persona. Sí produce navegación engañosa a una compra propia pero no relacionada con el carrito.
- La resolución es GET y no inicia el pago ni crea citas. El riesgo inmediato de doble cobro/duplicación de citas por esta rama del resolver es bajo/no observado. Aun así, redirigir un carrito pendiente a una compra previa puede ocultar el estado real del checkout y confundir la reanudación; conviene cubrir también carritos completados sin vínculo explícito para que no seleccionen una compra arbitraria.
- `relatedLaboratoryPurchase()` puede seguir usando fallback en consumidores legacy; retirar el fallback global no es parte de esta recomendación.

## Comportamiento recomendado y cambio mínimo propuesto

Sujeto a aprobación funcional: en el resolver, sustituir únicamente la consulta de compra por `$cart->explicitLaboratoryPurchase()`. Si hay compra explícitamente ligada por `cart_id`, mantener el destino a esa compra. Si no hay compra explícita y el carrito está completado, conservar la rama segura existente hacia `user.purchases.index`. Si está pendiente, seguir la ruta actual de verificación de ítems y draft y redirigir al paso guardado. No seleccionar compras por `customer_id` únicamente. Una asociación inconsistente debe caer en ese comportamiento seguro y dejar registro observable, sin inferir una orden.

Archivos propuestos después de autorización: `app/Actions/Laboratories/ResolveLaboratoryCheckoutResumeLinkAction.php` (cambio mínimo productivo) y `tests/Feature/LaboratoryCheckoutResumeLinkTest.php` (conservar las dos reproducciones añadidas y las pruebas de tokens). No cambiar `app/Models/Cart.php`, `CartController`, contratos URL, pagos ni citas.

## Autorización y estado

La propuesta de criterio fue presentada por el usuario como pendiente de confirmación. Esta ronda no la interpreta como autorización para cambiar negocio: solo modificó el archivo de tests y este reporte. La prueba de reproducción está intencionalmente roja en el comportamiento actual. Recomendación de release: mantener **NO-GO** para staging hasta aprobar la semántica propuesta y después aplicar el cambio mínimo, ejecutar la clase de resume completa y las suites de carrito/admin que validen los consumidores cercanos. No hubo commit, push ni deploy.

---

# Regresión final de liberación — ejecución actual

Fecha local: 2026-10-14. Rama `description-ia`, HEAD `ed82e41a159a97bd8f3c37661ff2c1cbbe1790ed`, merge-base `20109d3c17561db656e7620d7bba641fc07f98ca`. HEAD permaneció igual. El working tree está modificado y sin commit; las pruebas usaron el código actual, incluidos el resolver y tests de reanudación. No hubo commit, push, deploy, activación de flags ni correcciones automáticas.

## Inventario

Archivos tracked modificados: `.env.testing`, `phpunit.xml`, las dos Actions de checkout/PDF, `Documentation.php`, tres servicios/evaluadores de preparación, y tests de checkout, resultados, billing, cupones, monitor, TestCase y reglas IA visibles en `git status`. Nuevos no ignorados: este informe, los runners aislados MySQL/SQLite, tests de configuración SQLite y hash PDF, más logs/JUnit. No hay archivos borrados en el working tree. La comparación de rama con merge-base da 380 paths (336 añadidos, 42 modificados, 2 eliminados), no equivalente al diff local.

**Advertencia clínica de inventario:** al iniciar esta auditoría ya estaban modificados `GeneralPreparationCompatibilityEvaluator.php`, `SampleCompatibilityEvaluator.php` y `LaboratoryPreparationRuleEngineAuditTest.php`. El diff modifica la consolidación de abstinencia R07 (solo rangos idénticos) y el manejo de contenedores R18 (conflictos llevan a FALLBACK). No se tocaron durante esta ejecución; F valida los casos añadidos y los oficiales, pero el working tree contiene cambios de reglas clínicas y no es posible afirmar “sin cambios R01–R18”. Requieren revisión/aprobación clínica separada antes de incluirse en staging.

Hay 78,460 entradas ignoradas en el workspace; `.env` y `docs/*` están ignorados. No se inspeccionó ni alteró el contenido de secretos. El reporte IA bajo `docs/*` se actualizó localmente a petición del usuario, pero permanece ignorado por Git. Algunos directorios `storage` no se pudieron recorrer por permisos. No se identificó contenido sensible nuevo en los cambios revisados; no se hizo auditoría de credenciales locales.

## Aislamiento y suites

Los grupos PHP se ejecutaron serialmente mediante `bash scripts/run-isolated-pest.sh`, con schema MySQL descartable único por invocación, rechazo de overrides a BD fija, `APP_ENV=testing`, `DB_DATABASE` temporal, ActiveCampaign falso, OpenAI sin key, mail array, queue sync y `Http::preventStrayRequests()`. No se ejecutaron proveedores reales ni se apuntó deliberadamente a `famedic`/`famedic_test`. La protección de HTTP es a nivel Laravel, no un sandbox de red del sistema. Se preservaron logs/JUnit.

| Grupo | Alcance | Resultado actual | Evidencia |
|---|---|---:|---|
| A | Checkout, carritos, citas, reanudación, dispatch (14 archivos) | 185 PASS, 1 FAIL, 626 assertions, 95.68 s | Schema `...20261014104937_832585_24060`; log/JUnit con sufijo. Único fallo: test requiere SQLite corrido bajo MySQL. El intento aislado SQLite no llegó a assertions: runner no crea el archivo `.sqlite`; JUnit `storage/logs/junit-sqlite-20261014105126_833022_27939.xml`. |
| B | Coupons completo y suites admin/promo separadas | 222 PASS, 700 assertions | Tres schemas independientes; logs/JUnit de invocaciones 11:01, 11:11 y 11:13. Un intento combinado previo terminó por memory limit antes de JUnit, no se cuenta. |
| C | Métodos de pago, correlación, rutas PayPal, sanitizador y payload GDA | 32 PASS, 78 assertions | Runner MySQL aislado. No se encontraron tests dedicados a doble cargo ni estado GDA uncertain; brecha de cobertura, no se simularon proveedores reales. |
| D | PDF/hash, mail, versionado/refresh/completion/freshness, resultados GDA, QA review, webhook | 76 PASS, 20 FAIL, 274 assertions, 242.50 s | Schema `...20261014111637_837048_15905`, log/JUnit del mismo sufijo. Ocho casos de `GdaResultsNotAvailableTest` fallan por fakes HTTP ausentes y guard anti-request; uno de vista de compra discrepa en estado de orden vacía; once casos admin QA Review responden 403 ante expectativas 200/500. |
| E | Monitor IA, Results Center, billing y admin de cupones | 110 PASS, 1 FAIL, 995 assertions, 255.03 s | Schema `...20261014112124_837856_21108`, log/JUnit del mismo sufijo. Monitor 6/6, Results Center 12/12 y billing 43/43. `CouponAuthorizationInboxTest` falla al recrear `laboratory_notifications`, ya existente en el schema compartido. |
| F | Unidad de preparación/R01–R18/QA01–QA40 y features persistencia, dispatch, redactor, presenter, validación, servicio, correo/PDF | 296 PASS, 4,875 assertions, 0 omitidos | Schemas `...112545_838334_25785` (172/4,357) y `...112552_838540_4730` (124/518), logs/JUnit por sufijo. Incluye FALLBACK originales, AUTO estructurado validado, rollback de flag y sin OpenAI real. |
| G | JavaScript relacionado | 30 PASS, 0 FAIL/SKIP, 76.304 ms | TAP de `node --test` para cuatro archivos JS; sin JUnit PHP. |
| Estilo | Pint en 22 PHP modificados; `git diff --check` | Pint FAIL, 6 issues; diff check PASS | Import no usado en `Documentation.php`; estilo en billing test, CartJourney, cupones, `tests/TestCase.php` y hash test. No se aplicó Pint automáticamente. |

Conteo de ejecuciones A–G: 973, 951 PASS, 22 FAIL, 0 SKIP; 7,548 assertions PHPUnit (incluye repeticiones; JavaScript no reporta assertions PHPUnit). E repitió 50 tests administrativos que ya pasaron en B; su único fallo (`CouponAuthorizationInboxTest`) es setup/migración de la corrida combinada y ese mismo caso pasó en el grupo B aislado. Conteo deduplicado por tests: 923 únicos, 902 PASS, 21 FAIL, 0 omitidos. Los 21 fallos únicos son 1 de A y 20 de D. El intento SQLite pre-assertions y el intento OOM no se cuentan como casos funcionales adicionales. Históricos 302/5,118 y otras verificaciones previas no se suman a esta cobertura actual.

Comandos: todos los grupos PHP se invocaron con el prefijo `bash scripts/run-isolated-pest.sh` y paths siguientes. A incluyó `CartJourneyCheckoutFlowTest`, suites `Carts/*` (abandonment, resume, ActiveCampaign signals/outbox/site events, backfill), `EmptyLaboratoryCartRedirectTest`, checkout appointment-first flow/Phase4/Phase5/eligibility y `LaboratoryCheckoutResumeLinkTest`. B corrió `tests/Feature/Coupons`, luego `CouponAssignCreditTypesTest`, `CouponAssignOtpTest`, `CouponAuthorizationInboxTest`, y después `PromoCodeAdminTest`, `PromoCodeCheckoutTest`, `CouponServicesSmokeTest`, en invocaciones separadas. C incluyó `CartPaymentMethodSelectedTest`, `AdminCartPaymentAttemptCorrelatorTest`, las dos rutas `PayPal/*RouteTest`, comisión Efevoo y los dos tests unitarios GDA sanitization/payload ID. D incluyó los 11 paths exactos bajo `Unit/LaboratoryNotificationPdfRefreshTest`, `Unit/ResolveLaboratoryPurchasePdfPathHashTest`, `Feature/Mail/MailSafetyPolicyTest`, `Feature/Laboratory/GdaResultVersioningPhase1Test`, `GdaResultRefreshPhase2Test`, `GdaResultCompletionGatePhase3Test`, `GdaResultsPdfFreshnessIsolatedTest`, `GdaResultsNotAvailableTest`, `GdaResultsPurchaseViewPhase5Test`, `GdaResultsQaReviewIsolatedTest` y `Unit/GDA/GdaWebhookPayloadResolverTest`. E incluyó `LaboratoryPreparationMonitorTest`, `LaboratoryResultsCenterTest`, `LaboratoryBillingModuleIsolatedTest`, `CouponAuthorizationInboxTest`, `CouponAssignCreditTypesTest`, `CouponAssignOtpTest`, `PromoCodeAdminTest`. F usó los dos comandos descritos en la tabla, primero `tests/Unit/LaboratoryPreparation`, después las siete suites `Feature/LaboratoryPreparation*Test.php`. G: `docker compose exec -T node node --test tests/JavaScript/laboratoryOrderShare.test.mjs tests/JavaScript/laboratoryPreparationExperience.test.mjs tests/JavaScript/laboratoryStructuredPatientResults.test.mjs tests/JavaScript/adminLaboratoryStoresMap.test.mjs`.

## Clasificación y release

| Test/fallo | Causa actual | Severidad / estado |
|---|---|---|
| A: test SQLite | Runner SQLite no crea el archivo destino; el grupo MySQL no cumple la precondición | P2 infraestructura; test obligatorio sin validar |
| D: `GdaResultsNotAvailableTest` (8) | Fakes HTTP incompletos; `Http::preventStrayRequests()` evita toda salida real | P1 de testing/integración; sin llamada externa |
| D: orden vacía (1) | Estado producido no coincide con contrato esperado; causa funcional no decidida | P1 pendiente de análisis |
| D: QA Review admin (11) | 403 de middleware/Policy con fixtures; falta demostrar si fixture carece del rol correcto o contrato difiere | P1; no ampliar permisos |
| E: AuthorizationInbox (1) | Setup/migración duplica tabla en schema compartido | P2 aislamiento de test |
| C: doble cobro/GDA uncertain | No hay tests localizados para estos flujos críticos | Riesgo funcional no cubierto, P1 antes de release general |
| Pint (6) | Convenciones de estilo incumplidas en archivos cambiados | P2 calidad, sin cambio automático |

No se ejecutó main en un worktree separado; estos fallos se clasifican como resultados actuales y no como preexistentes. Las evidencias manuales de portal/correo/PDF/rollback reportadas por QA son del 2026-10-13 y no se revalidaron visualmente. PDF visible/hash y rollback sí tienen tests actuales en D/F.

**Veredicto: NO-GO para staging.** Bloquean 22 fallos actuales, ausencia de ejecución válida SQLite, setup duplicado en admin, 6 fallos de Pint por resolver y brecha de pruebas dedicadas de doble cobro/GDA uncertain. Indicaciones IA v3 pasa su bloque actual de 296 casos, pero no compensa la regresión pendiente.

Además, cambios locales existentes en R07/R18 son incompatibles con la restricción de release de no cambiar R01–R18 sin revisión explícita; no se alteraron en esta tarea. La autorización de Leo debe especificar si esos cambios clínicos pertenecen al release y aportar aprobación clínica, o excluirlos por el mecanismo de integración apropiado fuera de esta auditoría.

Siguiente lote mínimo, previa autorización de Leo para cambios de testing: crear el archivo SQLite descartable y validar CartJourney; completar fakes GDA y aislar setup de CouponAuthorizationInbox; aclarar permisos/fixtures QA Review sin relajar policies y decidir el estado de orden vacía; cubrir doble cobro/GDA uncertain o aceptar formalmente la brecha; corregir Pint y repetir solo grupos afectados. La excepción de staging requeriría aceptación explícita de esos riesgos; la recomendación técnica sigue siendo NO-GO.

---

# Corrección puntual del resolver de reanudación

Fecha local: 2026-10-14. Rama `description-ia`, SHA `ed82e41a159a97bd8f3c37661ff2c1cbbe1790ed` (sin commit). El working tree sigue incluyendo modificaciones previas ajenas a este ajuste.

## Cambio realizado

En `app/Actions/Laboratories/ResolveLaboratoryCheckoutResumeLinkAction.php`, la compra destino ahora se obtiene con `$cart->explicitLaboratoryPurchase()`. Se verificó que el método solo consulta la relación `laboratoryPurchases()` ligada por `cart_id`, y no aplica fallback por cliente. `Cart::relatedLaboratoryPurchase()` permanece intacto para administración, trazabilidad y consumidores legacy.

El orden funcional es ahora: compra explícita -> detalle de esa compra; si no hay compra explícita y el carrito está completado -> listado de compras; si está pendiente -> validación existente de items/draft y checkout con paso guardado. Las verificaciones de token hash, revocación, expiración, autenticación, propietario y pertenencia del carrito no cambiaron.

Se agregó una traza `Laboratory checkout resume link resolved` con ID de enlace, carrito, cliente, tipo de destino e ID de compra cuando corresponde. No registra URL ni token. Se mantiene `last_used_at` en las ramas exitosas existentes.

## Pruebas y evidencia

| Suite / corrida | Resultado | Base descartable | Log / JUnit |
|---|---:|---|---|
| `tests/Feature/LaboratoryCheckoutResumeLinkTest.php` tras el cambio | 17 passed, 58 assertions, 80.74 s | `famedic_test_description_ia_20261014103153_827616_20499` | `codex/pest-famedic_test_description_ia_20261014103153_827616_20499.log`; `storage/logs/junit-famedic_test_description_ia_20261014103153_827616_20499.xml` |
| `tests/Feature/Laboratory/LaboratoryCheckoutAppointmentFirstPhase4Test.php` | 15 passed, 35 assertions, 76.75 s | `famedic_test_description_ia_20261014103320_828038_7919` | `codex/pest-famedic_test_description_ia_20261014103320_828038_7919.log`; `storage/logs/junit-famedic_test_description_ia_20261014103320_828038_7919.xml` |
| Resume link, repetición final con assert de logging sin token | 17 passed, 59 assertions, 80.33 s | `famedic_test_description_ia_20261014103452_828443_20921` | `codex/pest-famedic_test_description_ia_20261014103452_828443_20921.log`; `storage/logs/junit-famedic_test_description_ia_20261014103452_828443_20921.xml` |

Las tres corridas usaron `bash scripts/run-isolated-pest.sh` en serie, cada una con schema único. Consulta posterior a `information_schema.schemata`: cero para los tres nombres. El runner fijó MySQL descartable, desactivó ActiveCampaign/OpenAI, usó mail array y bloqueó HTTP saliente no fakeado. No hubo servicios externos ni `famedic`/`famedic_test`. `php -l` de los dos archivos editados y `git diff --check` pasaron.

La suite de resume valida carrito pendiente sin historial y con compra histórica no relacionada, destino de pasos `payment` y `confirmation`, compra explícita, carrito completado sin asociación, token aleatorio/manipulado/vencido/revocado y de otro usuario. La reproducción antes del cambio fallaba con redirect a `/laboratory-purchases/1`; después redirige al checkout esperado. El GET conserva sin cambios los conteos de compras, transacciones y citas. La traza de resolución se comprueba y no contiene el token.

## Resultado y riesgo

- Comportamiento anterior: fallback por cliente podía enviar un carrito pendiente al detalle de una compra histórica del mismo cliente.
- Comportamiento actual: el resolver solo dirige a una compra con vínculo `cart_id`; sin ese vínculo usa estado del carrito y draft actual. Los otros consumidores del fallback global no cambian.
- Riesgos pendientes: ninguno detectado en las suites directamente afectadas. La asociación legacy que `relatedLaboratoryPurchase()` produce para vistas administrativas/auditoría sigue existiendo deliberadamente; su evaluación global queda fuera del alcance.
- Sin cambios a pagos, citas, rutas o contratos de enlaces opacos; sin cambios clínicos/OpenAI. No hubo commit, push, deploy ni activación de flags.

**Veredicto actualizado: GO para este bloqueante de reanudación**, sustentado por la clase completa y Phase 4 verdes en bases independientes. Esto cierra únicamente este hallazgo; no convierte por sí solo la regresión A-E amplia, que no se repitió en esta ronda, en aprobación global de staging.

---

# Cierre focal P1 de fallos GDA/resultados — 2026-10-14

Rama `description-ia`, HEAD `ed82e41a159a97bd8f3c37661ff2c1cbbe1790ed`; sin commit. Estado Git continúa modificado, con cambios previos y no relacionados además de los cambios de testing descritos aquí. Las pruebas reportadas abajo usaron el working tree actual. No se ejecutó una regresión general.

## Causas confirmadas y cambios mínimos

- `GdaResultsNotAvailableTest`: el fake HTTP esperaba una ruta relativa mientras el cliente construía la URL con `services.gda.results_consult_url` vacío en testing. Se fijó un host `.test` solo en el setup de test y se ampliaron escenarios de HTTP 200 sin PDF, 503, timeout y ausencia de notificación/reemplazo prematuros. Cliente productivo y contrato GDA no cambiaron.
- `GdaResultsQaReviewIsolatedTest`: los 403/setup failures provenían de fixtures incompletos: config de Spatie teams, permisos consultados por middleware y schemas de `administrators`/`laboratory_concierges` ausentes o sin soft deletes. Se completaron schema y catálogo de permisos en la suite; no se otorgaron permisos de negocio adicionales ni se cambió middleware.
- `GdaResultsPurchaseViewPhase5Test`: una orden con estudios pero sin status ni resultados deriva a `awaiting_results`; presenter expone `pending_interpretation` y conserva `is_complete=false`/`can_view_results=false`. Según decisión funcional del usuario, se actualizó la expectativa únicamente. El fixture administrativo también tenía `role_id` duplicado, carecía de soft deletes/tablas de concierge y del catálogo consultado por middleware; esos defectos de setup se corrigieron sin tocar presenter ni autorización productiva.
- Runner SQLite: creación del archivo temporal dentro del contenedor `app`, validación estricta del nombre/ruta, rechazo de colisión y cleanup limitado al archivo que esa ejecución creó. La prueba `CartJourneyDatabaseConfigurationTest` pasó. La búsqueda posterior encontró cero `famedic_test_*.sqlite` remanentes en el directorio temporal.
- `CouponAuthorizationInboxTest` al compartir schema después de billing: se añadió `laboratory_notifications` a la lista de teardown del fixture aislado de billing. La corrida secuencial combinada de billing y bandeja de cupones pasó.

## Evidencia actual focal

| Suite | Resultado | Schema/log/JUnit |
|---|---:|---|
| `GdaResultsNotAvailableTest` | 12 PASS, 23 assertions | Runner MySQL aislado; log y `storage/logs/junit-<schema>.xml` generados por la corrida focal. |
| `GdaResultsQaReviewIsolatedTest` | 19 PASS, 95 assertions | Runner MySQL aislado; log y JUnit por schema único. |
| `GdaResultsPurchaseViewPhase5Test` | 5 PASS, 32 assertions | Schema `famedic_test_description_ia_20261014122607_925022_25048`; `codex/pest-famedic_test_description_ia_20261014122607_925022_25048.log`; `storage/logs/junit-famedic_test_description_ia_20261014122607_925022_25048.xml`. |
| Checkout/promo/webhook/PayPal equivalente | 42 PASS, 137 assertions, 93.34 s | Schema `famedic_test_description_ia_20261014122635_925401_2841`; `codex/pest-famedic_test_description_ia_20261014122635_925401_2841.log`; `storage/logs/junit-famedic_test_description_ia_20261014122635_925401_2841.xml`. Incluye confirmación promo idempotente, webhooks de gabinete, resolución de consecutivo/etiquetas y rutas PayPal autenticadas. |
| `LaboratoryBillingModuleIsolatedTest` + `CouponAuthorizationInboxTest` | 56 PASS, 496 assertions | Corrida combinada secuencial ya registrada en evidencia focal anterior; ambas suites compartieron solo el schema descartable de esa corrida. |
| `CartJourneyDatabaseConfigurationTest` | 1 PASS, 3 assertions | SQLite descartable; log `codex/pest-sqlite-20261014122022_922841_19770.log`, JUnit `storage/logs/junit-sqlite-20261014122022_922841_19770.xml`; archivo temporal eliminado. |

Las primeras corridas de diagnóstico de Phase 5 fallaron antes de assertions por el schema incompleto; después de reparar exclusivamente el fixture, la corrida final completa pasó. No se contabilizan esos intentos como fallos funcionales. No se ejecutaron tests en paralelo. El runner MySQL crea un schema único por invocación y lo elimina al salir; bloquea overrides a BD fija. Variables mantienen ActiveCampaign apagado, OpenAI sin key, mail array, queue sync; `tests/TestCase.php` bloquea HTTP no simulado. No hubo llamada a proveedor real, acceso deliberado a `famedic` o `famedic_test`, ni modificación de compras.

Pint `--test` sobre los nueve PHP focales pasó; `git diff --check` y `bash -n` para ambos runners pasaron.

## Cobertura de pagos e incertidumbre GDA

La búsqueda semántica localizó cobertura relacionada, no equivalente a protección contra doble cobro: idempotencia de confirmación de promo/pedido, idempotencia de eventos de método de pago, protección de duplicados en webhooks y correlación de intentos. Las suites existentes cercanas pasaron en el bundle de 42 tests. No se localizó prueba que afirme una sola captura/cargo tras reintento de PayPal ni recuperación de una compra con `gda_status=uncertain`/sin folio; tampoco se encontró ese estado implementado explícitamente por nombre. No afirmar que el sistema carezca de protección: queda como brecha de evidencia. Recomendación: diseñar pruebas focalizadas con gateways y GDA fakeados antes de staging o conseguir aceptación formal de riesgo. No se añadió lógica ni cobertura de pagos en este alcance.

## Clasificación P1 y decisión

Los ocho casos de GDA, once de QA review y el caso de orden vacía que aparecían en la regresión anterior tienen ahora pruebas focales verdes; sus causas confirmadas fueron fake de endpoint, fixtures de autenticación/schema y expectativa del presenter respectivamente, no cambios productivos de Indicaciones IA. El caso de orden vacía conserva los tres asserts de seguridad de resultados. El fallo SQLite se resuelve al usar el runner SQLite y el ajuste de ruta/cleanup; la interferencia de schema entre billing y cupones se resolvió en setup/teardown de tests.

**Veredicto: NO-GO para staging general.** Esta ronda cierra los P1 focalizados, pero no repitió la regresión completa de 923 tests. Siguen pendientes la evidencia directa de idempotencia de captura y recuperación GDA incierta, y la aprobación clínica independiente de los cambios locales R07/R18 que ya constaban en el working tree. Próximo lote mínimo: tests mock de reintento/captura e incertidumbre, y luego solo las suites A–E que consumen esos contratos; no iniciar corrida masiva antes de esa evidencia.
