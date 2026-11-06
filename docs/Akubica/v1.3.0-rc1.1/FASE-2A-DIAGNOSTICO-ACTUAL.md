# Fase 2A Diagnóstico del comportamiento actual

Fecha: 2026-11-05. Resultado: inspección estática del repositorio; no ejecución de Laravel ni consulta de datos desplegados. «Confirmado» significa demostrado por el código local citado. Las pruebas existentes se identifican como evidencia de intención y cobertura, sin atribuirles una ejecución nueva.

El carrito médico guarda una selección para calcular un precio previo. No reserva ni consume saldo. No se encontró un finalizador de compra médica que consuma esa selección; por ello la continuidad hacia una compra real necesita un diseño adicional. La API actual tampoco dispone de una identidad de consumidor Leo que permita imponer su restricción de pagos.

## Pertenencia y autorización

| Evidencia local | Comportamiento confirmado | Límite relevante |
| --- | --- | --- |
| `routes/api/v1.php:106`, `app/Http/Middleware/EnsureApiCustomer.php:12` | Las rutas de carrito/checkout exigen Sanctum y perfil customer. | El perfil customer identifica al usuario; no identifica al consumidor Leo. |
| `app/Services/Api/V1/MedicalServiceCartBenefitService.php:139` | Normaliza código con trim/mayúsculas y consulta `couponUsers.user_id` del usuario autenticado. | Cupón ajeno e inexistente no se encuentran. No se admite elegir otro usuario en este servicio. |
| `app/Services/CouponApplicationService.php:142` | La validación médica vuelve a buscar la asignación por coupon_id y user_id. | La pertenencia no depende de una política administrativa. |
| `app/Support/Api/V1/CartCouponSupport.php:31` | Laboratorio también limita el código a asignaciones del usuario. | La búsqueda inicial no filtra usados: la validación posterior decide el error de negocio. |
| `app/Policies/CouponPolicy.php:10` | La política de cupones controla administración mediante permisos. | No sustituye ownership del usuario ni es una política de beneficios opacos. |
| `app/Services/CouponService.php:691` | Una asignación de campaña puede crear un cupón hijo y una fila coupon_user. | El saldo se guarda en coupons.remaining_cents; no hay evidencia de una cartera agregada por usuario. |

Un cupón con varias asignaciones comparte la fila del cupón y su remanente: no puede suponerse que cada coupon_user tiene un saldo independiente. Hay que conservar este modelo hasta que Finanzas decida otra cosa. `getUserBalance()` suma tipos Balance y Coupon (`CouponService.php:34-52`); su nombre no demuestra que represente solamente saldo monetario ni un importe acumulable en una compra.

## Elegibilidad, vigencia y monto

`CouponApplicationService.php:165-227` exige actividad, aprobación Active, tipo Balance o Coupon, asignación sin used_at, remanente positivo, inicio de vigencia alcanzado, ausencia de vencimiento y compra mínima cubierta. `Coupon.php:63-89` considera válido exactamente valid_from y exactamente expires_at; vence cuando now es mayor a expires_at. Son comparaciones de instantes del servidor; el diseño nuevo no debe introducir redondeos de fecha al día.

En medicina, `validateMedicalServicePreview()` activa allowPartialBalance. El importe es `min(max(remaining_cents,0),max(subtotal_cents,0))` (`MedicalServiceCartBenefitService.php:152`), tanto para el cupón de importe como para saldo. Se etiqueta como descuento si es coupon y como crédito si es credit. No se encontró una regla de porcentaje en este flujo. Un subtotal no positivo produce EMPTY_CART antes de aplicar.

La presentación genérica de `CouponService.php:132` no es un catálogo médico autoritativo: suma opciones y usa reglas de checkout generales. No se debe reutilizar su «balance_too_large» para rechazar un saldo parcial médico ni presentar la suma como ahorro aplicable.

## Aplicación, retiro y efectos de lectura

