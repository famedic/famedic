# Inventory helper de IT-0061

El release QA observado previamente contiene `functional-window2:custody`,
pero no contiene un inventory helper equivalente. El helper nuevo es un
componente del host del runner, no una ruta HTTP ni un comando que maneje
credenciales.

En local sintético usa SQLite y deriva el SHA con `git rev-parse`. Para QA,
la implementación equivalente debe ejecutarse en el host controlado que tenga
acceso de sólo lectura a la BD y al release activo; debe obtener el SHA desde
`current`/metadatos del release y consultar el inventario dedicado. Debe
rechazar namespace, ownership, propósito o SHA distintos.

El helper sólo acepta `action=verify` y devuelve una confirmación sanitizada.
No crea, actualiza ni elimina filas.
