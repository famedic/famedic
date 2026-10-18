# Indicaciones IA v3 — Integración selectiva GDA/P0

Fecha: 2026-10-16. Proyecto: `/home/usuario/projects/famedic`.
Rama conservada: `feature/indicaciones-ia-v3-mejoras`.
HEAD conservado: `67dbf35d1c63d187e355c5334c8b91c5565b478a`.
Referencias consultadas: `6acdbe02c7ec801795e61d91f7818ac5ecbb9591` y `release/indicaciones-ia-v3-staging` (`f3ea57f9346c63842a64721e859833037b78143c`). Integración selectiva; ningún merge/cherry-pick.

## Resultado y alcance

Contrato GDA/P0 implementado y validado con MySQL 8 descartable en los contenedores existentes. Los doce casos de despacho previamente fallidos pasan sin flexibilizar elegibilidad. No se incorporó el correo único, sus hooks, ledger, migraciones, workers ni cohortes. El comportamiento de correos legacy permanece pendiente de la futura integración B.

Se conservaron byte por byte los 22 archivos modificados/no rastreados del bloque A al inicio de esta implementación, verificados mediante SHA-256. No se modificaron reglas R01–R18, prompts, redactor/presenter clínico, validadores, elegibilidad, resultados IA, centro de resultados, rutas del monitor ni servicios/drivers de ActiveCampaign. `action_url`, `resultReports()`, TestCase y runners existentes permanecen intactos.

No se cambió de rama, no se crearon worktrees/entornos Docker, no se modificaron `.env`, Forge, staging ni la base habitual. No hubo cobros, correo ni llamadas a proveedores reales. Sin commit, push o deploy.

## Contrato de estados

| Estado | Evidencia y efectos |
| --- | --- |
| `pending` | Nueva compra con vínculo financiero y estudios persistidos antes de solicitar GDA. No acredita confirmación. Un reintento bajo bloqueo lo trata como operación interrumpida, sin solicitar GDA otra vez. |
| `confirmed` | Únicamente con folio válido y consecutivo entero positivo. Habilita el cierre del carrito y los efectos de compra completada existentes. |
| `failed` | Falla definitiva anterior al envío de GDA; conserva el camino de error/reembolso preexistente. No representa confirmación. |
| `uncertain` | Timeout, respuesta no verificable, caída posterior al envío o falla al persistir el resultado. Pago retenido, compra vinculada, carrito sin completar; no reembolso automático ni segundo cobro/solicitud GDA. Requiere conciliación. |
| `NULL` | Compras históricas: ninguna inferencia/backfill automático. La elegibilidad IA sigue exigiendo evidencia explícita. |

`GdaOrderConfirmation` valida identificadores sin alterar contenido clínico: folio escalar no vacío/no cero y consecutivo de dígitos positivo; rechaza valores incompletos, negativos, booleanos, arrays y fracciones. `gda_response` tiene cast a array y `gda_status` al enum. El default `pending` se aplica en el modelo a compras nuevas, no como actualización SQL histórica.

## Migración

Archivo: `database/migrations/2026_10_16_220000_add_gda_order_status_to_laboratory_purchases.php`.
Agrega `gda_status` nullable string(32) e índice `laboratory_purchases_gda_status_idx`; no actualiza filas existentes. Rollback elimina exclusivamente índice/columna de esta migración.

Validación MySQL: aplicación, rollback y reaplicación; datos históricos con/sin identificadores y eliminados lógicamente permanecen sin backfill; una compra nueva recibe `pending`. Si la columna ya existe, la migración falla explícitamente antes de modificar esquema/datos: se prueban valores importados `NULL`, `confirmed`, `completed` y desconocidos. No se reinterpretan silenciosamente estados externos ni se asume propiedad de una columna ajena.

La migración **no está aplicada a la base habitual**. El checkout modificado requiere esta columna antes de utilizarse con esa base. No ejecutar la aplicación como smoke test de compras ni habilitar el flujo habitual hasta autorizar y completar la preparación de esquema. Si el destino ya tiene la columna, revisar su origen, tipos, índice y valores antes de acordar una migración de reconciliación; no marcar esta migración como aplicada a ciegas.

