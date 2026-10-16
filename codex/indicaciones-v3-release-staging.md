# Candidato de staging: monitor e indicaciones IA v3

Destino previsto: `beta.famedic.com.mx`. Rama: `codex/indicaciones-ia-v3-mejoras-v2`. Base de comparación: `release/indicaciones-ia-v3-staging` en `f3ea57f9346c63842a64721e859833037b78143c`. No se mezcla `description-ia` ni otra rama. Esta guía prepara operaciones futuras; no acredita acceso ni cambios en Forge o staging.

## Alcance y aceptación local

Incluye monitor renovado, coordinación durable de correo único, UUID de snapshot y CAS P1, sanitización P2, dos migraciones aditivas y pruebas. El código de pagos/GDA y las llamadas de automatización existentes permanecen intactos; el cambio de fulfillment comienza después del bloque financiero. No se cambian prompts ni reglas R01–R18.

Validación focal: 163 PHP PASS, 1,016 aserciones y 5 JavaScript PASS. La auditoría integrada MySQL 8.0.43 usa procesos independientes, proveedores simulados y base descartable: la intercalación P1 anterior reproduce el fallo y las tres ejecuciones corregidas preservan igualdad HTML/texto y tipo de snapshot; el canario P2 no aparece en aplicación/workers/failed_jobs/ejecuciones/diagnóstico administrativo inspeccionados. Las suites PHP existentes usan SQLite en memoria; no se confunden con las pruebas MySQL. Evidencias y logs permanecen fuera del repositorio.

## Configuración inicial: correo único OFF

Los valores siguientes se comprobarán en la configuración efectiva de staging después de una futura aprobación, sin volcar el entorno completo ni credenciales:

```dotenv
LAB_CONFIRMATION_COORDINATOR_ENABLED=false
LAB_CONFIRMATION_COORDINATOR_ACTIVATED_AT=
LAB_CONFIRMATION_CONTENT_WAIT_SECONDS=15
LAB_CONFIRMATION_QUEUE_CONNECTION=database
LAB_CONFIRMATION_QUEUE=laboratory-confirmations
LAB_CONFIRMATION_GENERATION_QUEUE=laboratory-preparation
```

Son valores operativos propuestos, no un `.env` modificado. El código también tiene enabled=false y fecha nula por defecto. No configurar una fecha para este despliegue ni cambiar flags clínicos o de ActiveCampaign.

Condición para el comportamiento legacy: ausencia de frontera previamente persistida y ausencia de intenciones previas para la compra. Con ella, el coordinador no enrola ni envía; el reconciliador sale sin crear intenciones y el correo IA adicional conserva su ruta previa. Las migraciones no crean intenciones ni modifican compras. El monitor mejorado está disponible sin activar correo único.

Después de una activación previa, OFF pausa entregas de la cohorte y conserva la supresión del segundo correo. No vuelve a legacy. No borrar la frontera o el ledger para forzar ese retorno.

## Secuencia futura de despliegue OFF, solo tras aprobación

