# IT-0061 — ventana focalizada A12 + B03/B03r

## Identidad y precheck

- Release/SHA: `83882430` / `49c72de20a53a9a4864b2ab9262d26db7f186e73`.
- `REVISION` e `inventory identity` coincidieron; entorno `staging` y host HTTPS first-party.
- Configuración efectiva: Gate del operador PASS; TTL de acceso `30` minutos; TTL de continuidad A4 `10` minutos; laboratorio habilitado/certificado; flags financieras apagadas.
- Migraciones pendientes: `0`.
- Antes de emitir: `access-state.json` ausente; manifest dedicado activo; manifest legacy presente; sin PAT residuales del namespace.

## Conciliación histórica B01/B01r/B02/B04/B05

Se buscó evidencia por release `83825089` y SHA
`b5c828c87d99a595df6d9959449f0202cfede7dc` en el control y el árbol de
evidencias. No se encontró un artefacto con esa pareja exacta. Los reportes
con otros releases o sólo locales no acreditan esos PASS QA. Se conservan como
históricos y los cinco casos permanecen `BLOCKED_QA` hasta localizar evidencia
verificable de esa corrida o ejecutar la selección autorizada correspondiente.

## B03 — PASS_QA

- Preparador: `php artisan akubica:rc2-price-fixtures --dry-run/--apply` con
  namespace `akubica-uat-batch2-a4-v1`; recurso dinámico `5008`, resuelto por
  `RC2-PRICE-FIX-...-B03`.
- Request real: `GET /api/v1/catalog/laboratory-tests/5008`; HTTP `200`.
- Assertions: `comparison_status=public_price_unavailable`,
  `public_price_cents=0`, `savings_cents=null`; PASS.
- Nota de plan: `search=RC2-B03` no devolvió el recurso porque el materializador
  usa el nombre `[UAT] RC2 B03 sin precio publico`; no es un defecto runtime.

## B03r — PASS_QA

- Request real: `GET /api/v1/catalog/laboratory-tests?brand=olab&search=RC2%20B03&per_page=10&page=1`; HTTP `200`.
- Assertions: paginación presente; el listado contiene el ID dinámico `5008`;
  el precio del elemento conserva `public_price_unavailable` y
  `savings_cents=null`; PASS.

## A12 — PASS_QA (expiración natural)

- Acceso emitido por `akubica:uat-access`, TTL efectivo `30` minutos; custodia
  privada y coherencia de alias verificadas sin revelar credenciales.
- Petición previa: `GET /api/v1/memberships/status`, HTTP `200`, estado `active`.
- Servidor UTC previo: `2026-11-24T15:33:49Z`; `expires_at` observado:
  `2026-11-24T16:03:48Z`.
- Sin cambiar reloj/TTL ni revocar, petición posterior a las
  `2026-11-24T16:13:35Z`: HTTP `401`, `UNAUTHENTICATED`; PASS de expiración
  natural. No se atribuye a un PAT concreto más allá del acceso custodiado de
  esta ventana.

## Limpieza y conciliación

- B03/B03r: cleanup de price fixtures y reset de accesos, ambos con salida 0;
  filas `RC2-PRICE-FIX-...` restantes: `0`.
- A12: reset final reportó `deleted=29`; una primera invocación devolvió código
  1 pese a eliminar el estado, por lo que se repitió únicamente el reset
  idempotente y se obtuvo salida sanitizada `status=ok`.
- Conciliación independiente final: `access-state.json` ausente, PAT de los
  seis usuarios dedicados `0`, manifest dedicado activo, manifest legacy `1`,
  fixtures base y legacy conservados.
- No hubo OTP, SMS, correo, pagos ni integraciones externas.

## Estado

Los PASS de esta ventana no cierran Batch 2. BLQ-A1-01, AJ-B, los 98 fallos
históricos y los casos sin evidencia QA permanecen abiertos.
