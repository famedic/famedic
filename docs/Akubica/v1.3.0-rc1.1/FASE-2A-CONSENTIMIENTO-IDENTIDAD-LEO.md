# Fase 2A Consentimiento e identidad de Leo

Estado: **DISEÑO PROPUESTO, PENDIENTE DE APROBACIÓN**. Ningún mecanismo descrito está instalado ni probado como control efectivo. La revisión actual confirma que nombre de token, abilities comunes y assistant_can_pay=false no distinguen ni restringen suficientemente a Leo.

## Consentimiento verificable

Se propone una confirmación en interfaz first party FAMEDIC, con sesión del usuario independiente del Bearer delegado a Leo. Leo no recibe credenciales de esa sesión, no controla el texto ni puede emitir recibos. Una respuesta de chat, un booleano, un OTP de login o un evento enviado unilateralmente por Akúbica no acreditan consentimiento para esta acción.

Secuencia propuesta:

1. Leo consulta el snapshot médico y presenta la opción y monto devueltos por el servidor como preview. El usuario abre una pantalla FAMEDIC mediante un mecanismo de navegación aprobado, sin datos personales ni bearer en URL. Un intento de confirmación lleva un nonce de estado opaco y de un uso, ligado al consumidor y snapshot; un enlace de confirmación no es un enlace de pago.
2. FAMEDIC autentica al usuario en su canal independiente, resuelve los vínculos del nonce y calcula de nuevo la oferta. GET de la pantalla no aprueba nada. La pantalla muestra acción apply/remove, etiqueta controlada, monto, subtotal y total resultante en MXN, advertencia de que no hay compra ni reserva y efecto del retiro. El usuario puede cancelar o aprobar explícitamente.
3. Un POST first party protegido contra CSRF y orígenes no admitidos registra la aprobación; el recibo no se emite desde la API delegada de Leo. El servidor verifica usuario, consumer/delegación, revisión/fingerprint, fuente/aplicación, monto, moneda y vigencia. Si cambió algo, muestra nueva intención y exige nueva aprobación; no actualiza una aprobación existente.
4. El emisor guarda confirmation_hash, intención única, usuario/customer, consumer_id, cart_id/revisión y fingerprint, fuente/aplicación interna, operación, monto/totales, rules_version, versión del texto, instante de aprobación, expires_at y canal. Se propone TTL 120 segundos, limitado por expiración de la opción. No guardar transcripción de chat ni tokens. La retención restringida de 90 días es una propuesta de Privacidad, sin valor de aprobación jurídica.
5. Un callback servidor a servidor autenticado entrega confirmation_ref al backend autorizado de Leo para esa intención. Verificar destino registrado, identidad de ambos servicios, audiencia, nonce y expiración, con protección de replay. No colocar confirmation_ref en query string, historial de navegador, analítica o logs. El callback usa credencial/canal independiente del HMAC ATIX y no permite elegir consumer ni callback_url desde un body del usuario.
6. Apply/remove verifica y consume el recibo junto con el efecto y el recibo de idempotencia en una transacción. Un replay original devuelve el resultado; otro monto/operación/beneficio/carrito/consumer o una segunda intención no reutilizan consentimiento. Si se pierde la respuesta después de commit, la aplicación duradera permite recuperar el resultado sin repetir el efecto.

El mecanismo de creación de la intención y el handoff/callback es una dependencia explícita del canal first party; no se inventa una cuarta ruta pública aprobada. En Fase 2B debe diseñarse/prototiparse ese transporte con sus responsables antes de exponer apply/remove. Si Akúbica no puede recibir el recibo por un canal autenticado, mantener las mutaciones opacas deshabilitadas. No sustituirlo por «el usuario dijo sí» como atajo.

Para remove, se confirma el efecto actual, incluso si la aplicación quedó invalidada; no reutilizar la confirmación de apply. Si no hay cambio monetario por invalidación, la pantalla explica que se retira una selección sin liberar/devolver fondos. La cancelación de la pantalla no altera carrito ni saldo.

## Identidad de consumidor y delegación

Identidades distintas: usuario propietario, consumidor Leo/Akúbica y canal/sesión de ejecución. La autorización se calcula por intersección de delegación del usuario, capacidades del consumidor, permisos explícitos del PAT y restricciones de canal/operación. Ninguna capacidad del token puede ampliar una prohibición del consumidor.

