# Fase 2A Diseño técnico de la API de beneficios opacos

Estado: **PROPUESTA PARA APROBACIÓN, SIN IMPLEMENTACIÓN**. Estas tres rutas no están registradas ni añadidas al OpenAPI validado de 67 operaciones. Prefijo tentativo `/api/v1`. No generar SDK ni tratar este diseño como contrato publicado. D01–D10 y el aislamiento/consentimiento deben aprobarse primero.

## Invariantes y representación

Solo carrito médico activo propio, moneda MXN y un beneficio. Aplicar significa guardar una selección de preview; retirar significa quitarla del cálculo. Ninguna operación reserva fondos, genera compra, activa membresía, crea link de pago o consume saldo. La selección no garantiza disponibilidad para compra futura.

El usuario sale de Sanctum y el consumidor de una identidad de servidor vinculada al PAT y al canal. Se rechazan user_id, customer_id, coupon_id, code, brand, requested_amount_cents, consent=true y campos desconocidos. Referencias opacas no sustituyen autenticación ni ownership. Todos los objetos nuevos cierran propiedades y declaran required, tipos y enums; sin escapes de tipo data={} ni nullable heredado. Los centavos son enteros JSON no negativos dentro del límite monetario aprobado por Finanzas y del rango del almacenamiento; ese máximo numérico es un bloqueo de contrato antes de codificar.

Formato propuesto de referencia: prefijo de tipo `crt_`, `bnf_`, `app_`, `cnf_`, `ver_` o `cur_` seguido de 43 caracteres base64url sin padding que representan 32 bytes aleatorios. Validación por tipo, longitud y decodificación canónica; regex con anclaje absoluto, sin trim de controles ni aceptar salto final. El prefijo indica tipo, no contiene IDs ni firmas reversibles. Guardar SHA-256 de referencias aleatorias y vínculos internos; no almacenar plaintext de referencias en logs. El recibo cifrado de idempotencia puede contener refs de respuesta y requiere acceso restringido.

`cart_ref`: estable por usuario+consumer+carrito médico activo; se invalida al cerrar/borrar carrito o revocar delegación. `cart_version`: token aleatorio de un snapshot autoritativo, no updated_at ni ID codificado. Mantener el mismo token mientras el snapshot permanezca idéntico, aunque se repita GET; emitir versión nueva al cambiarlo. Una revisión monotónica interna evita ABA (carrito A→B→A) incluso si el fingerprint vuelve a coincidir.

Para volver a emitir cart_ref, cart_version y application_ref estables en GET, un hash irreversible solo no basta. Se propone almacenar además su representación cifrada con cifrado autenticado y control de acceso; opciones/confirmaciones de un uso pueden conservar solo hash tras entregarse. Esta excepción explícita a la idea inicial «solo hashes» requiere aprobación D05. Nunca persistir referencias recuperables sin cifrado; no derivarlas de IDs como alternativa. Los recibos de idempotencia también se cifran y se tratan como datos sensibles restringidos.

Fingerprint de carrito: revisión, ítems/cantidades/precios vigentes, selección y montos, moneda, reglas/catálogo. Cada benefit_ref añade la fuente/asignación, remanente, actividad/aprobación y vigencia. Un cambio de una fuente no seleccionada invalida sus propias opciones/confirmaciones, sin necesidad de cambiar todos los carritos del usuario. Cambios relevantes en la fuente seleccionada invalidan también la selección efectiva y el snapshot. Volver a consultar no restaura referencias invalidadas.

## GET /cart/benefits

Query: `service_line=medical_services` obligatorio; `limit` entero entre 1 y 50, por defecto 20; `cursor` opaco opcional. No body. Autenticación y capacidad propuesta `benefits:read`. Cache-Control: no-store, private; sin caché compartida ni ETag que permita saltar ownership. X-Correlation-ID en todas las respuestas.

