# Beneficios elegibles y referencias opacas — propuesta para decisión

Estado: **DISEÑO NO APROBADO, NO IMPLEMENTADO**. No forma parte de las 67 operaciones del candidato OpenAPI RC1.1. Las rutas, códigos y campos siguientes son propuestas revisables; no deben generarse clientes ni integraciones productivas a partir de ellas. No se modificaron rutas Laravel ni reglas de negocio.

## Alcance y compatibilidad

Proponer primero el carrito médico, con `service_line=medical_services`. Laboratorio y farmacia no se incluyen por analogía: su expansión requiere reglas y aprobación específicas. Conservar los endpoints `/cart/medical-benefit` y `/cart/coupon` y sus payloads legacy mientras existan consumidores; el contrato RC1.1 documenta su comportamiento actual. Los DTO nuevos no deben heredar sus `coupon_id`, IDs de modelos ni código público para saldo.

La consulta requiere Bearer válido del usuario y contexto de carrito propio. Una referencia opaca es un permiso acotado de selección, no una credencial de autenticación. El asistente no puede cobrar, generar links de pago, consumir saldo ni activar una membresía mediante estas operaciones.

## Operaciones tentativas, separadas del OpenAPI candidato

| Operación propuesta | Entrada | Respuesta propuesta | Decisiones pendientes |
|---|---|---|---|
| `GET /cart/benefits` | Línea médica; filtro opcional `benefit_type=coupon\|credit`; carrito activo propio resuelto por servidor | Cupones y saldos elegibles, no elegibles relevantes, aplicados y totales autoritativos; referencias opacas, restricciones y vigencia | Si mostrar no elegibles; paginación; momento de recalcular y efectos de lectura |
| `POST /cart/benefits/applications` | `benefit_ref`, `cart_ref`, `cart_version`, `confirmation_ref`; Idempotency-Key obligatorio | `application_ref`, tipo, importe aplicado, estado, carrito/versión y totales recalculados | Semántica de preview/reserva; acumulación; monto parcial elegido por usuario |
| `POST /cart/benefits/applications/{application_ref}/remove` | `cart_ref`, `cart_version`, `confirmation_ref`; Idempotency-Key obligatorio | `removed`, aplicación opaca, totales y versión resultante | Método final; se propone acción POST para no depender de body en DELETE; forma de confirmar retiro |

La consulta cubre cupones, saldos, beneficios ya aplicados y totales. Podrá separarse en endpoints adicionales solo si una decisión de negocio/consumo lo justifica. No se ha fijado un conteo definitivo de operaciones nuevas.

## DTO propuesto (tipos estrictos)

Todos los objetos nuevos usarán campos declarados y cierre de propiedades; no usar `ApiSuccessResponse.data={}` como escape. Los ejemplos usan placeholders y no credenciales.

| Objeto | Campos propuestos |
|---|---|
| Contexto de consulta | `cart_ref:string` opaca, `cart_version:string` opaca, `service_line:medical_services`, `rules_version:string`, `currency:MXN` |
| Opción de beneficio | `benefit_ref:string`, `benefit_type:coupon\|credit`, `display_label:string` sin PII, `eligibility:eligible\|not_eligible\|already_applied`, `estimated_amount_cents:integer>=0`, `available_amount_cents:integer>=0\|null`, `max_applicable_cents:integer>=0`, `reference_expires_at:date-time`, `restrictions:Restriction[]` |
| Restricción | `code` de catálogo cerrado y `message` revisado sin datos personales; no volcar mensajes internos de excepciones |
| Aplicación | `application_ref:string` opaca, `benefit_type`, `applied_cents:integer>=0`, `status:applied\|removed\|invalidated`, `currency:MXN`; sin ID de cupón/modelo |
| Totales | `subtotal_cents`, `discount_cents`, `credit_applied_cents`, `total_cents`: enteros no negativos; `currency:MXN`, `item_count:integer>=0`; separar descuento de saldo |
| Aplicación/retirada confirmada | `cart_ref`, `cart_version`, referencia de opción/aplicación, `confirmation_ref`; `requested_amount_cents` solo si se aprueba selección parcial, no obligatorio por defecto |

Ejemplo de intención de aplicación, no de endpoint existente:

```json
{
  "cart_ref": "{{opaque_cart_ref}}",
  "cart_version": "{{opaque_cart_version}}",
  "benefit_ref": "{{opaque_benefit_ref}}",
  "confirmation_ref": "{{opaque_confirmation_ref}}"
}
```

Para saldo no se solicita `coupon_id`, código público, nombre de usuario ni teléfono. Los errores de ownership no deben revelar si un beneficio ajeno existe.

## Reglas técnicas propuestas

