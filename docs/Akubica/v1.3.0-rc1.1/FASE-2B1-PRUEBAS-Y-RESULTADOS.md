# Fase 2B 1 Pruebas locales y resultados de autorización

Resultado de aceptación: **NO PASS**. P07 y P08 fallan; los otros diez escenarios están BLOCKED por falta de identidad/emisión/canal aprobados. Registrar doce IDs en un test parametrizado no equivale a implementar o ejecutar doce pruebas de una política inexistente.

## Automatización entregada y límites

`tests/Acceptance/Api/V1/LeoAuthorizationP01P12Test.php` conserva los doce IDs y criterios. P07 y P08 ejecutan HTTP real por las rutas Laravel, Sanctum, FormRequest, idempotencia y action existentes. P01–P06/P09–P12 tienen marca SKIPPED con razón BLOCKED_IDENTITY_MODEL_NOT_APPROVED; su preparación y aserciones integradas siguen pendientes. No se fabricó una identidad Leo mediante header, usuario, ability o mock del resolver. No afirmar que esos diez negativos o el positivo native se probaron.

`LeoAuthorizationCurrentBehaviorTest.php` añade cuatro controles positivos/de caracterización: abilities vacías, read-only y wildcard permiten un link en el comportamiento existente; replay devuelve body persistido sin duplicar link y una clave nueva genera otro. Esos son clientes Bearer sintéticos sin clasificación: no sustituyen P12 ni acreditan una identidad native autorizada por el modelo futuro.

`Phase2B1EphemeralTestCase.php` usa el kernel y rutas reales y finge solo transporte HTTP externo. No sustituye auth, middleware de replay, controlador, readiness o creación del link. Compara hash de todas las tablas de negocio y conteos de links/transacciones/compras antes y después. Excluye únicamente migrations, personal_access_tokens (last_used_at), api_v1_idempotency_records y otp_movement_events técnico de la comparación de negocio. Observaciones persistidas no contienen Bearer, OTP, cuerpos, URLs, referencias de link ni PII.

Las llamadas HTTP de proveedor se cuentan en la frontera Http fake; el transporte de red PHP además está deshabilitado. La acción inspeccionada no llama proveedor de pagos. Esto no acredita cobertura de SDK/rutas de cobro ajenos al camino probado ni prueba un negativo Leo que no se ejecutó.

## Resultado P01 a P12

| ID | Estado | Resultado verificable |
| --- | --- | --- |
| P01 | BLOCKED | No hay una identidad Leo emitida/vinculada/canal verificados con aprobación. |
| P02 | BLOCKED | Variantes de brand/service_line bajo identidad Leo aún no ejecutadas. |
| P03 | BLOCKED | Denegación Leo con wildcard/grant accidental no ejecutada. Caracterización wildcard actual: 200. |
| P04 | BLOCKED | No existe identidad autenticada Leo contra la cual intentar los headers falsos. Headers solos no se tratan como identidad. |
| P05 | BLOCKED | No hay gate que deniegue a Leo antes de replay. C02 confirma el camino real de respuesta persistida. |
| P06 | BLOCKED | No hay binding native/PAT versus canal Leo aprobado. |
| P07 | **FAIL** | Se exige 403 CONSUMER_NOT_AUTHORIZED; observado 200 sin código de error y **un link creado** con PAT read-only no clasificado. |
| P08 | **FAIL** | Ausente/inválido/revocado/expirado: 401 UNAUTHENTICATED, cero efectos. Sesión tras login web real en el harness: **500 INTERNAL_ERROR**, no 401. No aceptar ese error como control correcto. |
| P09 | BLOCKED | D08 pendiente: no se aplicó ni probó un bloqueo Leo real de seis rutas legacy, aliases o acción directa. |
| P10 | BLOCKED | No hay consumidor/delegación registrados que revocar y verificar antes de replay. |
| P11 | BLOCKED | No se inventó una identidad Leo por tener rol admin. |
| P12 | BLOCKED | Positivo native registrado con grant/canal concordantes y negativo sin grant pendientes. Compatibilidad Bearer existente se verificó por separado. |

La matriz JSON `FASE-2B1-P01-P12.json` conserva las filas exactas de Fase 2A, el estado de ejecución y las observaciones sin datos sensibles. La aceptación contiene 12 casos: **0 PASS, 2 FAIL, 10 BLOCKED**. Los cuatro positivos de caracterización se cuentan aparte.

## Evidencia de efectos

P07 creó un link solo en SQLite :memory:, sin nueva transacción/compra ni llamada HTTP de proveedor. Falla el requisito de cero efectos porque cambia akubica_checkout_links. No se declara «denegado sin efectos».

P08 ausente/inválido/revocado/expirado produjo exactamente 401/UNAUTHENTICATED y conservó todas las tablas de negocio, conteos de links/transacciones/compras y contador de llamadas HTTP. La variante sesión produjo 500 y también dejó el negocio intacto, pero su criterio de autorización falló. Los escenarios BLOCKED no tienen evidencia de cero efectos: no se ejecutaron.

