# Prueba local controlada del correo único

## Estado después del ajuste de navegación

El modo QA y ambas puertas del coordinador están apagados en el `.env` local.
Se retiraron el middleware global de navegación QA y su CSP, incluido el hook de errores.
No se modifica la CSP original ni se fuerzan valores de widgets.
La navegación no depende de la dirección QA. Las imágenes, fuentes y redirecciones siguen
las políticas normales de la plataforma. No se modificó Docker ni Gmail.
Las instrucciones de activación siguientes son optativas y NO se ejecutaron en este ajuste.
Fuera de QA vuelven las integraciones originales: ActiveCampaign/OpenAI pueden contactar
proveedores, PayPal sandbox también es externo y Odessa conserva su integración real.
Efevoo y creación de órdenes GDA conservan sus simuladores locales originales.
El navegador ya no está aislado de servicios externos por QA; los recursos y SDKs siguen
la configuración habitual. El bloqueo HTTP QA sólo cubre el backend cuando se habilita QA.


Proyecto `/home/usuario/projects/famedic`, rama `feature/indicaciones-ia-v3-mejoras`.
En el ajuste de navegación sólo se cambiaron a `false` tres switches locales: QA, ENABLED y RELEASE_READY.
No se modificó Docker ni credenciales. No se activó el coordinador ni se enviaron correos.
La dirección autorizada se configura exclusivamente mediante `LOCAL_CHECKOUT_QA_RECIPIENT`.
El correo `qa-autorizado@example.invalid` del ejemplo es un placeholder; sustituirlo por la dirección QA aprobada existente localmente.
Debe ser la misma dirección de inicio de sesión del usuario local ya verificado por el preflight.

## Arranque simplificado: una sola terminal

Con la configuración QA preparada y ambas puertas habilitadas por el operador:

```bash
docker compose exec app php artisan local-qa:listen
```

Ejecutar desde la carpeta existente del proyecto. No requiere pegar fingerprint ni cambiar
colas. Obtiene la huella y valida protecciones automáticamente; inicia simultáneamente
confirmaciones y preparación, exclusivamente en sus colas QA. Permanece escuchando hasta
Ctrl+C, sin el límite de 15 minutos de los comandos individuales. Si uno termina o falla,
detiene el otro y muestra el problema; no deja sólo confirmaciones funcionando.

Antes de usarlo, detener con Ctrl+C los workers QA individuales abiertos anteriormente.
No iniciar otra copia ni hacer una compra hasta que los pendientes de preparación terminen
con DONE y ambos workers estén escuchando. Arrancar puede enviar confirmaciones QA pendientes.

Para comprobar sin iniciar procesos ni enviar correos:

```bash
docker compose exec -T app php artisan local-qa:listen --check
```

Ctrl+C pide una parada ordenada de ambos hijos; esperar a que termine el comando.
No modifica `.env`, flags, fecha de activación, Docker, CSP ni credenciales.
Los pasos de dos terminales posteriores quedan disponibles como procedimiento avanzado.

## Preparación por el operador

Ejecutar los comandos en una terminal WSL Ubuntu:

```bash
cd /home/usuario/projects/famedic
git branch --show-current
```

Actualizar solamente estas claves en el `.env` existente (una sola aparición por clave).
Conservar todas las claves Gmail `MAIL_*`, incluyendo credenciales y MAIL_URL si existiera.

```dotenv
LOCAL_CHECKOUT_QA_ENABLED=true
LOCAL_CHECKOUT_QA_RECIPIENT=qa-autorizado@example.invalid
LOCAL_CHECKOUT_QA_SCENARIO=auto
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=180
LAB_PREPARATION_DETERMINISTIC_V3_ENABLED=true
LAB_PREPARATION_DETERMINISTIC_V3_SHADOW_ENABLED=false
LAB_CONFIRMATION_COORDINATOR_ENABLED=false
LAB_CONFIRMATION_COORDINATOR_RELEASE_READY=false
LAB_CONFIRMATION_COORDINATOR_ACTIVATED_AT=
LAB_CONFIRMATION_CONTENT_WAIT_SECONDS=15
LAB_CONFIRMATION_QUEUE_CONNECTION=database
LAB_CONFIRMATION_QUEUE=laboratory-confirmations-qa
LAB_CONFIRMATION_GENERATION_QUEUE=laboratory-preparation-qa
```

La política QA ignora las listas amplias de dominios del mail safe mode: permite únicamente
la dirección exacta indicada y bloquea el mensaje entero ante cualquier To, CC, BCC o
recipiente de sobre distinto. No redirige mensajes de otros usuarios a QA.
Los mailers alternativos se rechazan; solamente SMTP Gmail está permitido.
ActiveCampaign y automatizaciones de compra quedan bloqueados en QA local; no se generan
nuevos jobs de automatización de compra para ejecutarlos más adelante al desactivar QA.
El middleware HTTP QA deniega solicitudes del backend únicamente con QA explícitamente habilitado.
No se agrega CSP ni se bloquean recursos visuales o navegación del navegador.
Efevoo y creación de orden GDA usan los simuladores existentes. PayPal/Odessa y otros
checkouts quedan bloqueados. El preflight y checkout rechazan configuraciones incompletas.

