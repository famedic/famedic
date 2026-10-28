# Indicaciones IA v3 — Integración selectiva del bloque A

Fecha: 2026-10-16. Proyecto: `/home/usuario/projects/famedic`.
Rama: `feature/indicaciones-ia-v3-mejoras`.
HEAD preservado: `67dbf35d1c63d187e355c5334c8b91c5565b478a`.
Referencia consultada: `10e439d84cd4e691d24073b1df0490cd8d572aa7`.

## Resultado

Bloque A implementado en el proyecto original, usando los contenedores existentes.
No se incorporó el coordinador de correo único, sus hooks, configuración, modelos ni migraciones.
No se realizaron commit, push, deploy, cambios de rama, modificaciones de `.env`, Forge o staging.
La base habitual no se usó para pruebas destructivas. Las bases de prueba se crearon y descartaron mediante los runners existentes.

## Cambios integrados

- Monitor: cinco KPI principales (total, AUTO, FALLBACK, revisión, fallos técnicos), métricas secundarias desplegables, filtros validados, filtros avanzados, tabla de cinco columnas y tarjetas móviles.
- Consultas: misma ejecución relevante para filas, filtros, detalle y métricas; se evita arrastrar fallos históricos. Agregación SQL sin listas de IDs en PHP. Las consultas permanecen constantes para una y 25 compras, con un máximo de cinco en la prueba.
- Detalle: presentación actual del paciente, originales, reglas, historial acotado a 20 ejecuciones con aviso de más registros, navegación conservando filtros y acceso a compras eliminadas para Administrador. No se borró historial.
- P2: códigos seguros, etapa, categoría controlada y UUID de correlación. No se encadena la excepción original. Los fallos legacy descartan payloads rechazados, conservando intactos los validadores y el resultado funcional de generación/fallback.
- P2 adicional: el correo de resumen legacy es un job independiente. Se añadió middleware específico a las nuevas notificaciones encoladas para sanear fallos posteriores del transporte. Conserva destinatarios, contenido, canales, resultado satisfactorio y política de reintentos. No implementa coordinación ni supresión de correos.

## Diferencias originales preservadas

- `action_url` en la notificación interna y en el esquema de sus pruebas.
- Rutas del centro de resultados. El único cambio en `routes/admin.php` es `withTrashed()` en el detalle del monitor.
- Relación `LaboratoryPurchase::resultReports()` y modelo de compra completo.
- `tests/TestCase.php`, `tests/Pest.php` y ambos runners aislados existentes.
- Reglas R01–R18, parser, fuentes, presenter, validadores, prompts y migraciones de prompts.
- Código de fulfillment, pagos, GDA, ActiveCampaign, rutas de consola, configuración y migraciones productivas.

## Archivos modificados o añadidos

- `app/Http/Controllers/Admin/LaboratoryPreparationMonitorController.php`
- `app/Jobs/GenerateLaboratoryPurchasePreparationSummaryJob.php`
- `app/Notifications/LaboratoryPreparationSummaryAvailable.php`
- `app/Services/LaboratoryPreparation/Admin/LaboratoryPreparationMonitorService.php`
- `app/Services/LaboratoryPreparation/LaboratoryPreparationOpenAiRedactor.php`
- `app/Services/LaboratoryPreparation/LaboratoryPreparationOperationalException.php`
- `app/Services/LaboratoryPreparation/LaboratoryPreparationSafeNotificationMiddleware.php`
- `app/Services/LaboratoryPreparation/LaboratoryPreparationSummaryNotificationService.php`
- `app/Services/LaboratoryPreparation/LaboratoryPreparationSummaryService.php`
- `resources/js/Pages/Admin/LaboratoryPreparation/Monitor.jsx`
- `resources/js/Pages/Admin/LaboratoryPreparation/MonitorComponents.jsx`
- `resources/js/Pages/Admin/LaboratoryPreparation/MonitorDetail.jsx`
- `resources/js/Pages/Admin/LaboratoryPreparation/monitorPresentation.js`
- `routes/admin.php`
- `tests/Feature/Admin/LaboratoryPreparationMonitorTest.php`
- `tests/Feature/LaboratoryPreparationOpenAiRedactorTest.php`
- `tests/Feature/LaboratoryPreparationOperationalSafetyTest.php`
- `tests/Feature/LaboratoryPreparationResponseValidationTest.php`
- `tests/Feature/LaboratoryPreparationSummaryServiceTest.php`
- `tests/JavaScript/laboratoryPreparationMonitor.browser.mjs`
- `tests/JavaScript/laboratoryPreparationMonitor.test.mjs`
- `codex/indicaciones-v3-bloque-a-integracion.md` (este informe).

## Validación final

| Grupo | Resultado |
| --- | --- |
| Monitor en MySQL descartable | 14 PASS, 319 aserciones |
| Regresión de preparación PHP en MySQL descartable | 298 PASS, 12 FAIL preexistentes, 4,978 aserciones |
| Total PHP de esas dos ejecuciones | 312 PASS, 12 FAIL preexistentes; 5,297 aserciones ejecutadas |
| Seguridad operacional P2, incluida en la regresión | 6 PASS, 90 aserciones |
| JavaScript del proyecto | 105 PASS, 0 FAIL, 1 SKIP; incluye 5 pruebas nuevas del monitor |
| Navegador específico del monitor | 12 PASS: listado/detalle a 320, 375, 768, 1024, 1280 y 1440 px |
| Build | `npm run build` PASS: cliente y SSR, exit 0 |
| Sintaxis PHP | 15 archivos comprobados, sin errores |
| Git | `git diff --check` PASS; índice y HEAD sin cambios |

