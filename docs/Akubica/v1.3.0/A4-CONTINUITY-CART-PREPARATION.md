# IT-0061 — preparación local A4

## Alcance

Preparación reversible, sólo para `akubica-uat-batch2-a4-v1`, de un ítem
`laboratory_cart_items` OLAB cuyo customer es `membership_none`. No añade
`cart:laboratory:write` a Leo, no modifica los ítems base y no emite accesos,
ligas, pagos ni llamadas externas.

## Comandos

```text
php artisan akubica:a4-continuity-cart --dry-run --namespace=akubica-uat-batch2-a4-v1
php artisan akubica:a4-continuity-cart --apply --namespace=akubica-uat-batch2-a4-v1 --confirm=akubica-a4-continuity-cart
printf '{"action":"register","namespace":"akubica-uat-batch2-a4-v1","resource_id":<ID>,"purpose":"cart-item-created-by-A4-continuity","source_case":"A4","owner":"customer:membership_none","expected_sha":"<SHA_VERIFICADO>"}' | php artisan functional-window2:inventory
printf '{"action":"verify",...}' | php artisan functional-window2:inventory
printf '{"action":"read","scenario":"functional-window-2-continuity","namespace":"akubica-uat-batch2-a4-v1","aliases":["machine_leo","leo_membership_none_olab"]}' | php artisan functional-window2:custody --no-ansi
python3 scripts/functional_window2_http.py plan.qa.operativo.json --mode qa --base-url https://staging.famedic.com.mx --release-source release-source.json --inventory-command "bash -lc 'php artisan functional-window2:inventory --no-ansi'" --credential-command "bash -lc 'php artisan functional-window2:custody --no-ansi'" --output evidence/a4-continuity.sanitized.json
php artisan akubica:a4-continuity-cart --cleanup --namespace=akubica-uat-batch2-a4-v1 --confirm=akubica-a4-continuity-cart
printf '{"action":"unregister",...}' | php artisan functional-window2:inventory
printf '{"action":"revoke","scenario":"functional-window-2-continuity","namespace":"akubica-uat-batch2-a4-v1","aliases":["machine_leo","leo_membership_none_olab"]}' | php artisan functional-window2:custody --no-ansi
php artisan akubica:uat-access --reset --namespace=akubica-uat-batch2-a4-v1
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