`MedicalServiceCartBenefitService.php:25-78` inicia transacción, bloquea el carrito existente, valida cupón/asignación y guarda una fila de beneficio. Un cupón distinto al seleccionado produce BENEFIT_ALREADY_APPLIED; reaplicar el mismo puede recalcular. La migración `2026_10_21_120000_create_medical_service_cart_benefits_table.php:27` impone unique(cart_id). No hay acumulación cupón+saldo.

El retiro (`:81-113`) elimina la selección y recalcula; sin selección devuelve removed=false. No cambia remaining_cents, used_at ni crea CouponTransaction. La selección actual no conserva un historial de aplicaciones retiradas.

`MedicalServiceCartService.php:22-40` usa firstOrCreate y recalcula incluso al consultar el carrito activo. El GET legacy de beneficio puede crear el carrito, actualizar totales y poner una selección en cero. No es una lectura pura. `recalculate():135-174` atrapa cualquier Throwable de validación y lleva el importe a cero, conservando la fila. El resource filtra selecciones con importe cero (`MedicalServiceCartResource.php:29`). Inferencia: una selección invalidada puede quedar oculta al consumidor sin explicar su estado. Para el API nuevo se propone un cálculo explícito que distinga ineligibilidad de fallo de infraestructura.

## Momento real de consumo

| Flujo | Evidencia | Efecto confirmado |
| --- | --- | --- |
| Preview médico apply/remove/recalculate | Servicios médicos anteriores | Solo selección/totales; no reserva ni gasto. |
| Compra laboratorio | `CouponApplicationService.php:26-68`; `Actions/Laboratories/FulfillLaboratoryCartOrderAction.php:106-134` | Dentro de una transacción, el servicio bloquea cupón y asignación, valida, pone remaining_cents=0, marca used_at y crea CouponTransaction. La llamada de fulfillment aparece después de crear/procesar la compra y antes de commit; no demuestra por sí sola liquidación bancaria. |
| Compra farmacia | `CouponApplicationService.php:74-117` | Existe método de consumo análogo. La búsqueda estática en app no encontró un llamador: su uso real no queda acreditado. |
| Compra médica desde selección M2C | Búsqueda de referencias a MedicalServiceCartBenefit y validateMedicalServicePreview en app | No se identificó integración de consumo definitivo. `MedicalAttentionCheckoutController.php:14-36` prepara el checkout web con precio de configuración, sin enlazar el beneficio M2C en ese controlador. |

El consumo genérico pone el remanente completo en cero, incluso si el descuento calculado de un cupón es menor. No sirve como implementación automática del débito parcial médico. La fórmula y el punto de compra médica, reversos, transacciones y reconocimiento contable siguen pendientes de aprobación. «Aplicado al cálculo» nunca debe presentarse como «pagado» o «saldo gastado».

## Compras concurrentes

Hay bloqueo de carrito en apply/remove y en addBasicPlan. La unicidad de carrito es `(customer_id,service_line,status)` en la migración médica; la unicidad de beneficio impide dos filas por carrito. Si el carrito no existe, lockedCart delega a activeCart; no debe suponerse que esa creación ya proporciona la misma serialización que bloquear una fila existente.

La validación médica no bloquea cupón/asignación ni reserva fondos. Dos previsualizaciones pueden considerar disponible el mismo saldo. El consumo genérico bloquea cupón y después asignación, pero `CouponService.php:767-772` revoca en orden asignación→cupón. Esta diferencia puede provocar deadlocks; requiere estudiar orden global y reintentos acotados, no prometer ausencia de carreras por usar DB::transaction.

`MedicalServiceCartService.php:103-132` elimina un ítem y recalcula sin la misma transacción/bloqueo visible. GET también recalcula sin ese protocolo. La carrera de una mutación legacy con apply opaco no se resuelve bloqueando solo los endpoints nuevos. La invalidación/versionado debe cubrir todos los escritores autorizados, con pruebas en un motor que implemente bloqueos de fila. Una prueba secuencial o SQLite por sí sola no acredita esa propiedad.

## Idempotencia disponible

