# IT-0061 — preparación local A4

## Alcance

Preparación reversible, sólo para `akubica-uat-batch2-a4-v1`, de un ítem
`laboratory_cart_items` OLAB cuyo customer es `membership_none`. No añade
`cart:laboratory:write` a Leo, no modifica los ítems base y no emite accesos,
ligas, pagos ni llamadas externas.

## Comandos

```text
NS=akubica-uat-batch2-a4-v1
SCENARIO=functional-window-2-continuity
CONFIRM=akubica-a4-continuity-cart
ACCESS_CONFIRM=akubica-uat-batch2-a4-v1
SHA=$(printf '%s' '{"action":"identity"}' | php artisan functional-window2:inventory --no-ansi | jq -er '.active_sha')
php artisan akubica:a4-continuity-cart --dry-run --namespace="$NS" --no-ansi
php artisan akubica:uat-access --dry-run --namespace="$NS" --scenario="$SCENARIO" --no-ansi
APPLY_JSON=$(php artisan akubica:a4-continuity-cart --apply --namespace="$NS" --confirm="$CONFIRM" --no-ansi)
ITEM_ID=$(printf '%s' "$APPLY_JSON" | jq -er '.resource_id')
REGISTER_JSON=$(printf '%s' "{\"action\":\"register\",\"namespace\":\"$NS\",\"resource_id\":$ITEM_ID,\"purpose\":\"cart-item-created-by-A4-continuity\",\"source_case\":\"A4\",\"owner\":\"customer:membership_none\",\"expected_sha\":\"$SHA\"}" | php artisan functional-window2:inventory --no-ansi)
php artisan akubica:uat-access --apply --namespace="$NS" --scenario="$SCENARIO" --confirm="$ACCESS_CONFIRM" --no-ansi
printf '%s' "{\"action\":\"verify\",\"namespace\":\"$NS\",\"resource_id\":$ITEM_ID,\"purpose\":\"cart-item-created-by-A4-continuity\",\"owner\":\"customer:membership_none\",\"expected_sha\":\"$SHA\"}" | php artisan functional-window2:inventory --no-ansi
printf '%s' "{\"action\":\"read\",\"scenario\":\"$SCENARIO\",\"namespace\":\"$NS\",\"aliases\":[\"machine_leo\",\"leo_membership_none_olab\"]}" | php artisan functional-window2:custody --private --no-ansi | python3 -c 'import json,sys; print(json.dumps({"aliases":sorted(json.load(sys.stdin))}))'
python3 scripts/functional_window2_http.py plan.qa.operativo.json --mode qa --base-url https://staging.famedic.com.mx --release-source release-source.json --inventory-command "bash -lc 'php artisan functional-window2:inventory --no-ansi'" --credential-command "bash -lc 'php artisan functional-window2:custody --private --no-ansi'" --output evidence/a4-continuity.sanitized.json
php artisan akubica:a4-continuity-cart --cleanup --namespace="$NS" --confirm="$CONFIRM" --no-ansi
printf '%s' "{\"action\":\"unregister\",\"namespace\":\"$NS\",\"resource_id\":$ITEM_ID,\"purpose\":\"cart-item-created-by-A4-continuity\",\"owner\":\"customer:membership_none\",\"expected_sha\":\"$SHA\"}" | php artisan functional-window2:inventory --no-ansi
printf '%s' "{\"action\":\"revoke\",\"scenario\":\"$SCENARIO\",\"namespace\":\"$NS\",\"aliases\":[\"machine_leo\",\"leo_membership_none_olab\"]}" | php artisan functional-window2:custody --no-ansi
php artisan akubica:uat-access --reset --namespace="$NS" --scenario="$SCENARIO" --confirm="$ACCESS_CONFIRM" --no-ansi
```

El runner real recibe el plan JSON y sus opciones son las mostradas arriba;
no se autoriza sustituirlas por una interfaz inventada. El ID se
captura de la salida `apply`, se registra y se verifica antes de cualquier
DELETE. `expected_sha` debe provenir de `REVISION`/`release-source` verificado.