## Protección financiera e incertidumbre

- La compra, sus estudios, cita y vínculo con el pago se confirman en una transacción local antes de llamar a GDA. Después de la respuesta se usa una segunda transacción para confirmar y completar el carrito. En producción se rechaza una transacción exterior que impediría esa durabilidad; las fixtures transaccionales quedan permitidas exclusivamente durante tests.
- El fallo anterior al envío conserva la política existente. Una respuesta HTTP de error, timeout o identificadores incompletos después de enviar se clasifica conservadoramente como incierta, sin asumir rechazo GDA.
- Una falla después de recibir GDA conserva identificadores conocidos cuando es posible. Si tampoco puede persistirse `uncertain`, la fila `pending` previamente confirmada sigue bloqueando la repetición.
- Los efectos posteriores al commit no pueden provocar reembolso de una orden ya confirmada. Un replay devuelve el registro existente y no repite GDA, conversiones, automatizaciones o notificaciones. Esto no añade entrega durable de correos: un proceso caído entre commit y notificación puede requerir recuperación administrativa; esa entrega corresponde al futuro bloque B.
- `GET_LOCK` de MySQL usa una clave por base y carrito/transacción, compartida por procesos, sin TTL de cache. Callback y webhook toman primero carrito y después transacción. La adquisición/liberación usa el PDO de escritura. La caída de un proceso libera el bloqueo de conexión y la evidencia durable impide repetir el efecto externo.
- Una compra con pago acreditado del mismo carrito bloquea otro cargo tanto si está `pending`, `confirmed` o `uncertain`. Se conservan las protecciones anteriores de montos, cupones, promociones y elegibilidad.
- Antes de una captura PayPal se persiste un marcador durable. En reintentos se consulta la orden y se recupera una captura completada; con marcador y sin resultado verificable no se vuelve a capturar. Se evita confundir un identificador de orden `APPROVED` con una captura completada.
- Una captura recibida cuyo registro SQL falla conserva la barrera y queda para revisión. Otro pago ya asociado al carrito no dispara una segunda orden GDA; una captura externa adicional se conserva como caso de conciliación.
- El reembolso PayPal definitivo exitoso persiste `refunded_at`. Un webhook de captura repetido no restaura `captured`, no repite fulfillment ni reembolsa otra vez. Un reembolso fallido/ambiguo requiere revisión manual, sin retry automático nuevo.
- Odessa conserva el reembolso administrativo existente. Para laboratorio persiste carrito/marcador `processing` antes del débito, y evidencia `completed` después del éxito. Timeout o falla al guardar respuesta impide otro débito, sin refund automático; un rechazo explícito retira el marcador y mantiene el error definitivo existente. La firma agrega un tercer argumento opcional y conserva los otros callers.
- Intentos Efevoo `pending/processing/approved/error` sin prueba de reembolso verificado bloquean otro pago del carrito aun sin compra creada. `declined` y reembolso verificado permiten continuar. Es una política conservadora: errores locales sin cobro también pueden exigir conciliación manual, en vez de asumir que es seguro cobrar.
- Diagnósticos nuevos de incertidumbre usan códigos controlados y correlación UUID, sin incorporar el cuerpo/resumen ni `previous` de la excepción del proveedor. Un error al escribir diagnóstico/liberar un lock no cambia el desenlace financiero.

## Webhook

`HandleStatusUpdateAction` conserva estados externos en sus registros de notificación/cotización y campos específicos existentes. Ya no copia `completed` u otros valores libres al enum interno de la compra. Se prueban eventos repetidos y desconocidos.

## Evidencia automatizada

Todas las ejecuciones MySQL usaron `scripts/run-isolated-pest.sh`, que crea una base con nombre único y la elimina al terminar. Los casos nuevos de migración y procesos se ejecutan por separado. Los proveedores están simulados, el correo usa transporte de pruebas y las automatizaciones externas están desactivadas/interceptadas.