1. Registrar revisión Git aprobada y revisar el script de deploy de Forge antes de ejecutarlo. No hacer merge de ramas adicionales. Confirmar respaldo y plan de reversión del entorno según su procedimiento habitual.
2. Comprobar los valores efectivos anteriores y que no existe frontera previa. Si hay frontera/intenciones previas, detener la aceptación del escenario «legacy sin cohorte» y revisar ese estado; no eliminar registros.
3. Evitar workers con código antiguo en paralelo al nuevo. Coordinar una parada/drenaje con el proceso habitual del sitio y el despliegue atómico. No reiniciar un proceso antiguo entre la migración y el cambio de release.
4. Instalar las dependencias bloqueadas y generar frontend usando el proceso de despliegue existente. Aplicar `php artisan migrate --force` en el nuevo release antes de ejecutar sus workers. No ejecutar migraciones durante esta preparación local.
5. Las migraciones nuevas son `2026_10_16_200000_create_laboratory_purchase_confirmations_table.php` y `2026_10_16_210000_add_snapshot_version_to_laboratory_purchase_confirmations.php`. La primera crea frontera/ledger; la segunda añade UUID y asigna versiones a snapshots existentes en bloques de 100 sin descifrarlos ni reconstruirlos. No cambia filas de compras, pagos o GDA. Mantener APP_KEY estable y custodiada, sin imprimirla.
6. Regenerar la caché de configuración mediante el procedimiento existente del sitio y volver a comprobar solo flags/colas/fecha. Los workers largos no recargan configuración por sí solos.
7. Reiniciar los workers existentes contra el release nuevo con `php artisan queue:restart` y el supervisor habitual. La señal usa el cache del sitio: verificar que el supervisor los repone y que los procesos realmente pertenecen al nuevo release. No basta con asumir que el comando reinició todo.
8. Comprobar `php artisan migrate:status`, evento del reconciliador, salud de workers y el QA siguiente. No crear todavía workers adicionales ni activar el coordinador como parte del despliegue OFF.

Consultas de verificación futuras, solo lectura y sin contenido de pacientes:

```sql
SELECT COUNT(*) AS cohort_boundaries
FROM laboratory_confirmation_rollouts
WHERE `key` = 'purchase_confirmation';

SELECT COUNT(*) AS automatic_intents
FROM laboratory_purchase_confirmations
WHERE event_type = 'purchase_confirmation';
```

Para el escenario inicial se espera 0/0; registrar el resultado inicial y confirmar que no crece con OFF. Si ya hay registros, no asumir que un resultado no nulo es una regresión nueva: investigar la historia del entorno sin borrar datos. No se ejecutaron estas consultas en staging durante la preparación.

## Preparación de una futura activación, separada del despliegue OFF

Estos recursos son requisitos previos de beta y no se crean ahora. Se documenta `database`, el backend ensayado:

```sh
php artisan queue:work database --queue=laboratory-confirmations --sleep=1 --tries=1 --timeout=60
php artisan queue:work database --queue=laboratory-preparation --sleep=1 --tries=3 --timeout=75
```

Propuesta inicial: dos procesos de confirmaciones y al menos uno de preparación, en grupos independientes. No consumir ambas colas en un único worker. El job de confirmación fija tries=1/timeout=60; recuperación y reintentos pertenecen al ledger. El de preparación conserva tries=3/backoff=[60,300,900]. No eliminar los workers de otras colas.

Propuesta a revisar antes de aplicar: `DB_QUEUE_RETRY_AFTER=180`, por encima de ambos timeouts; `stopwaitsecs=180` en supervisor. Esa conexión puede estar compartida por otros jobs: comprobar sus duraciones antes de cambiarla. Transporte del coordinador=20s (acotado a 1–30), lease mínimo=120s; revisar timeout efectivo de generación/OpenAI y confirmar que cabe en el timeout/visibility del worker. No se cambiaron esas variables compartidas.

Verificar un cron de Laravel por minuto y cache persistente/compartida para mutex. `laboratory-confirmations:reconcile` ya está programado cada minuto con `withoutOverlapping(2)`. Con OFF sale sin recuperar ni crear entregas. No ejecutar `schedule:run` manual indiscriminadamente: puede disparar otros módulos. Revisar el evento con `schedule:list` y observar el cron habitual.

El mailer del coordinador debe ser un transporte permitido, con timeout acotado y credenciales del sitio custodiadas. En operación se rechazan failover, roundrobin, log, array y sendmail. La validación local usa dobles/array solo en testing; no acredita compatibilidad del proveedor real. Ninguna prueba real de correo se autoriza por esta guía.

Una activación posterior exige autorización separada, revisión del entorno y un instante ISO-8601 absoluto con zona deliberadamente elegido. No usar una fecha histórica arbitraria: ampliaría la cohorte. La frontera persistida es inmutable; cambiar después la variable no la mueve. No activar ni alterar los flags clínicos en este release.

