# IT-0061 · Diagnóstico local de functional-window-2

La evidencia QA original se conserva sin modificar en los reportes de
`storage/framework/`. Este documento clasifica los nueve fallos de la corrida
del release 83814060 / SHA `5d0769850941f8fc93da423426e79249d7c0ed97` y registra
únicamente correcciones locales.

## Diagnóstico de los nueve fallos

| Caso | Resultado QA | Clasificación | Causa demostrada | Corrección local |
|---|---|---|---|---|
| B03 | 200, assertion fallida | fixture | El recurso no era la variante `public_price_unavailable`; devolvió precio público disponible y ahorro | Usar la variante `RC2-PRICE-FIX-B03` y validar sus campos contractuales |
| B03r | 200, assertion fallida | plan/runner | La lista se comprobaba por posición | Aserción `contains` por ID del recurso de B03 |
| B06b | 403 | acceso | El binding native de la ventana no tenía `cart:laboratory:write` | Añadir capacidades laboratory mínimas y marca `olab` al perfil native de ventana |
| B06r | 422 | plan/dependencia | La consulta de carrito no incluía el filtro de marca; además B06b no había creado el ítem | Usar `GET /cart?brand=olab`; queda dependiente de B06b |
| B20 | 403 | acceso | Mismo déficit de capacidad native laboratory | Misma corrección de binding, sin wildcard ni permisos financieros |
| B21 | 403 | acceso/plan | El DELETE no identificaba la línea laboratory y el perfil no tenía la capacidad explícita | Capacidad laboratory native y `?brand=olab` como discriminador |
| B21r | 422 | plan/dependencia | La lectura no indicaba `service_line=medical_services`; además B21 no había podido ejecutarse | Usar `GET /cart?service_line=medical_services`; queda dependiente de B21 |
| B19 | 409, envelope de error válido | plan | OpenAPI permite `201/409`; con membresía activa el contrato devuelve `MEMBERSHIP_ALREADY_ACTIVE`, no `data` | Assertion alternativa estricta para los dos envelopes contractuales |
| B25 | 403, código específico | plan | Rev7 exige 403 para Leo; la implementación específica devuelve `LEO_PAYMENT_LINK_FORBIDDEN` | Aceptar sólo los códigos de denegación contractualmente equivalentes, manteniendo status 403 |

## Cambios locales

- Servidor/runtime candidato: capacidades y binding native laboratory de la
  ventana; rama de autorización laboratory sólo cuando existe la capacidad
  explícita. El camino native legacy permanece sin cambios cuando no existe
  esa capacidad.
- Runner: aserciones `one_of` y `contains`; extracción segura de IDs DELETE
  con query string; timeout mínimo independiente para cleanup; conserva el
  transporte `X-Consumer-Credential` y el User-Agent del commit `84295dfb`.
- Plan: filtros de carrito, discriminador de B21 y assertions contractuales.
- Arnés: allowlist explícita para las bases sintéticas IT-0061 y fixture HTTP
  con totals de carrito; no se relaja hacia QA ni producción.

## Validación local

- Python runner + integración sintética: `13 tests`, PASS.
- Precios B03/B06: `5 tests / 35 assertions`, PASS.
- Aislamiento B19/B20/B21: `8 tests / 68 assertions`, PASS.
- Acceso de ventana: PASS; idempotencia y reset verificados.
- Leo laboratorio/payment-link afectado: PASS en las comprobaciones dirigidas.

La suite local usa MySQL sintético en `127.0.0.1:13307`; no usa QA ni
credenciales remotas. Las advertencias de PHPUnit `file_get_contents` son del
arnés de cobertura existente y no convierten fallos en PASS.

## Retest mínimo QA pendiente

1. Precheck de acceso/binding native con `cart:laboratory:read/write` y marca
   `olab`.
2. B03, B03r.
3. B06a, B06b, B06r.
4. B19, B20, B21, B21r.
5. B25 y los controles B21x, B22, R07a, R07a2 y R07b.

B01/B01r/B02/B04/B05 siguen BLOCKED y R05 sigue excluido. No se reejecutaron
los 13 PASS no afectados.
