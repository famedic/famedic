# TI-11 - Observabilidad pre-UAT staging

## Identidad

- Tarea: TI-11 - Observabilidad pre-UAT
- Fecha: 2026-08-13
- Environment: staging
- Forge release runtime: `75401580`
- SHA runtime: `0b46a096a3902630e6ae0e3088c266c790d31b0f`

## Alcance

Esta evidencia documenta la verificacion de observabilidad pre-UAT en staging. No contiene bearer tokens, OTP, passwords, cookies, emails, telefonos, DSN, signed URLs, grants, PII real, request bodies sensibles ni metadata completa de audit.

No se modifico runtime, logging ni configuracion. No se ejecuto deploy, no se uso produccion, no se generaron OTP/SMS, pagos ni secure links.

## Runtime observado

| Control | Valor observado | Resultado |
|---|---|---|
| app.debug | `false` | PASS |
| logging.default | `stack` | PASS |
| logging.stack | `single,nightwatch` | PASS |
| api_v1.audit.enabled | `true` | PASS |
| business_audit.enabled | `true` | PASS |
| api_v1.idempotency.enabled | `true` | PASS |
| secure links | `60/3` | PASS |

## Correlation end-to-end

| Observacion | Metodo | CID | Resultado |
|---|---|---|---|
| OBS-01 catalogo publico | `GET /api/v1/catalog/laboratory-brands` | `11111111-1111-4111-8111-111111111111` | HTTP `200`, header `X-Correlation-ID` identico, `success=true` |
| OBS-02 validacion | `GET /api/v1/catalog/laboratory-tests?brand=invalid-brand` | `22222222-2222-4222-8222-222222222222` | HTTP `422`, `error.code=VALIDATION_ERROR`, `retryable=false`, `error.correlation_id` identico |
| OBS-03 no autenticado | `POST /api/v1/user/addresses` sin bearer | `33333333-3333-4333-8333-333333333333` | HTTP `401`, `error.code=UNAUTHENTICATED`, `retryable=false`, header/body CID identico, sin escritura |
| OBS-04 mutacion auditada controlada | `POST /api/v1/cart/items` fixture primary | `44444444-4444-4444-8444-444444444444` | HTTP `409`, `error.code=ITEM_ALREADY_IN_CART`, `retryable=false`, header/body CID identico |

Resultado: PASS. `X-Correlation-ID` entra por request, el mismo valor sale en response header, los errores lo reflejan en `error.correlation_id`, y OBS-04 demuestra persistencia del mismo CID en `api_v1_audit_events`.

## Diferencia entre log y audit

- `api_v1_audit_events` no es un access log global.
- Requests como catalogo publico, validaciones 422 o errores 401 pueden no generar fila audit.
- `Log::shareContext` agrega `correlation_id` a mensajes que efectivamente sean logueados.
- No existe un log entry garantizado por cada request.
- La ausencia de filas audit/log para OBS-01, OBS-02 u OBS-03 no se clasifica como falla.

## Audit persistido OBS-04

| Campo | Valor observado | Resultado |
|---|---|---|
| event_name | `api_v1.cart.item_added` | PASS |
| route_name | `api.v1.cart.items.store` | PASS |
| method | `POST` | PASS |
| outcome | `rejected` | PASS |
| http_status | `409` | PASS |
| error_code | `ITEM_ALREADY_IN_CART` | PASS |
| retryable | `false` | PASS |
| correlation_id | `44444444-4444-4444-8444-444444444444` | PASS |
| idempotency_effect | `null` | PASS |

OBS-04 uso el fixture primary y un request que duplicaba deliberadamente un estudio OLAB sintetico ya presente. Esto genera audit real y no altera el carrito.

## Cleanup OBS-04

| Control | Valor observado | Resultado |
|---|---|---|
| temporary_pat_deleted | `1` | PASS |
| cart before: cart_items_total | `2` | PASS |
| cart before: olab_item_count | `1` | PASS |
| cart after: cart_items_total | `2` | PASS |
| cart after: olab_item_count | `1` | PASS |
| cart_state_restored | `true` | PASS |
| temporary_pat_count | `0` | PASS |

El audit event permanece por diseno append-only. El carrito fixture quedo restaurado y la credencial temporal fue eliminada.

## Mini-runbook de investigacion

