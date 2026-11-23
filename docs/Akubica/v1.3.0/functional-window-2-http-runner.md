# Runner HTTP local de functional-window-2

## Diagnóstico

La preparación anterior sólo derivaba una colección y sanitizaba un reporte Newman. No existía un executor que recibiera la selección exacta, rechazara un destino no controlado, preservara el orden físico, devolviera un código de salida fiable y ejecutara cleanup ante fallo o interrupción. El runner histórico tampoco tenía un adaptador seguro para pasar credenciales temporales y su limpieza Artisan no podía ser acoplada de forma demostrable al ciclo HTTP.

La corrección local es `scripts/functional_window2_http.py`. Recibe un plan JSON derivado, no secretos ni una carpeta completa de Newman. En local sólo admite `http://127.0.0.1`, `localhost` o `::1`; rechaza QA y cualquier otro host hasta que exista un adaptador seguro revisado. No sigue redirects, valida método/ruta/duplicados/HTTP esperado, corta ante un request fallido, conserva el código de salida y ejecuta la lista de cleanup en `finally`, incluso ante timeout o Ctrl-C. La salida contiene únicamente alias de request, método, ruta, estado, duración y códigos de error sanitizados.

El modo `--mode qa` ya está preparado pero no se ejecutó: exige HTTPS, sin puerto alternativo, uno de los hosts cerrados `staging.famedic.com.mx` o `famedic-otp.on-forge.com`, SHA de 40 hexadecimales, `target_env=staging`, TLS verificado y banderas exactas `"0"`/`"1"`. Las escrituras requieren `qa_writes_authorized="1"`; B25 requiere además `leo_negative_writes_authorized="1"`. El modo valida estas guardas antes de abrir una conexión. No existe opción `--insecure` ni seguimiento de redirects.

Si cualquier cleanup falla, la salida contiene `cleanup_status=CLEANUP_FAILED` y el proceso termina con código no exitoso. Un plan QA con escrituras sin lista de cleanup es rechazado antes de abrir la conexión.

El plan QA futuro debe ser generado por una interfaz soportada y aportar un adaptador explícito de credenciales y cleanup del namespace. No se permite poner Bearer, PAT, headers ni secretos en el plan o en la evidencia.

## Comprobación local

```text
wsl bash -lc "cd '/mnt/c/.../famedic-a4-wt' && python3 -m unittest scripts.tests.test_functional_window2 scripts.tests.test_functional_window2_http -v"
git diff --check
```

Resultado: 12 tests PASS, código de salida 0, `git diff --check` PASS.

La suite usa un `ThreadingHTTPServer` sintético y comprueba:

- orden exacto y ausencia de requests extra;
- cleanup después de una respuesta HTTP inesperada;
- cleanup después de timeout;
- rechazo de destino no local;
- cleanup fallido explícito con código no exitoso;
- ausencia de secretos en la salida proyectada.

## Matriz de la segunda ventana

| Caso | Estado local | Precondición o bloqueo | Corrección pendiente |
|---|---|---|---|
| B03/B03r | ejecutable con plan HTTP | fixture de precio dedicado y perfil permitido | adaptar el plan seguro al executor QA |
| B06a/B06b/B06r | ejecutable con plan HTTP | recurso mutable dedicado, readback y cleanup de carrito | adaptador QA de cleanup verificable |
| B07 | ejecutable con plan HTTP | endpoint sin integración externa | adaptar plan y observar efectos |
| B09 | bloqueado | native; bearer `membership_regular`; customer/delegación/binding de ese titular; `memberships:read`; ownership | aprovisionador debe emitir la identidad individual sin ampliar permisos |
| B14 | bloqueado | native; mismo titular activo; `cart:read` y `cart:membership:write`; customer, binding, ownership y `olab` coherentes | fixture/credencial individual soportada |
| B19/B20/B21/B21r | ejecutable condicionado | native primary, customer propio, delegación, binding `olab`, capacidades literales y carrito dedicado | readback y cleanup QA del carrito |
| B21x | bloqueado | Leo dedicado con binding que carezca de `cart:laboratory:write`, manteniendo las demás capacidades | aprovisionar binding negativo soportado; no quitar permiso del operador existente |
| B22 | ejecutable con plan HTTP | estado pharmacy suspendido y endpoint sin proveedor externo | adaptar plan QA |
| B25 | bloqueado | Leo; bearer `none`; `machine_credential`; `lab_brand`; bandera negativa exacta; verificación de 403 antes de efectos | emitir identidad `none` dedicada o mantener bloqueado |
| R01/R01b | bloqueado | pedidos propios y ajenos dedicados, ownership y IDs existentes | materializador soportado para pedidos sintéticos |
| R04 | bloqueado | familiares dedicados y relación con el perfil Leo | fixture soportado de familiares |
| R06 | ejecutable condicionado | endpoint de observabilidad sin integración externa | adaptar plan y conservar correlation ID sanitizado |
| R07a/R07b | ejecutable condicionado | payload inválido/sin bearer; validación antes de efectos | adaptar plan; no convertir 403 previo en PASS de regla de negocio |
| R07a2 | bloqueado | Leo sin `item_type` y sin capacidad no autorizada; binding negativo dedicado | aprovisionar perfil negativo soportado |

