# Indicaciones IA v3 Correo único de confirmación

La coordinación se implementa en `codex/indicaciones-ia-v3-mejoras-v2`, sobre la base validada `f3ea57f9346c63842a64721e859833037b78143c`. Permanece desactivada por defecto. El cambio de confirmación y la supresión del correo IA se liberan conjuntamente; no se activan en esta ejecución.

## Flujo y límites

Fulfillment conserva su transacción, cobro, confirmación GDA, conversiones y automatizaciones. Solo cambia la comunicación posterior: una compra de la cohorte nueva registra su intención después del commit exterior y evita la confirmación síncrona y el correo concierge que repetía la información de esa misma compra. Las actualizaciones posteriores reales de concierge conservan su notificación.

La intención tiene clave automática única por compra y evento. El coordinador usa una cola exclusiva, no llama a OpenAI y no duerme esperando el resumen. La generación v3 de compras de la cohorte se dirige a otra cola; los demás Jobs conservan su destino.

El plazo absoluto inicia al registrar la confirmación después del commit. Si ese registro falla, el reconciliador usa la creación de compra como límite inferior conservador: puede enviar originales antes, pero nunca renovar la espera desde el momento de recuperación. Los reintentos no cambian el plazo.

AUTO requiere la representación vigente del presenter, decisión AUTO_CONSOLIDATED y fecha de generación dentro del plazo. Un resultado que llegó después no gana por retraso del worker. FALLBACK, IA OFF o fallo conocido permiten originales inmediatamente. Al vencer se usan originales si no hay AUTO válido. No se añade ningún mecanismo de interpretación clínica.

Ambas variantes existentes de compra incluyen preparación en el nuevo flujo. Con cita se incluyen fecha/sucursal y desaparece el anuncio del segundo correo. El snapshot conserva HTML y texto del Markdown existente, destinatario y asunto; se cifra con el encrypter de Laravel antes de persistir. No aparece en serialización del modelo ni logs. Antes del primer intento se revalida un AUTO preparado si cambió la fuente o se apagó IA. Después de un rechazo conocido, los reintentos conservan exactamente el contenido del primer intento.

## Persistencia y estados

La migración `2026_10_16_200000_create_laboratory_purchase_confirmations_table.php` crea:

- `laboratory_confirmation_rollouts`: frontera de activación persistente e inmutable.
- `laboratory_purchase_confirmations`: intención automática o reenvío explícito, actor, plazo, contenido cifrado, hash, UUID de intento, contadores, lease, aceptación del transporte y códigos seguros de error.

| Estado | Significado y recuperación |
| --- | --- |
| waiting | Espera de contenido o preparación recuperable. |
| preparing | Reclamación para preparar contenido; lease vencido vuelve a waiting. |
| ready | Snapshot listo, aún sin intento de transporte. |
| sending | Reclamación atómica de entrega; UUID exclusivo y contador incrementado. |
| retry_wait | Rechazo inequívoco; espera el siguiente intento. |
| accepted | Transporte confirmó aceptación; no acredita entrega en bandeja. |
| ambiguous | Timeout, desconexión o worker caído durante envío. No reintenta automáticamente. |
| failed | Intentos agotados, snapshot ilegible o preparación irrecuperable. Requiere revisión. |
| cancelled | Compra eliminada/no disponible o sin contrato GDA confirmado. |

Las actualizaciones condicionales por estado y token impiden dos envíos concurrentes. La red no se usa dentro de una transacción de base de datos. Una confirmación positiva tardía del mismo token puede resolver un estado ambiguo como accepted.

El adaptador SMTP distingue respuesta negativa explícita de pérdida de confirmación. Un mailer nuevo no reinicia su conexión después de aceptar el mensaje; se evita confundir un error posterior de reconexión con rechazo. Failover y round-robin se rechazan para no delegar reintentos ocultos. En operación se rechazan transportes log/array/sendmail para este flujo. Las excepciones genéricas se tratan como ambiguas, no como rechazo probado. Solo las pruebas usan array o transportes simulados.

La política predeterminada permite tres intentos ante no aceptación conocida, con esperas de 30 y 120 segundos. La reclamación dura al menos 120 segundos y el Job tiene timeout de 60 segundos. La preparación de contenido también tiene reintentos acotados. El payload no contiene credenciales ni prompts, pero sí información del correo: el acceso a tablas y claves de cifrado debe mantenerse restringido.

## Rollout y pausa

| Configuración | Predeterminado |
| --- | --- |
| LAB_CONFIRMATION_COORDINATOR_ENABLED | false |
| LAB_CONFIRMATION_COORDINATOR_ACTIVATED_AT | Sin valor; exige ISO 8601 absoluto con zona horaria y fecha ya alcanzada. |
| LAB_CONFIRMATION_CONTENT_WAIT_SECONDS | 15; acotado entre 0 y 60. |
| LAB_CONFIRMATION_QUEUE_CONNECTION | database; no se admite sync/null. |
| LAB_CONFIRMATION_QUEUE | laboratory-confirmations |
| LAB_CONFIRMATION_GENERATION_QUEUE | laboratory-preparation |

No se modifican `.env`, flags clínicos ni valores de Forge. Antes de la primera activación, con enabled=false, el comportamiento legacy permanece intacto. Tras iniciar la cohorte se conserva su frontera en base de datos. Apagar enabled pausa las entregas coordinadas y mantiene registradas las nuevas compras de esa cohorte; no las devuelve a legacy, porque al reanudar eso provocaría duplicados. Los operadores deben conocer que una pausa requiere seguimiento de confirmaciones pendientes.