200 devuelve `success:true` y `data:BenefitsSnapshot`. Si no existe carrito activo o está vacío: 409 EMPTY_CART sin crearlo; el usuario debe preparar el carrito mediante un flujo FAMEDIC autorizado. GET calcula una vista consistente sin escribir negocio, sin consumo, sin eliminación de selección y sin auditoría de compra. Puede persistir referencias/snapshots técnicos con vencimiento. Repetir GET no invalida opciones previas que sigan dentro de su contexto/TTL.

| DTO | Campos obligatorios y tipos propuestos |
| --- | --- |
| BenefitsSnapshot | cart_ref:string, cart_version:string, service_line:const medical_services, rules_version:string, currency:const MXN, snapshot_expires_at:date-time UTC, benefits:BenefitOption[], applications:Application[], totals:Totals, pagination:Pagination, availability:const preview_only |
| BenefitOption | benefit_ref:string, benefit_type:coupon\|credit, display_label:string de catálogo controlado, estimated_amount_cents:integer>=0, max_applicable_cents:integer>=0, reference_expires_at:date-time UTC, restrictions:Restriction[] |
| Restriction | code:MINIMUM_PURCHASE\|SINGLE_BENEFIT_ONLY\|PREVIEW_NO_RESERVATION, message:string del catálogo aprobado |
| Application | application_ref:string, benefit_type:coupon\|credit, status:applied\|invalidated\|removed, confirmed_cents:integer>=0, applied_cents:integer>=0, currency:MXN, invalidation_reasons:InvalidationReason[] |
| InvalidationReason | code:CART_CHANGED\|BENEFIT_CHANGED\|BENEFIT_UNAVAILABLE\|RULES_CHANGED, message:string de catálogo |
| Totals | subtotal_cents, discount_cents, credit_applied_cents, total_cents, item_count:integer>=0; currency:MXN |
| Pagination | has_more:boolean, next_cursor:string\|null; ambos obligatorios y coherentes |

Se omite available_amount_cents de la opción inicial: para seleccionar basta el máximo aplicable a esta compra y se evita divulgar el saldo total innecesariamente. Es una reducción deliberada respecto de la propuesta inicial, pendiente D06. No devolver código, ID, correo, teléfono, nombre del dueño, campaña interna o descripción administrativa. estimated_amount_cents y max_applicable_cents coinciden con el monto automático propuesto para ese snapshot; no se acepta una cantidad elegida por Leo.

Solo se listan opciones propias elegibles. Con otra selección activa, GET puede mostrar opciones elegibles alternativas con SINGLE_BENEFIT_ONLY, pero apply sigue prohibido hasta retiro; no inventar elegibilidad de acumulación. La opción ya seleccionada se representa en applications, sin ofrecer reaplicación. GET devuelve cero o una aplicación actual, incluso invalidada; no lista historial retirado. Un preview invalidado tiene applied_cents=0 y conserva confirmed_cents para explicar el cambio. Los totales se calculan excluyéndolo.

El cursor liga consumer, usuario, carrito, versión, orden interno estable y vencimiento del snapshot de cinco minutos. No contiene IDs codificados; referencias emitidas en páginas posteriores no pueden vencer después del snapshot. Cursor ajeno/desconocido: 404 BENEFIT_CURSOR_NOT_FOUND; propio vencido: 409 BENEFIT_CURSOR_EXPIRED; contexto cambiado: 409 CART_VERSION_CONFLICT. Ante incoherencia de lectura, repetir snapshot internamente de forma acotada y fallar 503 si no puede garantizarse coherencia; no mezclar páginas/versiones ni retornar total de otra revisión.

## POST /cart/benefits/applications

Headers: Bearer, Content-Type application/json, Idempotency-Key obligatorio (propuesta: 16–128 caracteres ASCII `[A-Za-z0-9_-]`, sin controles; no usa semántica de secreto). Capacidad `benefits:apply`. Body cerrado, todos obligatorios:

```json
{
  "service_line": "medical_services",
  "cart_ref": "{{cart_ref}}",
  "cart_version": "{{cart_version}}",
  "benefit_ref": "{{benefit_ref}}",
  "confirmation_ref": "{{confirmation_ref}}"
}
```