```bash
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan local-qa:status --initial
```

Debe devolver `ok: true`, coordinador OFF, cero confirmaciones y fronteras, ninguna cola QA
con trabajos. `automation` debe conservar sus dos jobs. Detenerse ante cualquier issue.
No ejecutar workers generales, `queue:retry`, Horizon ni scheduler completo; los accesos
habituales por Artisan se rechazan mientras QA está habilitado.
No permitir ningún worker antiguo previamente iniciado sin estas protecciones.

Cerrar sesiones anteriores y abrir una ventana privada. Iniciar sesión con la cuenta QA
existente; no crear usuarios ni iniciar verificaciones por SMS. Abrir:
`http://127.0.0.1:8080/__local-checkout-qa/status`.
Debe indicar `ok: true` y la misma huella (`fingerprint`) que CLI.
Comparar el campo JSON `fingerprint` de esa página con CLI. No se agrega encabezado QA.
La navegación está disponible para otros usuarios; sólo las compras QA y sus entregas conservan el control de propietario.

## Activación posterior: solamente cuando el operador autorice la prueba

Generar la frontera DESPUÉS de completar la preparación inicial:

```bash
date -u -d '+2 minutes' '+%Y-%m-%dT%H:%M:%SZ'
```

Copiar exactamente el resultado nuevo a `LAB_CONFIRMATION_COORDINATOR_ACTIVATED_AT`.
Cambiar ambas puertas a `true`:

```dotenv
LAB_CONFIRMATION_COORDINATOR_ENABLED=true
LAB_CONFIRMATION_COORDINATOR_RELEASE_READY=true
```

```bash
docker compose exec -T app php artisan config:clear
# Esperar a que pase la fecha UTC elegida antes de comprar o iniciar workers.
docker compose exec -T app php artisan local-qa:status --ready
```

Debe ser `ok: true`; el preflight rechaza una frontera inicial de más de diez minutos.
Volver a cargar `/__local-checkout-qa/status` en el navegador y comparar la huella nueva
con CLI. Sigue habiendo cero intenciones y fronteras; estas verificaciones son de lectura.
Copiar la huella NUEVA del navegador. Ejecutar cada worker en una terminal WSL independiente:

```bash
cd /home/usuario/projects/famedic
read -r -p 'Fingerprint del navegador: ' QA_FP
docker compose exec -T app php artisan local-qa:worker confirmations --fingerprint="$QA_FP"
```

```bash
cd /home/usuario/projects/famedic
read -r -p 'Fingerprint del navegador: ' QA_FP
docker compose exec -T app php artisan local-qa:worker preparation --fingerprint="$QA_FP"
```

Son wrappers de `queue:work database`, exclusivamente en `laboratory-confirmations-qa`
(timeout 60) y `laboratory-preparation-qa` (timeout 120). Caducan después de 900 segundos.
`retry_after=180` evita liberar una reserva antes del timeout mayor. No consumen `automation`,
`default` ni las colas anteriores sin sufijo QA. Los reintentos de confirmación pertenecen al ledger.

## Compra manual

1. Usar únicamente el usuario QA autorizado y datos sintéticos. No usar pacientes reales.
2. Elegir un estudio conocido del catálogo con indicaciones simples reconocidas por el motor
   (por ejemplo, ayuno de 8 horas). No alterar catálogo, prompts ni reglas para forzar AUTO.
3. Para empezar, elegir un estudio sin cita. Usar dirección y contacto QA propios existentes.
4. Elegir **Visa MOCK aprobada / siempre aprueba**, sin introducir tarjetas reales.
   El checkout QA presenta sólo los métodos simulados. No usar cupones ni saldos reales.
5. Confirmar una sola vez, guardar el ID de compra y esperar el correo. No usar reenvío administrativo.
6. Después de validar la primera compra, una compra QA nueva con cita permite verificar la cita;
   no modificar compras anteriores ni recurrir a proveedores externos para agendar.
   Si el flujo de cita requiere una llamada bloqueada, detener ese escenario; no retirar la protección.

Escenarios: `auto`, `fallback`, `late`, `error` en `LOCAL_CHECKOUT_QA_SCENARIO`.
Para cambiar escenario: detener ambos workers, modificar sólo ese valor, `config:clear`,
comparar nuevamente huellas de navegador/CLI y reiniciar ambos wrappers con la huella nueva.
Cada escenario requiere una compra NUEVA; no cambiar escenario mientras haya entregas pendientes.
Conservar la frontera persistida de la misma cohorte. No vaciar tablas ni cambiar su fecha.

`auto`: copia únicamente requisitos calculados por el motor real y pasa por el validador real;
si el motor detecta conflicto o cobertura insuficiente, conserva FALLBACK sin flexibilizar reglas.
`fallback`: simula una respuesta inválida del redactor; entrega originales por fallo técnico.
`late`: demora real de 20 segundos; debe entregar originales al vencer los 15 segundos y
conservarlos cuando llegue AUTO tarde. `error`: simula error de proveedor y entrega originales.
Ninguno consulta OpenAI ni utiliza su credencial.

