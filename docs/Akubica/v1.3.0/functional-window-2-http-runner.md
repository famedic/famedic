# Runner HTTP local de functional-window-2

## Diagnóstico

La preparación anterior sólo derivaba una colección y sanitizaba un reporte Newman. No existía un executor que recibiera la selección exacta, rechazara un destino no controlado, preservara el orden físico, devolviera un código de salida fiable y ejecutara cleanup ante fallo o interrupción. El runner histórico tampoco tenía un adaptador seguro para pasar credenciales temporales y su limpieza Artisan no podía ser acoplada de forma demostrable al ciclo HTTP.

La corrección local es `scripts/functional_window2_http.py`. Recibe un plan JSON derivado, no secretos ni una carpeta completa de Newman. En local sólo admite `http://127.0.0.1`, `localhost` o `::1`; rechaza QA y cualquier otro host hasta que exista un adaptador seguro revisado. No sigue redirects, valida método/ruta/duplicados/HTTP esperado, corta ante un request fallido, conserva el código de salida y ejecuta la lista de cleanup en `finally`, incluso ante timeout o Ctrl-C. La salida contiene únicamente alias de request, método, ruta, estado, duración y códigos de error sanitizados.

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

