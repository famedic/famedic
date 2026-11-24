# IT-0061 — generación segura del plan operativo QA

`functional-window-2-qa-plan.json` es la plantilla versionada. No se ejecuta
directamente en QA: contiene referencias sintéticas para la doble local y no
es evidencia de un plan operativo.

Generar un plan operativo requiere tres entradas sanitizadas:

```text
python3 scripts/functional_window2_plan.py \
  docs/Akubica/v1.3.0/functional-window-2-qa-plan.json \
  inventory.json release-source.json \
  --expected-sha <SHA-40> \
  --output plan.qa.operativo.json
```

En QA, `release-source.json` debe generarse desde el release Laravel activo,
no desde el checkout del runner. El release debe exponer un metadato verificable
(`AKUBICA_UAT_RELEASE_METADATA_PATH`, `REVISION` o `release.json`) con un SHA
completo. La generación soportada es:

```text
php artisan functional-window2:inventory --no-ansi < identity-request.json
python3 scripts/functional_window2_release_source.py \
  --inventory-command "php artisan functional-window2:inventory --no-ansi" \
  --expected-sha <SHA-40> --output release-source.json
```

Si el runner está separado, el `--inventory-command` debe ser un puente SSH
privado que ejecute el mismo comando en el release; no debe pasar secretos por
argumentos ni tomar el `HEAD` local como evidencia. `release-source.json` debe
contener `active_sha` leído del release activo y,
opcionalmente, `template_sha`. `inventory.json` debe contener el namespace
`akubica-uat-batch2-a4-v1` y recursos con `id`, `namespace`, `owner` y
`purpose`. El generador rechaza SHA distinto, namespace ajeno, recursos sin
procedencia y cualquier ID local. El resultado queda marcado
`id_source=runtime-inventory`.

Antes de custodiar credenciales, el runner QA exige `--release-source`,
comprueba el SHA contra el plan, valida la selección exacta, assertions,
host/HTTPS/flags y exige `--inventory-command` cuando exista un DELETE.
El helper Laravel de inventario se instala en el release y se invoca como
`php artisan functional-window2:inventory --no-ansi`. Recibe por stdin JSON
`{action:verify,namespace,resource_id,purpose,owner,expected_sha}` y consulta
MySQL, el manifest activo, customer, línea de laboratorio y marca `olab`.
Devuelve únicamente una confirmación sanitizada. Rechaza namespace, tipo,
ownership, propósito, SHA o recurso que no correspondan. Los DELETE dinámicos
usan el ID de la respuesta del POST; antes de cada DELETE el runner exige esa
confirmación y, si el POST no creó un recurso, lo omite con
`RESOURCE_NOT_CREATED`.

El comando no modifica datos. `functional-window2:inventory` sólo es fuente de
verdad QA cuando corre junto al release y su MySQL; el helper Python/SQLite se
conserva exclusivamente para pruebas sintéticas locales.

Instalación: publicar el comando y registrar `FunctionalWindow2InventoryCommand`
en `bootstrap/app.php`, conservar `release_metadata_path` en la configuración y
recargar la caché de configuración mediante el procedimiento normal del release.
No requiere migraciones nuevas. En un runner separado sólo se instala Python y
las dependencias del runner; el comando Laravel permanece en el servidor de
aplicación y el canal privado transporta únicamente JSON sanitizado y secretos
en memoria del custody helper.

## Generación de `REVISION` en cada release Forge

El script de despliegue debe ejecutar este bloque desde la raíz física del
release recién clonado, después de comprobar que el checkout terminó y antes
de activar el enlace `current`:

```sh
release_root=$(pwd -P)
deployed_sha=$(git -C "$release_root" rev-parse HEAD)
case "$deployed_sha" in
  [0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-Fa-F]) ;;
  *) echo 'Invalid deployed SHA' >&2; exit 1 ;;
esac
printf '%s\n' "$deployed_sha" > "$release_root/.REVISION.tmp"
chmod 0644 "$release_root/.REVISION.tmp"
mv -f "$release_root/.REVISION.tmp" "$release_root/REVISION"
test "$(cat "$release_root/REVISION")" = "$deployed_sha"
```

El bloque no recibe el SHA por variable externa ni lo fija en el repositorio;
la fuente es el commit realmente desplegado. La activación de `current` debe
ocurrir después de la última comprobación. El helper rechaza el release si el
archivo falta, no contiene 40 hexadecimales o no coincide con `current`.

Ejemplo de ejecución posterior a un precheck exitoso:

```text
python3 scripts/functional_window2_http.py plan.qa.operativo.json \
  --mode qa --base-url https://famedic-otp.on-forge.com \
  --release-source release-source.json \
  --inventory-command /ruta/al/inventory-helper \
  --credential-command /ruta/al/custody-helper \
  --output evidence/functional-window-2.sanitized.json
```

El cambio es de runner/generación y requiere publicar esos componentes antes
de una nueva corrida QA. No cambia runtime funcional, OpenAPI, rev7 ni flags.
