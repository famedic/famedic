# Fase 2B 1 Identidad actual y propuesta de capacidades

Estado: **BLOCKED para integración definitiva y cambios de autorización**. La petición autoriza diagnóstico y pruebas locales, pero condiciona instalar la política a un modelo de identidad aprobado. No existe esa aprobación expresa de emisión/vinculación/canal; D08/D09 siguen pendientes. No se atribuye aprobación a las propuestas de Fase 2A ni se asocia Leo al usuario final.

## Matriz de autenticación

| Elemento | Comportamiento actual demostrado por código | Reutilizable y propuesta | Pendiente y compatibilidad |
| --- | --- | --- | --- |
| Emisión API V1 legacy | LoginController::verifyCodeLegacy (`:280`) y RegisterController::verifyCodeLegacy (`:383`) llaman IssueAkubicaTokenAction tras verificar identidad del usuario. | Reutilizar la verificación OTP de usuario; añadir contexto de consumidor autenticado independiente. | No permitir elegir consumer_id desde JSON/header libre. No se cambia emisor ahora. |
| Emisión OTP P0A | AkubicaLoginOtpService::verify (`:298`) y AkubicaRegisterOtpService::verify (`:284`) llaman la misma acción. | Unificar vínculo obligatorio en todos los emisores/renovaciones que se aprueben, no solo en una rama. | Login/register alternativos sin clasificación podrían eludir el gate. La búsqueda en app/routes encontró una sola llamada createToken en código de aplicación, centralizada en la acción; tests/factories pueden emitir sintéticos. |
| Abilities | config/akubica.php:41 asigna akubica:auth/read/write. IssueAkubicaTokenAction:25 toma nombre/abilities desde servidor, no desde el body. | Mantener esos grants para clientes legacy inventariados; capacidades explícitas nuevas por operación. | Hoy payment-link no consulta abilities: un PAT vacío o read-only creó un link en pruebas locales. No asumir que read/write ya autoriza/restringe cada ruta. |
| Token y propietario | HasApiTokens::createToken guarda tokenable_type/id, hash SHA-256, abilities y expiración; Sanctum Guard resuelve tokenable y verifica vigencia/proveedor. | Reutilizar usuario propietario y lookup seguro del PAT. | tokenable es usuario, no consumidor. No confundir user_id, token_name o rol administrador con identidad Leo. |
| Wildcard | PersonalAccessToken::can (`:77`) devuelve true si abilities incluye `*`; createToken sin segundo argumento usa `['*']`. | Exigir política de consumidor antes de abilities; para pagos pedir grant literal autorizado y denegar Leo prioritariamente. | Agregar solo tokenCan(payment-link) dejaría pasar wildcard. Los helpers legacy de tests emiten wildcard; no prueban un cliente nativo verificado. |
| Bearer frente a sesión | UseApiTokenGuard intenta poner sanctum.guard=[]; la ruta usa auth:sanctum y api.customer. | Validar PAT persistido real y ordenar el guard antes de autenticación para la API, si se aprueba. | P08 sesión primero autenticada por POST /login y luego API sin Bearer produjo 500 INTERNAL_ERROR; el controlador accedió a id de TransientToken. Es una reproducción en proceso de test, no prueba de explotación desplegada. |
| Consumidor independiente | No se encontró registro de consumidor ni binding PAT→consumer en el flujo API V1. | Registro servidor, delegación y vínculo por token_id, usuario y consumidor con estado/expiración. | Sin modelo aprobado no es posible preparar una identidad Leo válida para P01–P06/P09–P12 sin inventarla. |
| Canal conversacional | ContactRecognitionContext y AccountContextTokenService ligan phone/conversation/event/channel/audience a un contexto de reconocimiento, con hashes y OTP. | Reutilizar vínculos de intención cuando sea pertinente; no convertirlos por analogía en autorización de pagos. | channel/audience de conversación no acreditan por sí solos el cliente que presenta cualquier PAT a checkout. No se encontró un enlace entre ese contexto y consumer_id del PAT. |
| ATIX HMAC | AtixHmacService autentica key_id, cuerpo, timestamp y nonce; fija atix.integration_key_hash tras firma válida. Su canonical path es phone-lookups y se aplica solo allí. | Patrón de firma/replay verificable como referencia técnica. | No sirve hoy como identidad Leo para login o payment-link. No ampliar su alcance ni reutilizar credenciales comprometidas/rotadas. Rotación informada no es verificación de rechazo de clave anterior. |
| Permisos de usuario | api.customer exige perfil cliente; CouponPolicy es administrativa. | Conservar ownership y permisos de usuario además de consumidor. | El mismo usuario puede usar web y Leo. Bloquear user_id prohibiría funciones legítimas del usuario y seguiría sin identificar al cliente. |
| Replay | EnforceIdempotencyKey antes del controlador puede devolver body persistido, sin ejecutar FormRequest/controlador/action. Actor autenticado se agrupa por customer. | Guard de consumidor en ruta antes de idempotencia; namespace por consumer para recibos nuevos. | No basta una comprobación en controlador/action. C02 demostró replay persistido con headers de consumidor distintos; esos headers no cambian autoridad. |

Código vendor inspeccionado localmente: Laravel 11.46.1, Sanctum 4.2.0, Pest 2.36.0 y PHPUnit 10.5.36; PHP CLI 8.5.4. No se descargaron dependencias. Las conclusiones anteriores se refieren a esa instalación, no a versiones desplegadas desconocidas.

## Separación de identidades propuesta

