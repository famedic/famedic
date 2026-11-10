# Colección y entorno offline RC2

Importar la colección y el entorno de ejemplo. Los únicos valores de entorno son URLs del dominio reservado rc11.invalid; no hay secretos, cookies ni respuestas guardadas. Los valores `{{vault:...}}` son referencias vacías que deberá aprovisionar un operador autorizado en almacenamiento local después de certificación; no son credenciales de ejemplo reutilizables.

La colección cubre las 74 operaciones principales y las cuatro web R30, con casos alternativos positivos/negativos. Los perfiles/capacidades son los de MATRIZ-ENDPOINTS, no un permiso general para Leo. Las solicitudes first-party son modelos de revisión manual en la sesión FAMEDIC: no importar una cookie de usuario a un agente. `_token` usa la referencia local first_party_csrf; authorize_payment=yes sólo puede remitirse tras la decisión directa del usuario.

El guard de colección siempre cambia el destino a rc11.invalid/blocked y omite la petición; sin API skipRequest lanza un error. No existe variable approval/enable para desbloquear. No editarlo para simular autorización: la edición ejecutable debe emitirse tras las aprobaciones y revisión de transporte/UAT.

Ejecutar `node preflight.cjs` desde este directorio. Comprueba estructura, cobertura por operationId/método/ruta, parámetros requeridos, schemas declarados, referencias vacías, scripts y guard en tres modos sin realizar red. No equivale a validación oficial de Postman ni a pruebas HTTP/UAT; el resultado oficial se informa por separado en MANIFIESTO.

Las plantillas con Vault no son ejemplos JSON ya resueltos: obtener sus valores dinámicos de la operación anterior. Los ejemplos positivos tipados están en OPENAPI; los negativos verificados están en MANIFIESTO/ESQUEMAS.json. Un código HTTP genérico, 404 o un test de estructura no acredita éxito funcional.