| Ejecución | Resultado |
| --- | --- |
| Regresión amplia de Indicaciones IA, clínica, checkout, promociones, autorización PayPal y P0 | 376 PASS; 5,251 aserciones |
| Cierre focal P0 y `LaboratoryPreparationGenerationDispatchTest` después de las últimas correcciones | 42 PASS; 188 aserciones |
| Monitor administrativo | 14 PASS; 319 aserciones |
| Migración MySQL aislada | 2 PASS; 10 aserciones |
| Concurrencia con procesos PHP independientes | 8 PASS; 387 aserciones |
| JavaScript | 109 PASS, 0 FAIL, 1 SKIP preexistente; incluye 4 pruebas nuevas del flujo PayPal |
| Build Vite cliente y SSR | PASS |
| PHP lint de todos los archivos PHP pendientes | 40 archivos, 0 errores |
| `git diff --check` | PASS |

Los reportes PHP incluyen solapamientos: deduplicando por clase/nombre son **403 casos distintos, 5,986 aserciones**, sin fallos. La suite amplia se ejecutó antes de los tres últimos casos P0; el cierre focal y la matriz de procesos se ejecutaron con las correcciones finales. No se presenta cada repetición como una prueba adicional.

Los doce fallos originales están resueltos dentro de los 21 casos de `LaboratoryPreparationGenerationDispatchTest`: AUTO, shadow, FALLBACK, deduplicación, pagos acreditados, folio/consecutivo, ausencia de estado explícito e inelegibilidad continúan comprobándose. Se completaron fixtures; no se desactivaron ni omitieron assertions de elegibilidad.

P2 continúa pasando en la regresión amplia: marcador `AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML` en las rutas negativas, pruebas de worker/failed_jobs y diagnósticos administrativos del bloque A. Además, fallos sintéticos al persistir GDA y Odessa no escapan como texto libre por las nuevas excepciones. Inspección final de `storage/logs/*.log`: cero archivos con el canario. No se imprimió contenido de logs ni se inspeccionaron colas/bases habituales.

El SKIP JavaScript corresponde al navegador de un módulo ajeno por falta de `libnss3.so`; los cuatro casos nuevos se ejecutaron. El bootstrap temporal de memoria para la suite PHP amplia es solo de pruebas y se elimina al finalizar; no se modificaron TestCase, runners ni configuración compartida.

### Matriz de concurrencia MySQL

Cada escenario inicia dos procesos PHP independientes con una barrera compartida SQL y ejecuta después un tercer reintento independiente. Usa las acciones productivas con dobles de proveedores y auditoría en la base descartable; no usa cache en memoria para coordinar el bloqueo.

1. Dos finalizadores: una compra/una asociación, una solicitud GDA.
2. Dos callbacks con falla entre respuesta de captura y persistencia: una captura, cero solicitudes GDA, marcador durable y bloqueo de otro cobro.
3. Dos capturas simultáneas: una captura y una solicitud GDA.
4. Dos checkouts del carrito: un cargo simulado y una solicitud GDA.
5. Callback más webhook mediante el manejador real: una captura/una solicitud GDA.
6. Muerte del proceso después de enviar GDA: fila `pending` durable, lock liberado y recuperación como incertidumbre sin repetir GDA.
7. Timeout y reintento: una solicitud GDA, fondos conservados, cero reembolsos.
8. Respuesta GDA y falla al persistir confirmación: una solicitud GDA, fondos conservados y revisión requerida.

En éxitos se comprueba un cierre del carrito y efectos existentes una sola vez. En incertidumbre se comprueba carrito activo, vínculos financieros conservados y ausencia de notificaciones/conversiones/automatizaciones de compra completada. La matriz no certifica redes ni proveedores reales ni garantiza entrega de correo tras un crash.

### Reportes locales ignorados por Git

- `storage/logs/gda-p0-regression-final.xml`
- `storage/logs/gda-p0-financial-final.xml`
- `storage/logs/gda-p0-monitor.xml`
- `storage/logs/gda-p0-migration.xml`
- `storage/logs/gda-p0-concurrency-final.xml`

## Archivos de esta implementación

No incluye los 22 archivos anteriores del bloque A. Son 27 archivos de código/pruebas más este informe:

### Acciones y entrada web

