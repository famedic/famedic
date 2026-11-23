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

`release-source.json` debe contener `active_sha` leído del release activo y,
opcionalmente, `template_sha`. `inventory.json` debe contener el namespace
`akubica-uat-batch2-a4-v1` y recursos con `id`, `namespace`, `owner` y
`purpose`. El generador rechaza SHA distinto, namespace ajeno, recursos sin
procedencia y cualquier ID local. El resultado queda marcado
`id_source=runtime-inventory`.

Antes de custodiar credenciales, el runner QA exige `--release-source`,
comprueba el SHA contra el plan, valida la selección exacta, assertions,
host/HTTPS/flags y exige `--inventory-command` cuando exista un DELETE.
El helper de inventario recibe sólo `{namespace, resource_id, purpose, owner}`
y debe devolver una confirmación exacta. Los DELETE dinámicos usan el ID de la
respuesta del POST; si el POST no creó un recurso, su DELETE se omite con
`RESOURCE_NOT_CREATED`.

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