## Gmail y ledger

```bash
# Sustituir 12345 por el ID nuevo de la compra QA.
docker compose exec -T app php artisan local-qa:ledger 12345
docker compose exec -T app php artisan local-qa:status --ready
```

Revisar una sola fila automática `purchase_confirmation`, `accepted`, `attempts=1`,
`transport_status=accepted`, UUID de snapshot e ID SMTP. `content_kind` será `auto`
únicamente si AUTO válido terminó antes del plazo; en los otros casos será `original`.
`accepted` acredita aceptación SMTP, no entrega en el buzón.
Los hashes HTML/texto corresponden al snapshot cifrado fijado; los reintentos conservan ese contenido.
En Gmail verificar un único mensaje «Gracias por tu orden de Laboratorio en Famedic»
para la compra, destinatario exacto, cita si procede, estudios/paquetes y preparación completa.
En «Mostrar original», comprobar partes HTML y texto y el Message-ID del ledger.
Esperar al menos dos minutos después de terminar preparación y verificar que no llegue
«Tu resumen de indicaciones ya está disponible».
Gmail puede agrupar correos: contar mensajes en la conversación, no sólo conversaciones.

Si falta trabajo de confirmación, con workers QA activos y configuración consistente,
reconciliar SOLAMENTE el ID QA nuevo:

```bash
read -r -p 'Fingerprint actual del navegador: ' QA_FP
docker compose exec -T app php artisan local-qa:reconcile 12345 --fingerprint="$QA_FP"
```

No ejecutar `laboratory-confirmations:reconcile` general en QA. El comando focal nunca repite
fulfillment, cobro ni GDA. Un estado `ambiguous` exige inspeccionar Gmail/Message-ID;
no reintentar, borrar la fila o crear un reenvío para resolver la incertidumbre.

## Pausa y recuperación

1. No efectuar más compras. Esperar a que termine el envío en curso si es posible.
2. Pulsar Ctrl+C en AMBAS terminales de workers y comprobar que sus procesos finalizaron.
3. Dejar `LOCAL_CHECKOUT_QA_ENABLED=true` y el destinatario exacto para conservar las protecciones.
4. Establecer ambas puertas `LAB_CONFIRMATION_COORDINATOR_ENABLED=false` y
   `LAB_CONFIRMATION_COORDINATOR_RELEASE_READY=false`; ejecutar `config:clear`.
5. Consultar `local-qa:ledger ID` y `local-qa:status`. No borrar intenciones ni frontera.
   Un corte durante SMTP puede dejar entrega ambigua; no existe garantía de cancelación de un envío iniciado.
6. Para reanudar pendientes, conservar QA, destinatario y la MISMA fecha/frontera de la cohorte;
   habilitar ambas puertas, limpiar configuración, comparar huellas y usar sólo workers QA
   y reconciliación focal. La fecha nueva sólo se exige en el primer arranque con tablas vacías.

Apagar las puertas no devuelve la cohorte al correo legacy ni autoriza a cambiar la frontera.
No deshabilitar el modo QA ni arrancar colas generales hasta revisar que no queden jobs o
intenciones de prueba pendientes. No eliminar los dos jobs históricos de `automation`.

## Resultados automatizados

Validación final: 122 PHP PASS, 591 aserciones (sin contar ejecuciones repetidas).
- Seguridad QA: 15 PASS / 44 aserciones.
- Motor y redactor: 43 PASS / 235 aserciones, incluidos los cuatro escenarios QA.
- Coordinador: 43 PASS / 198 aserciones.
- GDA/P0: 21 PASS / 114 aserciones.

El motor completo y GDA/P0 se ejecutaron en MySQL descartable del Docker existente;
seguridad QA y coordinador también pasan en SQLite aislado. El intento del motor completo
en SQLite se descartó porque una migración preexistente ajena a este bloque consulta
`information_schema.TABLE_CONSTRAINTS`; no se modificó esa migración.
No se iniciaron workers ni se envió correo real. No hubo cambios JS; no requiere nuevo build.
PHP lint y git diff --check pasan.

Estado habitual comprobado al finalizar: 2,341 compras; GDA NULL 1,313, confirmed 1,027,
uncertain 1; cero confirmaciones y fronteras; dos jobs automation; cero workers/scheduler.
QA, coordinador y RELEASE_READY permanecen efectivamente apagados.
Preflight con configuración de preparación y destinatario QA autorizado: ok=true,
validado originalmente mediante variables de proceso CLI temporales.
La regresión específica del ajuste de navegación se detalla en la entrega correspondiente.

Regresión del ajuste de navegación: 76 PHP PASS / 582 aserciones: QA 19/65, coordinador 43/198 y monitor 14/319.
Login PHP-FPM HTTP 200, sin CSP ni encabezado QA agregados; CSP de respuesta preexistente
y redirecciones preservadas incluso con QA habilitado en pruebas aisladas.
Compras habituales 2,341; confirmaciones y fronteras 0; jobs automation 2.