Usuario FAMEDIC es el propietario del carrito. Consumidor es el cliente registrado (Leo o cliente nativo autorizado). Canal es el transporte autenticado de ese cliente. PAT es la delegación de ese usuario a ese consumidor, con expiración y capacidades limitadas. La política de servidor combina esas identidades; ninguna procede de X-Consumer, X-Channel, X-Integration, User-Agent, nombre del PAT o un campo del body.

Se mantiene la recomendación de Fase 2A: autenticar al backend Leo mediante mTLS en el borde y vincular certificado/identidad verificada a un consumidor registrado. El borde elimina headers externos del mismo nombre y transmite el contexto por un canal interno autenticado. La aplicación verifica ese origen y concordancia con el binding del PAT. Alternativas de assertions firmadas requieren aprobación distinta; no se implementan como fallback tácito.

La emisión tras OTP debe registrar token_id único, consumer_id, user_id, delegación, capacidades efectivas, policy_version, emisión/expiración/revocación y vínculo de canal. Un token de usuario no puede pedir convertirse en native, ni la falta de metadata debe convertirlo en native por defecto. La aplicación puede obtener usuario del PAT y consumidor del binding sin leer el Bearer plaintext persistido.

## Capacidades candidatas

Para conservar el prefijo actual se propone el siguiente namespace. **No son abilities instaladas ni aprobadas**; son más específicas que akubica:read/write y no sustituyen ownership.

| Capacidad propuesta | Leo | Consumidor nativo registrado | Aplicación futura |
| --- | --- | --- | --- |
| akubica:cart:read | Solo DTO/rutas revisados | Según grant explícito | No habilitar lectura legacy que revele IDs por inferencia. |
| akubica:cart:write | Pendiente revisión específica | Según permisos existentes clasificados | No amplía esta fase a writers de carrito. |
| akubica:benefits:read | Solo interfaz opaca futura | Según grant | No autoriza GET legacy. |
| akubica:benefits:apply / remove | Solo futuro flujo consentido | Según grant | Sin endpoints opacos implementados en esta fase. |
| akubica:checkout:prepare | Solo respuesta compatible revisada | Según grant | assistant_can_pay sigue siendo presentación. |
| akubica:payment-link:create | **DENEGADA incluso con wildcard** | Solo consumidor/canal permitido y grant explícito | Gate de ruta y acción, antes de replay/readiness. |

Propuesta de decisión de servidor: PAT real/válido + usuario/customer admitido + consumidor activo + binding/canal concordantes + delegación vigente + operación autorizada. Para pagos, denegación Leo prioritaria y permiso explícito del consumidor; no considerar `*` suficiente para elevar pagos. No modificar globalmente el comportamiento de todas las rutas checkout.

## Tokens existentes y cambios de esquema

No se puede exigir de inmediato metadata inexistente y, al mismo tiempo, prometer que todos los PAT actuales seguirán pagando. Esta incompatibilidad requiere inventario y decisión de Integraciones/Seguridad. P07 codifica la política propuesta fail-closed para no clasificados y falla contra el comportamiento actual; no es una prueba de que un cliente legacy hoy legítimo deba revocarse sin revisión.

Propuesta de esquema, **sin archivos de migración generados ni aplicación**: api_consumers (identidad/canal, estado/capacidades/policy_version), api_token_consumer_bindings (token_id único, consumer_id/user_id, delegación/expiración/revocación). Definir FK o estrategia de invalidación al borrar PAT y evitar reutilización de token_id. Backfill solo por metadatos aprobados y prueba de cliente, nunca por «todo token akubica es nativo». Emisión/renovación y cierre de vías alternativas se prueban antes de habilitar. No se emitieron tokens reales: todos los tokens de prueba vivieron exclusivamente en SQLite :memory:.

## Ajuste preciso pendiente

Una vez aprobado el modelo, el punto viable es middleware específico de las siete rutas seleccionadas después de autenticación y resolución de consumidor, **antes** de EnforceIdempotencyKey, API audit de éxito y cualquier controlador. Verificar orden resuelto por el router, no solo posición escrita de aliases. La acción de creación también debe exigir un contexto de autorización resuelto por servidor para llamadas directas; esa defensa complementa, no reemplaza, la barrera anterior al replay.

Para el caso TransientToken, revisar y probar la prioridad de UseApiTokenGuard respecto a Authenticate y rechazar explícitamente tokens no persistidos antes de llegar a CheckoutController:168. No se corrigió globalmente esa autenticación en esta fase condicionada. La arquitectura sí permite interponer el gate antes de replay; el bloqueo actual es aprobación/emisión/binding y compatibilidad, no una imposibilidad técnica demostrada.

El inventario local del router en `FASE-2B1-RUTAS-AUTH.json` contiene 67 rutas API V1, 52 con auth:sanctum. Para payment-link el orden resuelto es Authenticate:sanctum → SubstituteBindings → ForceJsonResponse → AssignAkubicaCorrelationId → UseApiTokenGuard → EnsureApiCustomer → EnforceIdempotencyKey. Por tanto Authenticate se ejecuta antes del middleware que intenta deshabilitar el guard web; el orden escrito de aliases no basta. Ajuste propuesto: hacer efectivo UseApiTokenGuard antes de Authenticate en estas rutas y poner resolución/gate de consumidor después de autenticación pero antes de EnforceIdempotencyKey, con pruebas de orden y sesión/Bearer. El inventario no utilizó requests externos ni credenciales.

Pendientes concretos: Seguridad aprueba mecanismo de cliente/canal y deny; Integraciones identifica consumidores y migración de PAT; propietario de pagos aprueba grants y tratamiento de links/recibos previos; Producto/API aprueban D08 y códigos por ruta. Hasta entonces: implementación definitiva BLOCKED, sin nuevo middleware, policy, binding, issuer o migración funcional.