1. Recibir el `correlation_id` reportado por Akubica.
2. Validar formato: 8-128 caracteres, caracteres seguros, sin emails, bearer material ni espacios.
3. Buscar primero en `api_v1_audit_events` por `correlation_id` exacto.
4. Si no existe audit row, revisar logs solo por CID exacto y recordar que no todas las rutas generan audit/log.
5. Para idempotencia, revisar `api_v1_idempotency_records` por `correlation_id` y `status`.
6. Para secure links/step-up, revisar audit y modelos relacionados sin imprimir token/grant plano.
7. No consultar bodies completos, metadata completa ni secretos salvo procedimiento autorizado.

### ApiV1AuditEvent lookup

```bash
CID="REEMPLAZAR_CID_VALIDADO" php artisan tinker --execute='
$cid = getenv("CID");
App\Models\Api\V1\ApiV1AuditEvent::query()
  ->where("correlation_id", $cid)
  ->orderBy("id")
  ->get([
    "event_name",
    "occurred_at",
    "correlation_id",
    "route_name",
    "method",
    "outcome",
    "http_status",
    "error_code",
    "retryable",
    "idempotency_effect",
  ])
  ->each(fn ($e) => print(json_encode($e->toArray(), JSON_UNESCAPED_SLASHES).PHP_EOL));
'
```

### BusinessAuditEvent lookup

```bash
CID="REEMPLAZAR_CID_VALIDADO" php artisan tinker --execute='
$cid = getenv("CID");
App\Models\Audit\BusinessAuditEvent::query()
  ->where("correlation_id", $cid)
  ->orderBy("id")
  ->get([
    "event_name",
    "occurred_at",
    "correlation_id",
    "channel",
    "outcome",
    "error_code",
    "retryable",
  ])
  ->each(fn ($e) => print(json_encode($e->toArray(), JSON_UNESCAPED_SLASHES).PHP_EOL));
'
```

### IdempotencyRecord lookup

```bash
CID="REEMPLAZAR_CID_VALIDADO" php artisan tinker --execute='
$cid = getenv("CID");
App\Models\Api\V1\IdempotencyRecord::query()
  ->where("correlation_id", $cid)
  ->orderBy("id")
  ->get([
    "method",
    "path",
    "status",
    "http_status",
    "correlation_id",
    "lease_expires_at",
    "expires_at",
    "created_at",
    "updated_at",
  ])
  ->each(fn ($e) => print(json_encode($e->toArray(), JSON_UNESCAPED_SLASHES).PHP_EOL));
'
```

### Log lookup exacto

```bash
CID="REEMPLAZAR_CID_VALIDADO"
grep -F -- "$CID" storage/logs/laravel.log
```

No imprimir request/response bodies completos. No imprimir bearer, OTP, cookies, signed URLs, grants, metadata completa ni PII.

## Retencion

| Recurso | Estado observado | Resultado |
|---|---|---|
| api_v1_audit_events prune automatico | no identificado | PASS CON OBSERVACION |
| business_audit_events prune automatico | no identificado | PASS CON OBSERVACION |
| idempotency prune | existe programacion condicionada por flag | PASS |
| logging | depende de channel/host | PASS CON OBSERVACION |

No se inventa retencion efectiva no verificada.

## Acceso

La investigacion tecnica requiere acceso autorizado a staging, DB y logs. No se definen personas/roles no comprobados y no se afirma acceso directo de Akubica a DB/logs.

## Async

No se encontro propagacion automatica explicita del correlation ID a jobs. Business audit puede recibir correlation manualmente. Esto queda como riesgo residual y no bloquea TI-11.

## Sanitizacion

PASS. Este reporte no incluye tokens, OTP, passwords, cookies, PII, DSN, signed URLs, grants, request bodies sensibles ni metadata completa de audit. Se documentan solo CIDs, estados, codigos HTTP, codigos de error, nombres de eventos/rutas y conteos sanitizados.

## Resultado

TI-11 LISTO PARA CIERRE. OBS-01, OBS-02, OBS-03 y OBS-04 quedaron en PASS; la persistencia audit por CID fue demostrada en OBS-04; cleanup quedo en PASS; el carrito fue restaurado; la credencial temporal fue eliminada; el mini-runbook quedo documentado; y retencion/acceso/async quedaron registrados con riesgos residuales.
