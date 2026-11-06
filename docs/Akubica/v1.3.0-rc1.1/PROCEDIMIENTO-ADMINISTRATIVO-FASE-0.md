# Fase 0 — procedimiento administrativo restringido

Estado: **preparado, no ejecutado**. Solo un administrador expresamente autorizado puede realizar estas acciones en staging. No reutilizar la credencial en compras, carrito ni otras operaciones de negocio. No incluir su valor, su hash, IDs de usuario o datos personales en tickets, logs o constancias distribuibles.

## Identificación privada del incidente

El origen es el reporte Newman histórico inventariado en `FASE-0-SEGURIDAD.md`. Leer ese archivo existente bajo acceso restringido y conservar sus bytes. Decodificar en memoria la respuesta exitosa de verify-code; obtener el Bearer sin copiarlo al portapapeles, línea de comandos, archivo temporal, historial o pantalla. No inferir la credencial a partir de un ID o nombre de usuario.

La integración afectada corresponde a **ATIX sandbox / consumidor Leo del flujo phone-lookups**. Su selección está en `config/atix.php`, `sandbox.keys`, alimentada por `ATIX_SANDBOX_INTEGRATION_KEY_ID` y `ATIX_SANDBOX_HMAC_SECRET`. Los pares `atix_integration_key_id` y `atix_hmac_secret` conservados en el mismo reporte permiten al administrador identificar privadamente la pareja exacta. No se reproduce aquí el identificador ni el secreto. La coincidencia con la configuración efectiva de staging debe comprobarse en el almacén/configuración autorizado, sin mostrarlos.

## Revocación exclusiva del PAT

1. Confirmar host, app y base de datos de staging por metadatos administrativos independientes. Usar sesión segura sin grabación, debug, query logging con bindings ni historial de entradas. Cerrar si el entorno no es inequívoco. Este documento no autoriza operar producción.
2. Mantener en memoria el PAT obtenido del reporte. Resolverlo con `Laravel\Sanctum\PersonalAccessToken::findToken($valorEnMemoria)`. La implementación de `vendor/laravel/sanctum/src/PersonalAccessToken.php` verifica el hash de la parte secreta; no basta con buscar el segmento ID, propietario o nombre del PAT.
3. Verificar privadamente correspondencia: origen de evidencia, modelo/propietario esperado, nombre y abilities de emisión Akúbica y fechas del incidente. No imprimir el modelo: Eloquent puede serializar atributos personales. Si no existe, registrar «registro ausente» y continuar con comprobación de rechazo; no afirmar que esta operación lo revocó.
4. En una transacción, volver a cargar solo la fila resuelta con `whereKey($pat->getKey())->lockForUpdate()->first()`. Repetir la verificación del hash con `hash_equals` y verificar modelo/propietario esperado. Si cualquier dato difiere, abortar sin eliminar.
5. Eliminar exclusivamente esa fila, con una condición por clave y hash verificado; exigir una fila afectada. No ejecutar `tokens()->delete()`, borrados por usuario, nombre, truncado ni revocaciones masivas. Si no se obtiene exactamente una eliminación, revisar privadamente el resultado/concurrencia; no ampliar el filtro.
6. Confirmar fuera de la transacción que `PersonalAccessToken::findToken($valorEnMemoria)` devuelve null y que la fila ya no existe. Eliminar referencias a la credencial en memoria al terminar. No imprimir el objeto ni excepciones que puedan incluir valores.
7. Con autorización para una comprobación HTTP, usar una solicitud privada de solo lectura para confirmar HTTP 401 con el PAT afectado; no usar endpoints de negocio mutables. Evitar logs de Authorization/response y argumentos de proceso. Un token de control de otra sesión debe conservar acceso; no es necesario emitirlo ni revocarlo como parte del incidente.
8. Guardar constancia sin secretos: entorno, fecha, rol administrador, hash del archivo de evidencia, resultado «una fila revocada» o «ya ausente», rechazo confirmado, control de otra sesión y referencia interna restringida al incidente. Si ya expiró, no omitir la comprobación ni declarar que desapareció la exposición de copias.

Existe alternativa de API: `DELETE /api/v1/auth/token`, `app/Http/Controllers/Api/V1/Auth/TokenController.php`, que elimina el token actual o usa findToken. Puede rechazarse antes por expiración o guards. Su `revoked=true` no demuestra por sí solo cuál registro se eliminó; para este incidente se prefiere el procedimiento de registro y verificación descrito.