`B01`, `B01r`, `B02`, `B04` y `B05` siguen bloqueados por ausencia de preparación QA soportada. A4, OpenAPI, rev7 y `akubica-uat-v1` no se modifican.

La preparación sintética de los bloqueos se describe en `functional-window-2-preconditions.synthetic.json` y se valida offline con `functional_window2_preconditions.py`. Representa relaciones y capacidades literales para `membership_regular`, `leo_none`, el binding Leo sin `cart:laboratory:write`, pedidos propios/ajenos y familiares. No crea credenciales ni pretende demostrar preparación QA. Para QA aún se requiere que el aprovisionador exponga esas variantes, conserve ownership y permita reset/revocación verificables.

## Adaptador de custodia local

El runner admite `--credential-command <programa>`. El programa no recibe secretos
por argumentos: recibe por stdin JSON con los aliases solicitados y devuelve por
stdout únicamente un objeto JSON alias→secreto. El runner captura stdout/stderr,
usa los valores sólo en memoria para construir `Authorization: Bearer` y
`X-Api-Consumer-Credential`, y nunca copia secretos al reporte, logs, archivos
temporales o mensajes de error. El helper debe ser un mecanismo seguro existente;
un script de prueba sintético no acredita custodia QA. Al finalizar el bloque
HTTP, el runner llama al mismo helper con `{"action":"revoke","aliases":[...]}`
y exige una confirmación sanitizada de aliases revocados; si falla, el resultado
es `CLEANUP_FAILED` y código no exitoso.

Cada request puede declarar `auth.bearer_alias`, `auth.machine_alias` y
`assertions` (`required_paths`, `forbidden_paths`, `equals`). El status HTTP y las
assertions deben pasar; un status esperado con envelope incorrecto es FAIL.
La selección puede declarar `selection.authorized_ids` y
`selection.expected_request_count`; se rechazan IDs no autorizados, duplicados o
un conteo distinto.

Ejemplo sin valores secretos:

```text
python3 scripts/functional_window2_http.py plan.qa.json \
  --mode qa --base-url https://famedic-otp.on-forge.com \
  --output evidence/functional-window-2.sanitized.json \
  --credential-command /ruta/al/helper-de-custodia
```

El helper implementado sobre la custodia Laravel es el comando privado
`php artisan functional-window2:custody --no-ansi`. Se ejecuta en la máquina
que contiene el release Laravel, APP_KEY y la conexión de la BD; su
autenticación es el control de acceso del proceso/SSH y la lectura de la
custodia local de Laravel, no una ruta HTTP. Recibe sólo JSON por stdin y
responde JSON por stdout; no acepta aliases por argumentos, rechaza stdout
interactivo y sólo permite los cuatro aliases de este escenario:
`machine_native`, `machine_leo`, `native_membership_regular` y
`leo_membership_none`.

Antes de leer o revocar exige namespace y escenario exactos, estado vigente,
referencias de PAT existentes y material cifrado íntegro. `read` descifra sólo
en memoria. `revoke` elimina los PAT referenciados y consulta nuevamente la BD;
devuelve éxito únicamente si cada ID dejó de existir. No elimina consumers,
delegaciones ni bindings y no crea un endpoint público. Errores de framework,
SQL, ciphertext y secretos se proyectan sólo como códigos sanitizados.

El plan completo y ejecutable está en
`functional-window-2-qa-plan.json`: 22 requests físicos, dos requests de
cleanup, aliases, assertions, dependencias y efectos. `B01`, `B01r`, `B02`,
`B04` y `B05` están fuera de `selection.authorized_ids` y son rechazados si se
intenta incluirlos. R01/R01b son requests individuales; R07a/R07a2/R07b son
los tres físicos del agrupador R07. Los IDs numéricos del plan son IDs
deterministas del namespace dedicado de esta ventana, no recursos legacy.

Instalación/publicación: el comando PHP y `bootstrap/app.php` deben estar en
el release Laravel que ejecute el aprovisionador; el plan JSON, el runner
Python y su custodia ejecutan en el host controlado del runner. No se copia
`access-state.json`, PAT ni ciphertext al runner. Esta iteración sólo valida
localmente; todavía no publica ni instala nada en QA.

