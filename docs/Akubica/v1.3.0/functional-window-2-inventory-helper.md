# Inventory helper de IT-0061

El release QA observado previamente contiene `functional-window2:custody`,
pero no contiene un inventory helper equivalente. El helper nuevo es un
componente del host del runner, no una ruta HTTP ni un comando que maneje
credenciales.

En local sintético, el helper Python/SQLite y `git rev-parse` sólo sirven para
probar el runner. Para QA, el adaptador real es
`functional-window2:inventory`, ejecutado junto al release Laravel y su MySQL;
obtiene el SHA desde `current`/metadatos del release y consulta el manifest,
customer, línea de laboratorio y marca del recurso. Debe rechazar namespace,
ownership, tipo, propósito, expiración o SHA distintos.

Acepta por stdin `action=identity` para generar `release-source.json` y
`action=verify` para autorizar un DELETE. Devuelve sólo JSON sanitizado y no
crea, actualiza ni elimina filas. El runner debe invocarlo por proceso local en
el host Laravel o por un puente privado; nunca usa el HEAD del checkout del
runner como SHA desplegado.