Las compras anteriores a la frontera conservan sus envíos legacy y nunca se incorporan por reconciliación automática. Cambiar la fecha configurada no desplaza la frontera ya persistida. Un reenvío administrativo explícito sí puede referirse a una compra histórica y crea una intención separada con actor. Durante una pausa queda pendiente y auditado.

El aviso interno de resumen disponible conserva su lógica de validación y deduplicación. Para compras de la cohorte se omite únicamente su correo. Los registros anteriores y timestamps no se reescriben. Un rollback del flag operativo no reactiva el correo IA de una compra ya enrolada.

## Workers y operación de staging pendiente

Requiere migración aditiva antes de activar, APP_KEY disponible y estable para el cifrado, scheduler Laravel y capacidad independiente de workers. Ejemplos orientativos para revisión operativa, no ejecutados:

```sh
php artisan queue:work database --queue=laboratory-confirmations --sleep=1 --tries=1 --timeout=60
php artisan queue:work database --queue=laboratory-preparation --sleep=1 --tries=3 --timeout=75
```

El worker de confirmación no debe consumir la cola de generación. Revisar retry_after/visibility timeout: debe exceder el timeout del worker; database tiene valor predeterminado 90 segundos. La configuración y disponibilidad reales de Forge no se verificaron. El timeout del transporte se acota a 20 segundos por defecto, sin alterar el mailer global. Revisar compatibilidad del transporte configurado antes de activar.

El scheduler ejecuta `laboratory-confirmations:reconcile` cada minuto. Recupera intenciones omitidas y despachos fallidos, reclama trabajo pendiente y marca envíos interrumpidos como ambiguos; nunca invoca fulfillment, cobros, GDA o ActiveCampaign. La recuperación tras una caída no tiene el mismo tiempo objetivo que la vía normal de 15 segundos. Una caída total de workers o base de datos requiere intervención.

Para revisión, `php artisan laboratory-confirmations:reconcile --review` lista hasta 100 casos ambiguous/failed con IDs y códigos, sin destinatarios ni contenido. Los casos ambiguos solo permiten un reenvío administrativo explícito autorizado; no se promete exactamente una entrega. No ejecutar down de esta migración como rollback rutinario, porque contiene auditoría de entregas.

## Aceptación antes de activar

Pruebas locales con SQLite descartable, contenedores sin red y transportes simulados cubren decisiones, plazo, contenido completo, dos procesos concurrentes, interrupciones, despachos fallidos, cohortes y reenvío auditado. Las suites focales existentes verifican que no cambian las protecciones financieras/GDA ni el monitor.

La validación integrada ya se ejecutó en MySQL 8.0.43 descartable con workers independientes y transporte simulado: migraciones/rollback/reaplicación, despacho perdido, interrupción de preparación/transporte, recuperación, coherencia de snapshots y casos ambiguos. P1 se reprodujo con el código anterior y pasó tres repeticiones corregidas; el canario P2 estuvo ausente en los canales inspeccionados. La regresión focal reporta 163 PHP PASS, 1,016 aserciones y 5 JavaScript PASS. La preparación de staging OFF es GO; la activación sigue pendiente de validar configuración efectiva de staging y autorización. Véase `indicaciones-v3-release-staging.md`.


## Correcciones focales P1/P2 — 2026-10-16

P1: cada preparación fija un `snapshot_version` UUID independiente del hash clínico y del UUID de transporte. Invalidación, fallo de descifrado y reclamación para envío comparan esa versión. La reclamación también exige el intento que leyó el worker; así un lector antiguo no consume un reintento posterior. Los reintentos permitidos conservan versión, HTML, texto, destinatario y asunto. La red permanece fuera de transacciones/bloqueos.

La migración aditiva `2026_10_16_210000_add_snapshot_version_to_laboratory_purchase_confirmations.php` añade la columna y asigna UUID a snapshots existentes sin descifrarlos ni reconstruirlos. Se debe aplicar antes de ejecutar workers nuevos. Drenar/detener workers de la versión anterior y reiniciarlos con el release nuevo: código antiguo no respeta el CAS de versión. No hacer down con entregas activas. No se modifican la frontera de cohorte ni los valores predeterminados del rollout.

P2: `LaboratoryPreparationOperationalException` transporta solo etapa/categoría controladas y UUID de correlación, sin excepción previa ni mensaje de proveedor. El job protege también errores anteriores a generación y errores secundarios del fallback. Se conservan tries=3 y backoff=[60,300,900]. Los errores de ejecución del redactor/servicio y el reporte automático usan datos seguros; los fallos de fidelidad conservan su clasificación, pero no persisten la respuesta fallida libre. La notificación y shadow usan códigos o clases expresamente permitidas. No cambian prompts, reglas, validaciones clínicas ni decisiones AUTO/FALLBACK.

La verificación utiliza MySQL descartable, procesos independientes y barreras: el código anterior reproduce el mismatch y el nuevo conserva igualdad entre correo y snapshot. La prueba del canario incluye aplicación, workers, `failed_jobs`, ejecuciones persistidas y detalle administrativo serializado, además de fallos primarios/secundarios. No es una limpieza de errores históricos ni una certificación de observabilidad externa no conectada.

La recuperación no es instantánea si el worker cae en `preparing`: debe vencer el lease de 120 segundos y pasar el reconciliador. En pruebas se avanza ese vencimiento únicamente en la base descartable. Una caída en transporte sigue siendo ambigua y no permite reintento automático.