## Pausa y recuperación

- Pausar: enabled=false, conservar la fecha y la frontera. Recargar configuración y detener/drenar/reiniciar los workers coordinados según la operación aprobada. Una configuración nueva no cancela un transporte ya iniciado.
- Durante la pausa, las compras de la cohorte siguen coordinadas y pendientes; no retornan a legacy ni disparan el segundo correo IA.
- Reanudar: enabled=true con la misma frontera, actualizar caché de configuración, reiniciar workers y restablecer scheduler. El reconciliador despacha waiting/ready/retry_wait debidos. No borrar snapshots ni reconstruir el contenido de un reintento.
- Una caída en preparing espera lease y reconciliador; no se promete recuperación instantánea. Sending vencido pasa a ambiguous y no se reintenta automáticamente.
- `php artisan laboratory-confirmations:reconcile --review` lista IDs/códigos de ambiguous/failed sin cuerpos. La investigación y un eventual reenvío administrativo requieren evidencia y autorización; no se promete exactamente una entrega frente a aceptación de red desconocida.

## QA posterior al despliegue: pendiente de ejecución

Registrar operador, revisión desplegada, fecha y evidencias sanitizadas. No usar pacientes ni correos reales de prueba sin autorización.

- [ ] Confirmar host `beta.famedic.com.mx` y revisión Git aprobada; rutas/assets del release nuevo.
- [ ] `migrate:status` muestra ambas migraciones aplicadas, sin errores ni cambios de compras existentes.
- [ ] Flag efectivo false, fecha efectiva vacía; frontera previa ausente para el escenario inicial. Registrar conteo de intenciones y verificar que no crece con OFF.
- [ ] Administrador accede al monitor; usuario sin rol Administrador no accede al listado ni al detalle, incluso por URL directa.
- [ ] KPI total/AUTO/FALLBACK/revisión/fallos y métricas secundarias coherentes con los mismos filtros; categorías pueden superponerse.
- [ ] Búsqueda, fechas, laboratorio, filtros avanzados, limpiar, URL, paginación de 25 y retorno desde detalle conservan estado.
- [ ] Compras históricas y eliminadas: detalle autorizado, ejecución vinculada correcta o última ejecución relevante sin resumen; historial y originales coherentes.
- [ ] El detalle distingue decisión persistida de la representación actual del paciente; JSON técnicos sin payloads libres ni errores sensibles.
- [ ] Desktop y móvil sin scroll horizontal; probar tabla/tarjetas, textos largos y detalle.
- [ ] Zoom real del navegador al 200%, navegación y lectura completas. Sigue pendiente: reflow previo no sustituye esta prueba.
- [ ] Con OFF sin cohorte, confirmar continuidad de correo de compra actual y correo IA adicional mediante observación autorizada o fixture sintética/destinatario controlado. Ningún envío real de prueba está autorizado todavía.
- [ ] Confirmar que no hay despacho a las colas nuevas ni intención automática retroactiva por despliegue/scheduler.
- [ ] Revisar cron y workers existentes en el release nuevo; ausencia de errores nuevos. No crear los grupos nuevos como parte de este QA OFF.

## Reversión y límites

Preferir reversión de código manteniendo el esquema aditivo y la auditoría, siempre con OFF y verificación del estado de cohorte. No ejecutar down como rollback rutinario: la primera migración elimina historial y la segunda rompe el contrato de los workers nuevos. No mezclar código antiguo con entregas activas. Cualquier reversión con cohorte previa requiere plan específico.

El saneamiento P2 protege fallos nuevos; no borra logs/errores históricos. La validación local no certifica Forge, correo real, bandeja de entrada ni observabilidad externa. GO para solicitar aprobación de push y despliegue OFF; ambos permanecen pendientes de la aprobación del usuario. NO-GO para activación del correo hasta cerrar validación operativa y autorización de beta.