| Alternativa | Evaluación |
| --- | --- |
| User-Agent, X-Client/consumer_id del body, flag assistant_can_pay | No fiables como autoridad; son datos que el solicitante puede cambiar. |
| Nombre del PAT o abilities akubica:write | Son comunes en la emisión actual; no identifican al software que hace la petición. |
| Nuevo nombre/ability Leo sin verificar la emisión | Insuficiente: un flujo público podría solicitar un token con otro nombre y eludir el veto. |
| Identidad de cliente verificada en emisión y petición + PAT vinculado | Alternativa recomendada. Requiere autenticación del cliente y clasificación persistida por servidor. |

Propuesta concreta: canal backend Leo autenticado por mTLS en el borde FAMEDIC, con certificado registrado y consumidor activo; el borde elimina cabeceras de identidad externas y transmite una identidad verificada por canal interno autenticado. La aplicación solo confía en atributos de ese borde, con origen validado, no en un header público del mismo nombre. Una variante con assertions firmadas puede estudiarse si mTLS no es viable; no queda aprobada como sustitución automática.

La emisión de PAT tras autenticar al usuario debe tomar consumer_id del contexto de cliente verificado, nunca del teléfono/código/body. Registrar vínculo token_id→consumer_id, user_id, delegación, capacidades, expiración y estado. No modificar la tabla Sanctum base si una tabla lateral permite el vínculo. No almacenar Bearer plaintext. La propuesta requiere revisar Login/Register y la renovación para que no exista una vía de emisión no clasificada que Leo pueda usar para hacerse pasar por otro cliente.

En cada petición, el PAT real, no TransientToken de sesión, debe tener vínculo vigente al mismo consumidor del canal autenticado. Sin vínculo o canal: denegar capacidades sensibles. Un token robado de un consumidor nativo no basta en el canal Leo: la identidad de transporte debe coincidir. El consumidor nativo autorizado también necesita un canal/binding verificable o una sesión web first party independiente con CSRF; limitarse a marcar su token como «native» dejaría una vía de bypass. La imposibilidad de prestar credenciales de terceros no se presume: minimizar custodia y separar canales.

No se clasifican tokens antiguos por sus valores, ni se usan credenciales comprometidas. Antes de activar el control se requiere inventario por metadatos y aprobación para emitir/revocar o vincular tokens legítimos. Tokens desconocidos no obtienen permiso de pago por compatibilidad implícita. Esa denegación puede afectar consumidores antiguos y exige una migración acordada; no se presenta como un cambio transparente.

## Restricción efectiva de payment links

Política propuesta, independiente de service_line y brand:

- Identidad Leo: **403 LEO_PAYMENT_LINK_FORBIDDEN**, siempre, incluso si PAT trae `*`, capacidad payment-link, usuario administrador o body intenta actuar como native.
- Consumidor desconocido, mismatch PAT/canal, delegación revocada o falta de permiso explícito: **403 CONSUMER_NOT_AUTHORIZED** (401 para Bearer inválido/revocado/ausente).
- Consumidor permitido: requiere permiso servidor `checkout:payment-link:create`, PAT explícitamente limitado y binding de canal; después se ejecuta la validación existente de ownership/readiness. No conceder la capacidad a Leo ni inferirla de akubica:write.

Orden obligatorio: auth → identificación y gate → idempotencia/replay → validaciones/readiness → acción. El gate también se comprueba en el servicio/acción mediante un contexto de autorización inmutable resuelto por servidor; la llamada directa no puede evitarlo. Validar estado de la delegación antes de devolver un recibo cacheado, incluyendo recibos de links creados antes de desplegar el gate. Evitar que una ruta alternativa, alias o action dispatcher permita la misma creación sin política.

La negativa no crea AkubicaCheckoutLink, no cambia checkout_step, no llama proveedores de pago, no genera URL/token ni escribe recibo de éxito. Puede registrar auditoría mínima de denegación sin secrets. No devolver EMPTY_CART/CHECKOUT_NOT_READY para el caso Leo: su 403 precede a esas reglas y evita un falso negativo por condiciones del carrito.