## Guardas y assertions

- entorno `testing`/`staging`; namespace y manifest dedicado activos;
- customer `membership_none`, ownership del ítem y marca `olab`;
- GDA determinista `RC2-A4-CONTINUITY-<namespace>-OLAB`;
- segunda ejecución `apply` devuelve el mismo recurso y no crea otra línea;
- inventario verifica propósito, propietario, tipo, procedencia y SHA;
- perfil Leo conserva sólo las capacidades ya aprobadas, incluyendo lectura
  de laboratorio y `checkout:prepare`, nunca escritura;
- `prepare` debe devolver HTTPS first-party, URL presente y expiración no
  mayor de 10 minutos, sin payment link ni capacidad de pago;
- cleanup elimina sólo el ítem registrado, verifica su ausencia y luego
  desregistra; revoke/reset dejan cero PAT temporales y estado ausente;
- ownership, marca, namespace, ID o propósito ajenos se rechazan sin DELETE.

El consumo de la liga en sesión web first-party permanece fuera de esta
validación local; no se simula como PASS.

## Evidencia local

- comando/preparación y endpoint Laravel/MySQL: `2 tests / 17 assertions`, código 0;
- inventory register/verify/unregister A4 en MySQL sintético: `3 assertions`,
  código 0; el runner mostró la advertencia histórica `file_get_contents`;
- lint PHP y `git diff --check`: PASS;
- QA histórico sin continuidad se conserva como FAIL; esta preparación local
  no reclasifica ese resultado ni certifica A4.

## Handoff separado: B24 y dependencias externas

| Bloque | Precondición y selección | Acción del operador/tercero | Canal y autorización pendiente |
|---|---|---|---|
| B24 / A4 médico | Titular `membership_none` dedicado con sesión web first-party en `staging.famedic.com.mx`; B24 usa `GET /api/v1/checkout/prepare?service_line=medical_services` y A4 usa la ruta de laboratorio con `brand=olab`; assertions de HTTPS, un uso, TTL y prohibición de pago | Abrir la liga en el navegador del mismo titular; no compartir cookies con el runner | Operador QA coordina sesión por navegador controlado; falta disponibilidad/confirmación del titular y autorización de la ventana con efecto web |
| OTP / step-up | Identidad sintética, challenge y código entregado por el titular; endpoints de request/verify y step-up definidos por rev7 | El titular recibe y dicta el código por el canal aprobado; operador sólo introduce el valor en el formulario seguro | Canal seguro de OTP; falta autorización explícita de SMS/OTP reales y ventana temporal |
| ATIX | `POST /integrations/atix/phone-lookups` con HMAC, `X-Correlation-ID`, `AtixIdempotencyKey` y datos sintéticos | Proporcionar la autoridad/clave HMAC desde el gestor seguro y confirmar el sandbox | Responsable ATIX/QA por vault o canal de secretos; falta autorización de llamadas externas |
| R02/R03 | Pedido propio dedicado, IDs dinámicos, ownership y, cuando aplique, challenge de step-up; no usar R02b/R03b incidentales | Proporcionar los fixtures/resultado/factura dedicados y confirmar retención | QA/Identidad por el procedimiento de fixtures; falta evidencia/OTP si se prueba step-up |
| R05 | Sesión FAMEDIC first-party y fixture de cita/concierge dedicado; `family-only` no crea citas y no sustituye R05 | Producto/QA debe aprobar el escenario y proporcionar la cita/concierge por el preparador soportado | Canal de control QA/Producto; falta autorización de R05 y datos dedicados |
| R08 | Decisión formal de alcance para UAT conversacional, sesión/canal y retención; no asumir exclusión por similitud | Responsable UAT define conversación sintética, acceso y periodo de retención | Canal UAT/Control; falta decisión documentada y autorización de datos |

Ningún bloque de esta tabla se ejecutó en esta corrección local.
