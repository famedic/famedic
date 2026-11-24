# IT-0061 — B01/B01r/B02/B04/B05 PASS_QA

## Identidad y precheck

- Release/SHA: `83882430` / `49c72de20a53a9a4864b2ab9262d26db7f186e73`.
- `REVISION` e `inventory identity` coincidentes; staging, HTTPS first-party,
  Gate PASS, TTL de acceso 30 minutos, flags financieras apagadas y 0
  migraciones pendientes.
- Antes de emitir: `access-state.json` ausente, manifest dedicado activo,
  manifest legacy conservado y sin accesos temporales residuales.

## Preparación namespace-scoped

Dry-run y apply de `akubica:rc2-catalog-fixtures` identificaron y crearon
exclusivamente:

| Caso | ID dinámico | Variante |
|---|---:|---|
| B01 | 5010 | estudio disponible con ahorro |
| B02 | 5012 | paquete |
| B02 componente | 5011 | componente del paquete |
| B04 | 5013 | `no_savings` |
| B05 | 5014 | `data_inconsistent` |

No se usaron IDs locales ni se tocaron registros legacy.

## Resultados HTTP

| Caso | Request | Resultado | Assertions |
|---|---|---|---|
| B01 | GET `/api/v1/catalog/laboratory-tests/5010` | 200 | `study`, `available`, ahorro 10000 = público 45000 − FAMEDIC 35000 |
| B01r | GET `/api/v1/catalog/laboratory-tests?brand=olab&search=RC2%20B01&per_page=10&page=1` | 200 | ID 5010 presente, paginación presente, precio FAMEDIC consistente |
| B02 | GET `/api/v1/catalog/packages?brand=olab&search=RC2%20B02&per_page=10&page=1` | 200 | ID 5012 presente, `item_type=package`, paginación presente |
| B02r | GET `/api/v1/catalog/packages/5012` | 200 | `package`, comparación disponible, ahorro positivo consistente |
| B04 | GET `/api/v1/catalog/laboratory-tests/5013` | 200 | `comparison_status=no_savings`, `savings_cents=0` |
| B05 | GET `/api/v1/catalog/laboratory-tests/5014` | 200 | `comparison_status=data_inconsistent`, `savings_cents=null` |

Todas las assertions pasaron; no hubo escrituras HTTP.

## Contrato B03 conservado

La colección congelada rev7 exige `savings_cents` nulo, no ausencia de la
propiedad: `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json`, líneas
1256 y 1288. Por tanto, el B03 anterior con `savings_cents=null` es PASS_QA;
no se modificó la assertion.

## Limpieza y reset

- `akubica:rc2-catalog-fixtures --cleanup`: salida `0`; componentes `1`,
  paquetes `1`, tests `5`, categorías `1` eliminados.
- `akubica:uat-access --reset`: salida `0`, `deleted=29`.
- Conciliación independiente: `access-state.json` ausente, catálogo temporal
  restante `0`, manifest dedicado activo y manifest legacy `1`.

## Incidencia histórica de cleanup

El primer reset de la ventana A12 devolvió código `1`; la consulta inmediata
encontró `access-state.json` ausente pero 9 PAT de esa ventana aún presentes.
El código del aprovisionador devuelve `UAT_ACCESS_CLEANUP_FAILED` cuando la
aserción post-borrado encuentra cualquier PAT, binding, delegación, consumer o
credential activo. La evidencia disponible demuestra esa guardia fallida y la
persistencia parcial; no demuestra cuál DELETE individual falló. Un reset
idempotente posterior devolvió `status=ok`, `deleted=29`, y la conciliación
final confirmó cero PAT de los usuarios dedicados. La incidencia histórica se
conserva separada del cleanup final exitoso.

## Estado

B01/B01r/B02/B04/B05 quedan `PASS_QA` para esta corrida. Batch 2 no queda
certificado; permanecen abiertos los 98 fallos históricos, BLQ-A1-01 y AJ-B.