El router resuelto pone Authenticate:sanctum antes de UseApiTokenGuard. En la variante con sesión obtenida por POST /login, CheckoutController:168 intenta leer id sobre TransientToken. Se conserva la reproducción local y no se extrapola a explotación de un despliegue. Corregir orden y requerir PAT real es un ajuste propuesto, sin modificación funcional no aprobada.

## Regresiones existentes finales

| Suite | Resultado |
| --- | --- |
| AkubicaAuthTest | 17 PASS |
| AkubicaLoginOtpP0a4Test | 10 PASS, 11 FAIL, 3 ERROR |
| AkubicaSanctumTokenExpirationP0c1Test | 10 PASS |
| AkubicaIdempotencyP1Test | 30 PASS, 2 FAIL |
| AkubicaCartCouponTest | 28 PASS |
| AkubicaCartCatalogTest | 17 PASS |
| AkubicaCheckoutPreparationTest | 28 PASS |
| AkubicaCheckoutPaymentLinkTest | 24 PASS |
| AkubicaMedicalServicesCartM2BTest | 10 PASS |
| AkubicaMedicalServicesCartM2CTest | 7 PASS |
| AkubicaMedicalCheckoutPrepareRc1IsolatedTest | 6 PASS |
| AkubicaErrorsCorrelationP1a6Test | 13 PASS |
| Total Laravel existente | **200 PASS, 13 FAIL, 3 ERROR, 216 casos** |
| Regresión Python Fases 0/1/1B/1C/1C.2 | **80 PASS** |
| Node | **4 PASS** |
| Validador local de contrato | PASS_LOCAL_SCOPE; 30 ejemplos y 20 fixtures válidos |
| Sintaxis de los tres PHP nuevos y AST del runner Python | PASS |
| Integridad contractual y git diff --check | PASS |

Los 24 tests de payment links existentes pasan, incluidos sus positivos Bearer; eso confirma funcionamiento previo en este harness, no el nuevo gate. Los 16 fallos/errores de las suites existentes impiden afirmar regresión completa PASS. No se alteraron código funcional ni expectativas de esas suites para eliminarlos.

Investigación: loginOtpUser crea un User sin customer en AkubicaLoginOtpP0a4Test:38, mientras AkubicaLoginOtpService:126 devuelve decoy/customer_missing sin perfil; varias expectativas exigen desafío real/delivery/verify. Dos tests de idempotencia OTP también crean User sin customer (:526 y caso de actores). Se observaron desafíos ausentes y expectativas de cooldown/delivery/diagnóstico incompatibles. Una consulta `meta.decoy_reason` de la suite falla en SQLite; no se cambia SQL ni se afirma su compatibilidad con otro motor. Son hallazgos sobre el código/pruebas locales existentes, sin afirmar que todos tengan una sola causa ni que sean fallos introducidos por esta fase.

La primera ejecución agrupada tuvo seis ERROR adicionales de «no such table users» en una suite aislada que presupone esquema preparado. Se corrigió solo el harness: ejecución separada con esquema existente provisionado en SQLite memoria; los seis pasan. Los recibos inicial/final se conservan, sin sobrescribir. Las categorías/test IDs de fallos restantes están en FASE-2B1-REGRESIONES-ANALISIS.json.

## Procedimiento reproducible y aislamiento

Desde WSL, sin instalaciones ni paquetes nuevos:

```bash
cd /home/developer/projects/famedic
python3 -B scripts/run-phase2b1-local.py --report docs/Akubica/v1.3.0-rc1.1/FASE-2B1-EJECUCION-OTRA.json
```

El runner rechaza sobrescribir evidencia y verifica el hash del contrato antes de empezar. Crea un directorio temporal nuevo fuera del repositorio con .env sintético vacío, APP_KEY de prueba, caches/logs/storage aislados y entorno de hijo limpio. Usa dependencias vendor instaladas, no lee .env real ni ejecuta DB MySQL. Las tablas provienen de migraciones existentes copiadas al entorno temporal y se aplican únicamente en SQLite :memory:; ninguna base persistente fue migrada y no hay un esquema nuevo de identidad aplicado.

La copia de test excluye expresamente `2026_10_21_142000_relax_atix_operation_unique_indexes.php`, que consulta information_schema de MySQL. El original no cambió. No se afirma compatibilidad de esa reparación ATIX con SQLite ni cobertura integral de migraciones/despliegue/concurrencia MySQL. No es un parche al contrato o al runtime para forzar PASS.

El runner devuelve estado BLOCKED y código 3 por identidad no aprobada, aun cuando una suite auxiliar pase; el wrapper WSL puede trasladar el código de salida. No convertir sus skips o sus excepciones en PASS. Los logs/JUnit íntegros de datos sintéticos quedan fuera del repositorio, con ruta y SHA en el recibo; la evidencia distribuible guarda nombres/estados y observaciones mínimas.