- `app/Actions/Laboratories/CreateGDAQuotationAction.php`
- `app/Actions/Laboratories/FulfillLaboratoryCartOrderAction.php`
- `app/Actions/Laboratories/OrderAction.php`
- `app/Actions/Laboratory/HandleStatusUpdateAction.php`
- `app/Actions/Odessa/ChargeOdessaAction.php`
- `app/Actions/PayPal/CapturePayPalOrderAction.php`
- `app/Actions/PayPal/CreatePayPalOrderAction.php`
- `app/Actions/PayPal/FinalizeLaboratoryPayPalPaymentAction.php`
- `app/Http/Controllers/LaboratoryPurchaseController.php`
- `app/Http/Controllers/PayPalController.php`
- `resources/js/lib/paypal/createLaboratoryPayPalHandlers.js`

### Contrato y servicios

- `app/Models/LaboratoryPurchase.php`
- `app/Enums/GdaOrderStatus.php`
- `app/Exceptions/GdaOrderDefinitiveException.php`
- `app/Exceptions/GdaOrderResultUncertainException.php`
- `app/Exceptions/LaboratoryPaymentAlreadyReceivedException.php`
- `app/Exceptions/LaboratoryPaymentInProgressException.php`
- `app/Services/Laboratory/GdaOrderConfirmation.php`
- `app/Services/Laboratory/GdaPaymentDiagnostics.php`
- `app/Services/Laboratory/UncertainGdaPaymentGuard.php`
- `database/migrations/2026_10_16_220000_add_gda_order_status_to_laboratory_purchases.php`

### Pruebas

- `tests/Feature/LaboratoryPreparationGenerationDispatchTest.php`
- `tests/Feature/Laboratory/GdaOrderP0ProtectionTest.php`
- `tests/Feature/Laboratory/GdaP0ConcurrencyTest.php`
- `tests/Feature/Laboratory/GdaP0MigrationTest.php`
- `tests/Support/GdaP0Process.php`
- `tests/JavaScript/laboratoryGdaPaymentGuard.test.mjs`

## Riesgos y pendientes

1. **Bloqueante operativo:** migración autorizada pendiente en cualquier base que vaya a ejecutar el checkout. No se ejecutó sobre la base habitual. Las compras históricas quedan `NULL`; cualquier recuperación/elegibilidad retroactiva necesita decisión y evidencia separadas.
2. **Bloqueante operativo si existe columna importada:** revisar estados/índice y diseñar reconciliación explícita. El test confirma que la migración se niega sin tocar datos.
3. **Revisión financiera manual:** pagos `processing`, `pending/uncertain`, débito/captura sin persistencia, capturas externas adicionales y reembolsos ambiguos no deben resolverse repitiendo pago/GDA o liberando marcadores a ciegas. Consultar primero evidencia del proveedor por un operador autorizado. No se añadió reconciliación financiera automática.
4. **Infraestructura:** todos los callers deben compartir el mismo MySQL escritor con permisos para `GET_LOCK`; no utilizar cache local ni un proxy que cambie de sesión en medio de la operación. Se validó MySQL 8 del Docker existente, no la infraestructura de staging.
5. **Entrega posterior al commit:** el guard previene repeticiones; no recupera por sí solo automatizaciones/notificaciones omitidas por un crash. La entrega durable queda para el bloque B; no se promete un correo único con este bloque.
6. **QA manual anterior:** sesión autenticada y zoom real al 200 % siguen pendientes. El monitor no cambió en esta implementación y su regresión HTTP pasa.
7. **Límite de certificación:** pruebas con proveedores simulados; no se verificaron servicios reales, colas habituales ni datos históricos de la base usual. No se declara aptitud para despliegue/activación.

## Dictamen

**GO técnico para preparar una integración selectiva del correo único (bloque B), sujeta a autorización separada.** La incompatibilidad de estado y los doce fallos originales quedan resueltos; las carreras y fallas ensayadas no repiten cargos/GDA/reembolsos.

**NO-GO para operar el checkout modificado en la base habitual, desplegar o activar funcionalidades hasta autorizar/aplicar la migración y resolver los requisitos operativos del destino.** No se implementó el bloque B ni se cambió ningún flag. No se requiere repetir pruebas clínicas amplias por una futura documentación; cualquier cambio financiero posterior sí requiere revalidar sus caminos afectados.
