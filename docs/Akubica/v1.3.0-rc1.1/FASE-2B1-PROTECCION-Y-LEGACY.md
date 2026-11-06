# Fase 2B 1 Matriz de payment links y aislamiento legacy

Estado: propuesta técnica pendiente de modelo de identidad aprobado y D08. **Ningún gate de servidor fue instalado.** Las pruebas locales confirman que no se puede declarar a Leo protegido mediante las abilities actuales, headers o banderas de presentación.

## Cadena real de payment links

| Punto | Código inspeccionado | Comportamiento actual y ajuste propuesto |
| --- | --- | --- |
| Ruta | routes/api/v1.php:106,142 | Auth Sanctum + customer; idempotencia de escritura. Añadir gate solo a las rutas seleccionadas tras aprobar identidad. |
| Guard/usuario | UseApiTokenGuard.php:15; EnsureApiCustomer.php:12 | Resolver usuario es distinto de resolver consumidor. Requerir PAT persistido y binding/canal, sin confiar en datos declarados por cliente. |
| Idempotencia | EnforceIdempotencyKey.php:33-93,127-150 | Puede cortar el pipeline y devolver respuesta previa; no se ejecutan controlador ni action. Gate obligatorio antes del replay. No devolver un enlace previamente guardado a un consumidor ahora prohibido. |
| FormRequest | GeneratePaymentLinkRequest.php:11-34 | authorize=true; valida brand y TTL, no capacidades. No es una prohibición de pagos para Leo. |
| Controlador | CheckoutController.php:162-203 | Obtiene customer, brand, TTL y currentAccessToken.id. La lectura de id falla con TransientToken en P08 sesión local. No hay comprobación Leo. |
| Readiness | CheckoutPreparation.php:210-250 | Valida ítems, borrador, contacto/dirección y cita. No decide autorización por consumidor. No usar un 409 de readiness como prueba negativa de permisos. |
| Acción | GenerateAkubicaCheckoutPaymentLinkAction.php:26-65 | Puede actualizar checkout_step, genera token aleatorio, guarda hash del link y created_by_token_id, retorna URL. No llama a proveedor ni crea compra/transacción de pago en esta acción. Gate de defensa para llamada directa requiere nuevo contexto aprobado. |
| Auditoría | Ruta payment-link declara api.idempotency, no api.audit adicional | No asumir auditoría de negocio específica del link. Correlación y recibo idempotente no equivalen a consentimiento/autorización. Replay puede escribir otp_movement_events técnico. |
| Enlace ya emitido | AkubicaCheckoutLinkController.php:18-68; AkubicaCheckoutLink.php:28-43; routes/web.php:24 | Lookup por hash, vigencia/readiness; autentica sesión del usuario y marca usado al abrir. markUsed registra, no rechaza por sí solo un segundo uso. Bloquear nueva creación/replay no revoca automáticamente enlaces ya entregados. |

La búsqueda estática de GenerateAkubicaCheckoutPaymentLinkAction y AkubicaCheckoutLink en app/routes identificó CheckoutController como llamador de creación y el controlador web como consumidor del enlace. La defensa de la acción directa debe probarse además del inventario de rutas; no se afirma cobertura global de otras APIs de cobro fuera de API V1.

## Matriz de protección propuesta

| Contexto | Resultado objetivo | Cero efectos exigidos | Estado comprobado hoy |
| --- | --- | --- | --- |
| Leo verificado, cualquier brand/readiness, incluso rol admin | 403 LEO_PAYMENT_LINK_FORBIDDEN | Sin link, cambios de draft, compra, transacción ni proveedor; sin body de link cacheado. | BLOCKED: no se puede preparar identidad Leo genuina aprobada. |
| Leo con wildcard o permiso de pago incluido por error | Mismo 403 | El deny de consumidor precede a tokenCan y roles. | BLOCKED. El comportamiento actual wildcard permite crear un link como Bearer no clasificado. |
| Header X-Consumer/native falso manteniendo Leo verificado | Mismo 403 | Header no modifica identidad ni entrada del action. | BLOCKED para Leo; C01/C02 prueban que headers no crean un binding verificado. |
| PAT native en canal Leo | 403 CONSUMER_NOT_AUTHORIZED | Ningún enlace, ni replay de otro canal. | BLOCKED por falta de binding/canal aprobado. |
| PAT sin clasificación/grant suficiente | 403 CONSUMER_NOT_AUTHORIZED, si se aprueba esa transición | Sin cambios ni links. | **P07 FAIL: 200 y un link en SQLite temporal**. No autoriza bloquear clientes legacy arbitrariamente. |
| Sin Bearer, inválido, revocado o expirado | 401 UNAUTHENTICATED | Cero mutaciones/links/transacciones/HTTP saliente. | Cuatro subcasos de P08 verificados, sin efectos. |
| Sesión web sin Bearer, después de login real en test | 401 UNAUTHENTICATED | No actuar como PAT ni llegar a generar un link. | **P08 FAIL: 500 INTERNAL_ERROR**, sin cambios de negocio; no aceptar excepción como denegación correcta. |
| Consumidor/delegación revocados después de emitir recibo | 403 CONSUMER_NOT_AUTHORIZED antes de replay | No devolver URL/body previos. | BLOCKED. |
| Native registrado con permiso de pago literal y canal concordante | 200 y un link; replay mismo recibo sin duplicación | Compras/cargos/proveedor quedan fuera de creación de link. | P12 BLOCKED. Los positivos legacy son controles de compatibilidad Bearer, no prueba de identidad native. |