Los placeholders de este documento son ilustrativos, no fixtures que pasen el formato real de referencia.

Revalidar referencia, usuario, consumidor, delegación, carrito activo, TTL, snapshot, asignación, reglas, saldo y consentimiento. Si la oferta, importe o totales ya no coinciden, rechazar; nunca actualizar monto silenciosamente usando la confirmación vieja. Si todo coincide, crear una aplicación y la selección médica, consumir el recibo de confirmación, invalidar las opciones ligadas a la versión anterior y emitir nueva versión dentro de una transacción.

201 devuelve `success:true`, data con campos obligatorios: cart_ref, cart_version, service_line, application:Application(status applied), totals:Totals, availability:preview_only. El monto se clasifica exclusivamente en discount_cents o credit_applied_cents. Total=max(0,subtotal-descuento-crédito). No hay datos de pago ni resultado de compra. El replay devuelve el mismo 201/body y añade Idempotency-Replayed:true; no crea otra aplicación. La cabecera de correlación conserva la identidad del recibo original conforme al patrón existente.

## POST /cart/benefits/applications/{application_ref}/remove

Capacidad `benefits:remove`, misma política de cabeceras. Body cerrado obligatorio: service_line, cart_ref, cart_version, confirmation_ref. No benefit_ref ni monto. Confirmación específica de retiro, ligada a la aplicación actual y al efecto sobre totales. Permitir retirar una aplicación invalidada con permiso y consentimiento nuevos.

200 devuelve `success:true`, data con cart_ref, nueva cart_version, service_line, removed:true, application:Application(status removed,applied_cents 0), totals:Totals, availability:preview_only. Conservar application_ref y confirmed_cents en el recibo/historial. Una aplicación retirada admite replay exacto de su operación original; otra intención devuelve 409 BENEFIT_APPLICATION_NOT_ACTIVE, no simula un nuevo retiro. Aplicación desconocida/ajena devuelve el mismo 404 BENEFIT_APPLICATION_NOT_FOUND. Retirar no devuelve dinero ni cambia ledger.

## Estados y expiración

| Entidad | Transiciones propuestas | Condición |
| --- | --- | --- |
| Opción | issued → used / expired / invalidated | Uso exitoso, cinco minutos o cambio de contexto. No reutilizable con clave nueva. |
| Confirmación | issued → consumed / expired / invalidated | Un efecto concreto; TTL dos minutos o vencimiento previo de opción/contexto. |
| Aplicación | applied → removed / invalidated; invalidated → removed | Revalidación determina estado efectivo. Nunca vuelve a applied sin nueva aplicación y consentimiento. |
| Idempotencia | processing → completed / failed_final / failed_uncertain | Resultado duradero con reconciliación del efecto y recibo. |

TTL se evalúa con reloj UTC del servidor: now>=expires_at significa referencia expirada. Esto aplica a las nuevas referencias, sin alterar la comparación histórica del vencimiento de cupones. La selección no caduca a los cinco minutos: caduca la opción de aplicarla. Tombstones de refs y aplicaciones retiradas sobreviven como mínimo la ventana idempotente de 24h; retención de auditoría propuesta 90 días, pendiente Privacidad. No usar expiración física/cron como única validación.

## Idempotencia y concurrencia

Orden propuesto: autenticar y autorizar consumer/capacidad → validar forma → buscar recibo en namespace usuario+consumer+operación+clave → si es replay exacto autorizado devolverlo → para primera ejecución, resolver ownership/contexto, adquirir bloqueos y revalidar → efecto+confirmación+recibo en commit único. El replay de éxito puede devolver la versión histórica del resultado dentro de 24h aunque la opción/confirmación hayan vencido; es un recibo, no la vista actual: reconsultar GET. Revocación de token/delegación o prohibición de consumidor siempre impide replay.