Para Leo se propone allowlist inicial de las tres operaciones opacas y solo las rutas adicionales expresamente revisadas. Denegar apply/remove legacy y endpoints de compra/pago/activación. GET cart/medical-benefit, cart y checkout/prepare actuales exponen IDs o selecciones mediante resources legacy: no habilitarlos para Leo bajo la promesa de opacidad sin una vista versionada aprobada. Conservar esas interfaces para clientes legacy autorizados. La preparación de ítems por Leo y la navegación end to end necesitan revisión de sus DTO; en el alcance inicial puede preparar el carrito el usuario en FAMEDIC.

## Pruebas negativas de aceptación de Fase 2B

**ESTADO: DISEÑADAS, NO EJECUTADAS.** No existe el gate nuevo; los tests actuales de beneficios no demuestran la prohibición de payment links. Cada caso usará identidades/keys sintéticas y una base local desechable autorizada, sin proveedores ni staging. El positivo debe demostrar que el harness sí alcanza la acción cuando corresponde.

| ID | Preparación y estímulo | Resultado exacto y aserciones |
| --- | --- | --- |
| P01 | PAT Leo válido, canal Leo, carrito laboratorio listo; POST payment-link con brand válido. | 403 LEO_PAYMENT_LINK_FORBIDDEN; cero links nuevos, acción/proveedor no invocados, draft sin cambios. |
| P02 | Mismo caso con todas las brands admitidas y body que omite o cambia service_line. | Mismo 403; prohibición independiente de línea y readiness. |
| P03 | PAT Leo con wildcard y con permiso de pago incluido por error. | Mismo 403; el deny de consumidor prevalece sobre abilities. |
| P04 | X-Client=native, consumer_id=native o User-Agent falsos manteniendo identidad verificada Leo. | Mismo 403; no modifica el contexto de autoridad. |
| P05 | Leo reutiliza Idempotency-Key de un link histórico propio, o coincide con la de otro consumidor del mismo usuario. | 403 Leo antes de replay; jamás responde URL ni body cifrado ajeno; namespace por consumer. |
| P06 | PAT nativo válido presentado por canal verificado Leo. | 403 CONSUMER_NOT_AUTHORIZED por mismatch; cero efectos. |
| P07 | PAT común antiguo/no clasificado, token con nombre akubica o ausencia de proof de cliente. | 403 CONSUMER_NOT_AUTHORIZED; sin default de permiso por legacy. |
| P08 | Sin Bearer, revocado, vencido o cookie de sesión sin PAT en ruta API. | 401 UNAUTHENTICATED; no usar sesión como elevación. |
| P09 | Leo intenta medical-benefit/coupon legacy, rutas alternas de generación o invocación de la acción sin autorización. | 403 por política; acción directa lanza denegación de dominio, sin generar link. Inventario de rutas cubre todos los entry points. |
| P10 | Revocar delegación/consumer después de obtener un recibo o antes de retry. | 403 CONSUMER_NOT_AUTHORIZED; nunca replay de éxito tras revocación. |
| P11 | Usuario Leo también tiene rol administrador y carrito vacío/no listo. | 403 LEO_PAYMENT_LINK_FORBIDDEN; rol y error de negocio no amplían permisos. |
| P12 | Control positivo separado: consumidor nativo registrado, canal/PAT concordantes y permiso explícito, carrito listo. | 200 conforme al contrato vigente, exactamente un link en primera ejecución y replay sin duplicación; proveedores reales reemplazados por fakes. Sin permiso: 403. |

Añadir pruebas del emisor: un solicitante sin autenticación de cliente no puede pedir consumer_id=native; renovación preserva audiencia; mTLS no reconocido/revocado no genera PAT; callback de consentimiento falso no crea recibo. Verificar denegaciones con HTTP+código exacto y ausencia de efectos, no aceptar cualquier 4xx ni usar un carrito inválido como única prueba de autorización.

## Aprobaciones y pendientes

Seguridad debe aprobar cliente/borde/binding y gestión de certificados; Integraciones el cambio de emisión y migración de clientes; Producto/Privacidad el texto/canal/retención de consentimiento; Pagos el gate y sus entradas alternativas. Fase 0 continúa abierta: rotación HMAC reportada por administrador, rechazo de clave anterior pendiente, revocación y comprobación del PAT comprometido pendientes. No se usan esas credenciales para diseñar o probar.