`EnforceIdempotencyKey.php:33-61` deja pasar la petición si el flag está desactivado, falta la clave o no puede resolver actor; también tiene una excepción para multipart. No equivale a idempotencia obligatoria. `config/api_v1.php:52` deja el flag OFF por defecto; no se inspeccionaron valores de staging.

`IdempotencyActorResolver.php:26-33` agrupa por customer, sin separar consumidores. `IdempotencyRequestHasher.php:15-46` incorpora método, patrón de ruta, parámetros y payload canónico; excluye Authorization y no incorpora identidad de consumidor. La tabla tiene unique(actor_key,method,path,key_hash). Las respuestas se guardan cifradas por el modelo (`IdempotencyRecord.php:45`), incluyendo JSON de respuesta, aunque no se guarda el body de petición.

Los estados son processing/completed/failed_final/failed_uncertain. Un 4xx se puede repetir; un 5xx o excepción es incierto. Existe conflicto de payload, lease y replay. `IdempotencyService.php:234` finaliza después de ejecutar la acción, dejando una ventana de caída entre efecto y recibo; begin no hace cumplir expires_at en el camino de lectura del registro existente. La limpieza es configurable. El flujo nuevo debe tener política propia de expiración, namespace de consumidor y reconciliación duradera; no se cambia la semántica legacy en esta fase.

## Exposición legacy y restricción de pagos

El resource médico expone cart_id, id de aplicación, coupon_id, code y descripción del cupón (`MedicalServiceCartResource.php:18`, `MedicalServiceCartBenefitResource.php:14-21`). Laboratorio expone code, value/remanente y descripción (`CartCouponSupport.php:77-91`). Esos payloads no deben alimentar los DTO opacos ni logs externos. No se certifica que una descripción administrativa carezca de PII.

`CartController.php:284-300` devuelve totales médicos con applied_benefits vacío aunque el resource del carrito sí pueda incluir una selección; no sirve como catálogo autoritativo nuevo. El clear actual (`:309`) delega al flujo de laboratorio por brand: no se identificó un clear médico en esa ruta. El protocolo futuro de revisión debe cubrir los escritores médicos reales y cualquier clear médico que se incorpore después, sin convertir este diseño en autorización para agregarlo.

La ruta POST checkout/payment-link tiene autenticación general e idempotencia, pero no gate por cliente (`routes/api/v1.php:142`). El FormRequest autoriza true. El controlador entrega customer/brand/TTL/token_id a la acción, que verifica readiness y crea AkubicaCheckoutLink; guardar created_by_token_id no autoriza. La rama médica de prepare devuelve assistant_can_pay=false, pero un Bearer puede intentar la ruta con brand de laboratorio. La prohibición no puede depender del service_line elegido por el cliente.

`IssueAkubicaTokenAction.php:25` emite nombre/abilities comunes de config/akubica.php. Las abilities son akubica:auth/read/write, sin identidad Leo ni permiso específico de payment links. La búsqueda estática no encontró tokenCan en app. `UseApiTokenGuard.php:15` evita usar guard web para estas rutas, pero no acredita una identidad de consumidor. Se propone aislamiento nuevo en el documento de consentimiento e identidad, pendiente de aprobación.

## Evidencia y aspectos no confirmados

Existen pruebas médicas aisladas de crédito parcial, exclusión, ownership, recálculo e idempotencia (`tests/Feature/Api/V1/AkubicaMedicalServicesCartM2CIsolatedTest.php:36-158`). Se leyeron; no se ejecutó Laravel, no se crearon bases de datos ni se ejecutaron migraciones. La evidencia de Fase 1C.2 conserva sus 84 regresiones históricas PASS, sin atribuir cobertura a endpoints aún inexistentes.

El usuario informa nueva rotación HMAC sandbox y limpieza de caché en staging. Se registra como comunicación administrativa, no como comprobación independiente. Siguen pendientes rechazo de la clave anterior y revocación/verificación del PAT comprometido. Fase 0 permanece ABIERTA.