La primera ejecución amplia con SQLite se detuvo: una migración preexistente del centro de resultados consulta `information_schema.TABLE_CONSTRAINTS`. Se mantuvo esa migración intacta y se ejecutó la regresión completa sobre MySQL descartable. El monitor también pasó inicialmente sus 14 pruebas en SQLite.

La prueba de navegador usa los componentes reales del monitor, detalle, Catalyst y SidebarLayout con datos sintéticos extensos. Simula navegación y chrome administrativo ajeno al módulo; bloquea peticiones de red. Verifica ausencia de overflow y enlaces de retorno/detalle con filtros. No acredita una sesión manual del monitor en la base habitual.
El contenedor node carece de `libnss3.so`; no se modificó. Se preparó la fixture con ese contenedor y se ejecutó con Chrome ya instalado en el host. La omisión de JavaScript corresponde a una prueba preexistente del modal/widget, ajena al monitor, por esa misma dependencia.

### P2: prueba negativa

Marcador: `AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML`.

- Fallos antes de generación, durante generación, en fallback secundario y en transporte de la notificación legacy.
- Worker real de Laravel con cola database dentro del proceso de prueba, tres intentos por escenario y almacenamiento real en `failed_jobs` aislado. No se lanzaron workers de producción ni se consumieron colas habituales. Se adelantó `available_at` exclusivamente en la base descartable para evitar esperas de backoff.
- Ausente del stdout capturado, del archivo de log de cada caso, de excepción/payload de `failed_jobs`, de los errores/payloads persistidos del redactor y servicio legacy, y del JSON administrativo del historial.
- La excepción mantiene correlación, etapa/categoría seguras y carece de `previous` con el error original.
- Inspección final de los cinco archivos `storage/logs/*.log` disponibles: cero archivos con el marcador. No se imprimieron sus contenidos.
- El test de generación usa evidencia confirmada sintética en el modelo recuperado porque la base original no tiene `gda_status`; no altera el esquema productivo ni relaja el servicio de elegibilidad. No sustituye una prueba real de integración GDA.

### Evidencia local (ignorada por Git)

- `storage/logs/block-a-monitor-final.xml`
- `storage/logs/block-a-final.xml`
- `storage/logs/block-a-baseline.xml`
- `storage/logs/block-a-monitor-mobile.png`

La fixture exportada y el bootstrap temporal de comparación contra HEAD fueron eliminados. Los logs de cada escenario P2 y las bases descartables se limpiaron al terminar.

## Fallos preexistentes y riesgos pendientes

1. **P1 / bloqueante para B:** doce pruebas de `LaboratoryPreparationGenerationDispatchTest.php` fallan por la falta de evidencia GDA confirmada que exige la elegibilidad. Se reprodujeron exactamente los mismos doce nombres de fallo al cargar las cuatro clases originales de HEAD en un proceso aislado, sin reemplazar archivos del árbol de trabajo: 9 PASS / 12 FAIL. La regresión final mantiene ese mismo conjunto y ningún fallo nuevo. No se modificaron ni omitieron estas pruebas.
   La rama carece del enum `GdaOrderStatus` y de la migración real de `gda_status`; el fixture de fulfillment no productivo devuelve un folio simulado sin consecutivo. Antes de B se necesita una propuesta separada que reconcilie evidencia, persistencia y protecciones financieras/GDA, sin flexibilizar validadores ni elegibilidad. No se implementó esa corrección.
2. **P2 / operación:** jobs de notificación serializados antes de esta integración no incorporan retroactivamente el middleware nuevo. Antes de un despliegue debe revisarse su existencia y acordar drenaje o reserialización específica, sin reintentos masivos ni duplicación de correos. No se inspeccionaron ni modificaron colas habituales.
3. **P2 / alcance:** errores/logs históricos persistidos permanecen intactos; el monitor oculta sus mensajes libres. No se realizó una limpieza de datos históricos ni una sanitización global de otros módulos.
4. **QA manual pendiente:** sesión autenticada en el Docker habitual y zoom real al 200 %. La regresión responsiva automatizada y la autorización HTTP aislada sí se comprobaron.
5. La suite focal no está completamente verde debido a los doce fallos originales; no se presenta como certificación de pagos/GDA ni del correo único.

## Reproducción segura

Desde `/home/usuario/projects/famedic`, usar exclusivamente los runners existentes:

```bash
LOG_PATH=/dev/null JUNIT_PATH=storage/logs/block-a-monitor-final.xml bash scripts/run-isolated-pest.sh tests/Feature/Admin/LaboratoryPreparationMonitorTest.php
LOG_PATH=/dev/null JUNIT_PATH=storage/logs/block-a-final.xml bash scripts/run-isolated-pest.sh tests/Feature/LaboratoryPreparation*Test.php tests/Unit/LaboratoryPreparation
docker compose exec -T node npm test
docker compose exec -T node npm run build
```

No ejecutar Pest directamente contra la base habitual. El runner genera su propio nombre de base y la elimina al finalizar. El test de navegador separado está en `tests/JavaScript/laboratoryPreparationMonitor.browser.mjs`; admite exportación `--prepare-only` y las variables de proceso `MONITOR_BROWSER_FIXTURE`, `MONITOR_BROWSER_EXECUTABLE` y, opcionalmente, `MONITOR_SCREENSHOT`. No son cambios de `.env`.

## Dictamen

**Bloque A integrado y validado en su alcance, con las limitaciones anteriores. NO-GO para implementar/activar el bloque B hasta resolver y volver a validar los bloqueantes GDA de la base original mediante autorización separada.**

El correo único permanece pendiente. El envío adicional legacy sigue funcionando como antes; este bloque no pretende suprimirlo.