1. **Ownership:** resolver el usuario desde Sanctum; nunca confiar en un user_id del body. Toda referencia queda ligada a usuario, carrito, línea y audiencia admitida. Comprobar también la pertenencia de aplicación al retirar.
2. **Opacidad:** emitir referencias aleatorias no derivables de IDs. Guardar solo hash de referencia con mapeo interno restringido. No devolver hashes de identificadores internos como sustituto de opacidad; no usar IDs codificados en base64.
3. **Vigencia:** asociar emisión, vencimiento, versión de reglas y fingerprint del carrito/importes. TTL exacto pendiente. Revalidar elegibilidad en el momento de aplicar, no solo al consultar.
4. **Confirmación expresa:** el servidor debe poder vincular el consentimiento a usuario, carrito/versión, beneficio, operación, monto y vigencia. Un booleano que Leo puede colocar unilateralmente no demuestra consentimiento. Falta decidir el canal, la emisión y la verificación de `confirmation_ref`; no inventar un endpoint definitivo de confirmación en esta fase.
5. **Idempotencia:** alcance usuario+operación+clave; fingerprint incluye referencias, versión, monto y confirmación. Reintento idéntico devuelve el resultado original; misma clave con payload distinto produce conflicto. Una referencia retirada no puede reutilizarse fuera de su flujo autorizado. Resolver concurrencia con bloqueo/transacción al implementar.
6. **Preview sin consumo:** preservar saldo y no marcar usado ni crear transacciones de compra. Decidir si existe reserva temporal para compras simultáneas y cuándo se consume finalmente en FAMEDIC; no heredar consumo de laboratorio/farmacia en el preview médico.
7. **Consistencia:** cambiar ítems o precio invalida o revalida explícitamente selección/consentimiento. Totales de carrito, beneficios y checkout deben coincidir. El endpoint actual de totales con `applied_benefits:[]` es una limitación documentada, no el comportamiento propuesto.
8. **Auditoría:** evento interno con resultado, código, línea, monto y referencia de operación restringida; exportación solo sintética. Sin Bearer, OTP, códigos públicos, cuerpos completos ni PII en evidencias.
9. **Compatibilidad:** adaptador legacy y DTO nuevo separados; plazo de deprecación por decidir. No cambiar silenciosamente el significado de campos legacy ni añadir nuevas capacidades de pago a Leo.

## Catálogo tentativo de errores

| HTTP | Código propuesto | Condición |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Bearer ausente/no válido |
| 404 | `BENEFIT_REFERENCE_NOT_FOUND` | Referencia inexistente o no visible por ownership; mismo resultado externo para evitar enumeración |
| 404 | `BENEFIT_APPLICATION_NOT_FOUND` | Aplicación inexistente/no visible |
| 409 | `BENEFIT_REFERENCE_EXPIRED` | Vigencia vencida |
| 409 | `CART_VERSION_CONFLICT` | Carrito distinto de la versión consultada/confirmada |
| 409 | `BENEFIT_NOT_ELIGIBLE` | Revalidación de reglas falla |
| 409 | `BENEFIT_ALREADY_APPLIED` | Existe selección incompatible o duplicada, según regla final |
| 409 | `BENEFIT_REFERENCE_ALREADY_USED` | Uso fuera de la repetición idempotente autorizada |
| 409 | `CONFIRMATION_EXPIRED` | Confirmación vinculada vencida |
| 409 | `IDEMPOTENCY_REPLAY_CONFLICT` | Misma clave y diferente payload |
| 422 | `CONFIRMATION_REQUIRED` | Falta confirmación verificable |
| 422 | `VALIDATION_ERROR` | Forma/tipos/monto inválidos |
| 429 | `TOO_MANY_REQUESTS` | Protección de abuso |
| 503 | `BENEFIT_SERVICE_UNAVAILABLE` | No se puede confirmar elegibilidad/cálculo; no devolver éxito con total inventado |

Los nombres/status definitivos requieren reconciliación con la infraestructura de errores e idempotencia existente. La lista no implica que Laravel ya los emita. Debe decidirse si exponer distinción de expiración puede filtrar existencia, y cuándo devolver un único 404 de no visibilidad.

## Decisiones de negocio bloqueantes

| ID | Decisión necesaria | Responsable por rol / evidencia requerida |
|---|---|---|
| D01 | ¿Un solo beneficio o acumulación cupón+saldo? La implementación actual permite uno. | Producto/finanzas: tabla aprobada de prioridades, exclusiones y máximos |
| D02 | ¿Saldo parcial solicitado o automáticamente min(saldo,subtotal)? | Finanzas: fórmula, redondeo, límites y totales cero |
| D03 | ¿Preview o reserva? ¿Qué ocurre con compras concurrentes? | Finanzas/ingeniería: estados y momento exacto de consumo/liberación |
| D04 | ¿Cómo se captura y verifica consentimiento para aplicar y retirar? | Producto/seguridad: canal y evidencia vinculada verificable |
| D05 | TTL de opción, confirmación e idempotencia; comportamiento al cambiar carrito/precio | Seguridad/ingeniería: política de expiración y revalidación |
| D06 | Qué no elegibles mostrar y qué restricciones explicar | Producto/privacidad: mínimo de información, sin referencias internas |
| D07 | Alcance médico y eventual expansión a otras líneas | Producto: reglas por línea, sin equiparación automática |
| D08 | Compatibilidad/deprecación y adopción de clientes | Integración: consumidores identificados y pruebas de regresión |
| D09 | Semántica futura de continuidad y readiness | Producto: navegación frente a checkout listo; no reutilizar banderas actuales como permiso de pago |
| D10 | Catálogo de errores y anti enumeración | Seguridad/API: códigos exactos y pruebas negativas independientes |

**Recomendación:** revisar y aprobar estas decisiones antes de autorizar Fase 2. Implementar después consulta autoritativa/DTO opaco, consentimiento e idempotencia con pruebas aisladas. No generar paquete ni pruebas externas mientras la Fase 0 administrativa siga abierta.