Fingerprint incluye ruta normalizada y parámetros (incluido application_ref), todos los campos del body, API/rules policy y consumer autenticado. El namespace no debe agrupar indiscriminadamente clientes del mismo customer. Misma clave/payload diferente: 409 IDEMPOTENCY_KEY_CONFLICT. En proceso: 409 IDEMPOTENCY_REQUEST_IN_PROGRESS con Retry-After. Incierto: 409 IDEMPOTENCY_OPERATION_UNCERTAIN, retryable=false y reconciliación interna/GET, sin instruir a Leo a usar una clave nueva a ciegas. El registro de aplicación incorpora identificador único de intención para impedir duplicar efecto después de expirar el caché.

No envolver sin cambios el middleware opcional existente. El flujo nuevo rechaza ausencia de clave y servicio idempotente no disponible; no hereda bypass por flag/multipart. Los 4xx finales admiten replay de la intención fallida; resolver un error y cambiar body exige una intención/clave nueva.

Para todos los escritores médicos, incluidos los legacy autorizados: transacción, serialización por carrito y revisión monotónica, cálculo consistente y mismo protocolo de invalidación. Cuando se bloqueen fuente/asignación, acordar orden global antes de implementar y compatibilizarlo con consumo/revocación; no invertirlo entre rutas nuevas. Las mutaciones externas de saldo/estado se detectan mediante fingerprint bajo bloqueo, no solo por revisión de carrito. Una reserva de fondos no se introduce. Dos previews pueden coexistir en distintos contextos; solo una compra definitiva puede consumir el mismo recurso según la política financiera pendiente.

Caída después de commit pero antes de HTTP debe recuperar el mismo recibo; caída previa no deja selección ni confirmación consumida. Deadlock: reintento acotado de transacción sin duplicación y sin activar servicios externos en el bloque reintentado. Agotamiento: 503, sin éxito inventado. Estas son pruebas futuras obligatorias, no garantías ya implementadas.

## Errores exactos propuestos

Envelope compatible con ApiResponse: success=false; error.code, message de catálogo, retryable:boolean y correlation_id obligatorios. fields solo para validación y details solo de catálogo autorizado. X-Correlation-ID coincide con el cuerpo. Los ejemplos nuevos deberán tipar cada respuesta por código, no aprobar un esquema genérico que tolere cualquier error.