No se incluyó un script que conecte a DB o ejecute borrados automáticamente. La consola/ejecutor del administrador debe haber sido revisado para no guardar entradas sensibles. Ante fallos, registrar únicamente categoría y resultado; investigar detalles en canal restringido.

## Rotación coordinada HMAC

1. Identificar privadamente la pareja exacta en el reporte existente y su configuración efectiva; confirmar que corresponde al sandbox de staging y al consumidor esperado. No rotar APP_KEY, todas las integraciones o los PAT de otros usuarios.
2. Suspender distribución del paquete comprometido e inventariar destinatarios y copias remotas por canales autorizados. No reenviar el reporte para avisar. Si existe riesgo activo, acordar suspensión temporal de esa integración concreta antes de una transición; requiere decisión administrativa.
3. Acordar ventana de cambio entre responsables de FAMEDIC y ATIX. Generar el reemplazo solo dentro de su gestor de secretos autorizado, por el administrador, fuera de esta ejecución. Elegir identificador nuevo cuando el procedimiento de la integración lo permita. Nunca enviarlo por correo/chat ordinario, argumentos o Postman distribuible.
4. Actualizar de forma coordinada el productor de firmas y `ATIX_SANDBOX_INTEGRATION_KEY_ID` / `ATIX_SANDBOX_HMAC_SECRET` del servidor correcto. Revisar configuración cacheada y mecanismo de recarga aprobado. La configuración local actual admite una pareja por estas variables; no asumir doble clave de transición sin soporte probado. Programar cambio coordinado o suspensión breve en lugar de agregar soporte funcional sin revisión.
5. Probar una solicitud de integración no destructiva de alcance autorizado con firma nueva, timestamps y nonce frescos. Si el lookup crea contexto, usar exclusivamente cuenta controlada y autorización expresa para ese efecto; no usar personas reales ni generar OTP incidentalmente.
6. Confirmar rechazo de la clave anterior mediante intento controlado sin operaciones de negocio; actualmente el servicio devuelve 401 ATIX_INTEGRATION_UNAUTHORIZED cuando el identificador no está configurado, o ATIX_SIGNATURE_INVALID si la firma ya no coincide. Usar la expectativa exacta correspondiente al cambio elegido. No aceptar cualquier fallo como prueba de rotación.
7. Retirar el material anterior del gestor/configuración activa y procesos de ejecución; revisar reportes y entornos generados después. Conservar la evidencia original del incidente restringida según retención, sin borrado indiscriminado.
8. Registrar constancia segura de integración lógica, entorno, ventana, rol responsable, aceptación de nueva firma, rechazo de la anterior y confirmación del consumidor. No registrar valor/hash del secreto ni identificador privado en la constancia distribuible.

## Acciones pendientes y cierre

| Acción del administrador | Verificación exigida | Estado |
|---|---|---|
| Resolver y revocar PAT exclusivamente | findToken, correspondencia, una fila o ausencia previa documentada | PENDIENTE |
| Verificar rechazo y aislamiento de otras sesiones | 401 afectado y acceso intacto de token de control | PENDIENTE |
| Identificar configuración efectiva de integración | Coincidencia privada con reporte y origen staging | PENDIENTE |
| Rotar/retirar clave afectada | Nueva firma aceptada y anterior rechazada con código esperado | PENDIENTE |
| Inventariar destinatarios/copias fuera del equipo | Retiro de distribución y restricciones verificadas | PENDIENTE |
| Atestar revisión de PII y build de staging | Constancia real; no usar info.version como build | PENDIENTE |

El archivo futuro `SECURITY-GATE-v1.3.0-rc1.1.json` solo puede afirmar `pat_revocation_verified`, `hmac_containment_verified`, `manual_pii_review_completed` y `staging_build_verified` después de reunir constancias verificables. No existe en esta entrega un gate real con esos valores aprobados. Los ejemplos verdaderos dentro de pruebas automatizadas son exclusivamente fixtures temporales, no aprobaciones administrativas.

**Fase 0 permanece abierta hasta la confirmación del administrador. Esperar revisión; no iniciar Fase 1 automáticamente.**