Propuesta de orden: auth y PAT real → resolver consumidor/canal y delegación → política de operación (deny Leo primero) → idempotencia → FormRequest/readiness → acción con autorización → respuesta. Si no puede obtenerse identidad confiable, no convertir un usuario o header en consumidor. La propuesta de deny para desconocidos requiere migración de clientes legítimos aprobada.

No es necesario cambiar todas las rutas checkout para insertar un gate específico. Debe verificarse su orden efectivo con el router y el camino de replay. La acción requiere defensa equivalente para ejecución directa. Ninguna de las dos modificaciones se hace antes de aprobar la identidad.

## Matriz exacta de rutas legacy

Todas las rutas siguientes están bajo Sanctum+customer y carecen de distinción de consumidor. Los FormRequest inspeccionados autorizan true y aplican validación de forma; los servicios sí comprueban ownership del beneficio/carrito. No se modifica ninguna regla de saldo/selección.

| Ruta | Operación y exposición confirmadas | Riesgo para el flujo opaco | Política propuesta y estado |
| --- | --- | --- | --- |
| GET /api/v1/cart/medical-benefit | Devuelve MedicalServiceCartResource: cart_id, ítems/IDs y beneficios con id, coupon_id, code, descripción e importe. activeCart puede crear/recalcular. | Expone representación interna; lectura no puramente pasiva. | Denegar a Leo hasta interfaz opaca; mantener clientes registrados. **Pendiente D08**. |
| POST /api/v1/cart/medical-benefit | Recibe service_line/code; aplica selección de preview y devuelve resource interno. Idempotencia/auditoría declaradas. No pide comprobante de consentimiento. | Elude confirmación futura y opacidad; no consume saldo actualmente. | Denegar a Leo, sin alterar regla médica para clientes permitidos. **Pendiente D08**. |
| DELETE /api/v1/cart/medical-benefit | Retira selección, recalcula y devuelve removed/cart con formato interno; no confirma consentimiento verificable. | Permite retirar fuera del nuevo flujo. | Mismo gate Leo, independiente de code/beneficio. **Pendiente D08**. |
| GET /api/v1/cart/coupon | Resuelve laboratorio por brand; formatCouponPayload expone code, type, value/remanente, descripción y descuento. No se identificó coupon_id ni credit_id en ese DTO. | Code y montos internos siguen disponibles fuera del catálogo opaco. | Denegar Leo si D08 aprueba el aislamiento; mantener consulta legacy de clientes legítimos. |
| POST /api/v1/cart/coupon | Recibe brand/code, persiste coupon_id internamente en draft; responde coupon code/value/descripción y totales. No exige confirmación verificable; ruta declara audit, no api.idempotency. | Mutación sin el nuevo consentimiento; código público es entrada directa. | Gate consumidor, sin cambiar fórmula ni consumo. **Pendiente D08**. |
| DELETE /api/v1/cart/coupon | Desasocia cupón del borrador; respuesta coupon=null, removed y totales cuando había cupón; no confirmación verificable. | Retiro fuera del flujo controlado, aunque no exponga ID en ese resultado. | Gate consumidor y compatibilidad explícita. **Pendiente D08**. |

No se encontró un campo credit_id en los recursos inspeccionados: el crédito médico se representa por coupon_id con type=credit. Se documenta lo realmente expuesto; no se inventan campos filtrados.

También revisar como dependencia GET cart y checkout/prepare médicos, que reutilizan el resource interno. Impedir solo los seis endpoints listados no elimina esa exposición alternativa. La allowlist inicial Leo debe enumerar expresamente las lecturas admitidas; no agregar un bloqueo global de usuarios ni una redacción silenciosa al DTO legacy. POST cart/items y sus respuestas también requieren revisión antes de prometer opacidad de todo el flujo.

## Links anteriores y compatibilidad pendiente

Un recibo o link obtenido antes del gate conserva valor si solo se bloquea una nueva llamada. Proponer namespace por usuario/consumer y rechazo de replay tras revocación; decidir invalidación de links emitidos por PAT comprometido o consumidor ahora prohibido mediante created_by_token_id y binding histórico. No se consumió ningún link real ni se revocaron datos en esta fase. La redención web y los enlaces ya distribuidos requieren una decisión específica de Pagos/Seguridad para conservar acceso legítimo sin dejar capacidades prohibidas vivas.

## Delta contractual propuesto

No hay delta implementado en la API: rutas, operationId y SHA RC1.1 se conservan. La futura política sí sería observable: 403 LEO_PAYMENT_LINK_FORBIDDEN/CONSUMER_NOT_AUTHORIZED para solicitudes antes aceptadas, nuevas condiciones de cliente/canal y rechazo antes de replay. Las seis rutas legacy tendrían denegación condicionada por consumidor. El contrato actual declara bearerAuth y 403 con descripción FORBIDDEN y esquema ApiErrorResponse genérico; que esos JSON quepan en el esquema no demuestra que documente la nueva política.

Propuesta de versionado: conservar RC1.1 inmutable; preparar después un borrador separado con requisitos de identidad/canal, capacidades por operación, catálogo exacto de errores y ejemplos negativos tipados, cabeceras de replay/correlación y estrategia de migración. Mantener operationId y DTO para consumidores autorizados. Si se exige proof de canal a clientes existentes sin transición compatible, tratarlo como incompatibilidad de seguridad y acordar versión/ventana con Integraciones; no llamarlo cambio aditivo solo por usar 403 existente. Las tres operaciones opacas quedan fuera de este incremento.