| HTTP | Código | Condición y acción para Leo | retryable |
| --- | --- | --- | --- |
| 401 | UNAUTHENTICATED | Bearer inválido/revocado/ausente; autenticar de nuevo. | false |
| 403 | FORBIDDEN | Usuario autenticado sin perfil customer; conservar el rechazo del middleware actual. | false |
| 403 | CONSUMER_NOT_AUTHORIZED | Consumidor/delegación/capacidad/canal no admitido. | false |
| 403 | LEO_PAYMENT_LINK_FORBIDDEN | Intento Leo de crear payment link, incluso replay. | false |
| 404 | CART_REFERENCE_NOT_FOUND | Carrito desconocido o ajeno. | false |
| 404 | BENEFIT_REFERENCE_NOT_FOUND | Opción desconocida o ajena. | false |
| 404 | BENEFIT_APPLICATION_NOT_FOUND | Aplicación desconocida o ajena. | false |
| 404 | CONFIRMATION_REFERENCE_NOT_FOUND | Recibo desconocido o ajeno. | false |
| 404 | BENEFIT_CURSOR_NOT_FOUND | Cursor desconocido o ajeno. | false |
| 409 | EMPTY_CART | No hay carrito activo con ítems. Preparar en plataforma. | false |
| 409 | CART_SERVICE_LINE_MISMATCH | Valor de línea reconocido pero fuera del alcance médico. | false |
| 409 | CART_VERSION_CONFLICT | Reconsultar y obtener consentimiento nuevo. | false |
| 409 | BENEFIT_REFERENCE_EXPIRED | Referencia propia vencida; reconsultar. | false |
| 409 | BENEFIT_CURSOR_EXPIRED | Reiniciar paginación con snapshot nuevo. | false |
| 409 | BENEFIT_NOT_ELIGIBLE | Regla/fuente/asignación ya no permite aplicar. | false |
| 409 | BENEFIT_ALREADY_APPLIED | Requiere retiro explícito antes de otra aplicación. | false |
| 409 | BENEFIT_REFERENCE_ALREADY_USED | Otra intención intenta reutilizar opción consumida. | false |
| 409 | BENEFIT_APPLICATION_NOT_ACTIVE | Aplicación propia ya retirada. | false |
| 409 | CONFIRMATION_EXPIRED | Recibo propio vencido; nueva confirmación. | false |
| 409 | CONFIRMATION_CONTEXT_CHANGED | Operación, monto, totales, versión o reglas distintos. | false |
| 409 | CONFIRMATION_ALREADY_USED | Fuera del replay original autorizado. | false |
| 409 | IDEMPOTENCY_KEY_CONFLICT | Misma clave con payload diferente; corregir intención. | false |
| 409 | IDEMPOTENCY_REQUEST_IN_PROGRESS | Esperar Retry-After y repetir misma petición/clave. | true |
| 409 | IDEMPOTENCY_OPERATION_UNCERTAIN | Reconciliar; no repetir con clave nueva automáticamente. | false |
| 415 | UNSUPPORTED_MEDIA_TYPE | Solo JSON en escrituras; no multipart. | false |
| 422 | CONFIRMATION_REQUIRED | Falta confirmation_ref; obtener aprobación first party. | false |
| 422 | IDEMPOTENCY_KEY_REQUIRED | Falta encabezado obligatorio. | false |
| 422 | VALIDATION_ERROR | Tipos/formato/campos extra o valores no reconocidos. | false |
| 429 | TOO_MANY_REQUESTS | Esperar Retry-After; mismo contexto solo si sigue vigente. | true |
| 503 | BENEFIT_SERVICE_UNAVAILABLE | No se garantiza cálculo/almacenamiento/recibo. Reconsultar estado antes de nuevo intento. | true |

Precedencia: autenticación → autorización de consumidor/pagos → tipo de contenido y forma (clave ausente, confirmación ausente, resto) → replay autorizado → ownership (carrito, opción/aplicación, confirmación) → TTL → versión/contexto → elegibilidad → exclusión → commit. Rate limit puede anticipar los errores de dominio después de identificar actor. En todos los 404 ocultar si existe o a quién pertenece. Solo un recurso propio verificable puede producir errores específicos de expiración/uso. Limitar detalles y evitar diferencias groseras de tiempos por rutas de búsqueda; no prometer igualdad temporal absoluta sin medición.

## Compatibilidad y pruebas pendientes

Las rutas legacy, sus operationId, DTO y códigos para consumidores autorizados se conservan. El adaptador nuevo usa la fuente interna resuelta por referencia; no la vuelve a buscar por code, que puede repetirse entre asignaciones. Los escritores legacy médicos deben invalidar snapshots/confirmaciones sin cambiar sus respuestas. Leo no obtiene acceso a rutas legacy que evadan opacidad o consentimiento; su migración es explícita, no una sustitución silenciosa de payload.

Una selección creada por legacy se asocia a una aplicación duradera durante la adopción aprobada de datos o por un adaptador transaccional antes de habilitar mutaciones opacas. El estado de selección pertenece al usuario/carrito; cada consumidor autorizado recibe su propio alias application_ref, sin reutilizar la referencia del otro. Conservar auditoría del productor original. Un retiro legacy invalida esa aplicación y sus consentimientos; nunca recrear la selección desde un recibo viejo. El GET nuevo no hace este backfill de negocio de forma oculta.

Las pruebas N01–N26 de la matriz, P01–P12 de identidad, fixtures de los tres DTO, casos de cada HTTP/código, snapshots de centavos y carreras reales forman los criterios para Fase 2B. Actualmente los endpoints no existen: ningún negativo ni positivo de estas nuevas rutas se considera ejecutado o PASS.
