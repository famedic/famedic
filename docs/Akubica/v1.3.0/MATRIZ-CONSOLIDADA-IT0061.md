# IT-0061 — matriz consolidada por escenario

Conciliación focalizada de una ventana QA autorizada; sin cambios de código,
permisos o deploy.

## Resultados conservados

| Escenario | Estado | Release/SHA | Evidencia | Observación |
|---|---|---|---|---|
| B01 | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | `RESULTADOS-VENTANA-B01-B05-20261124.md` | HTTP 200; estudio disponible, ahorro positivo consistente |
| B01r | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | misma evidencia | HTTP 200; listado, paginación e ID 5010 consistentes |
| B02 | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | misma evidencia | HTTP 200; paquete 5012, marca, tipo y precios contractuales |
| B02r | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | misma evidencia | HTTP 200; readback del paquete y ahorro consistente |
| B04 | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | misma evidencia | HTTP 200; `no_savings`, ahorro 0 |
| B05 | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | misma evidencia | HTTP 200; `data_inconsistent`, ahorro nulo |
| B03 | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | `RESULTADOS-VENTANA-A12-B03-20261124.md` | HTTP 200; `public_price_unavailable`, `public_price_cents=0`, `savings_cents=null`; ID dinámico 5008 |
| B03r | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | `RESULTADOS-VENTANA-A12-B03-20261124.md` | HTTP 200; filtro `RC2 B03`, paginación e identificación por ID; cleanup PASS |
| B06b | PASS_QA | release `83820733`, SHA `17313693ec1f48f4de0663a7bf8a67c615b3673a` | retest QA sanitizado | POST 201; ID dinámico verificado por inventory helper |
| B06r | PASS_QA | release `83820733`, SHA `17313693ec1f48f4de0663a7bf8a67c615b3673a` | retest QA sanitizado | Lectura 200; cleanup posterior |
| B19 | PASS_QA | release `83820733`, SHA `17313693ec1f48f4de0663a7bf8a67c615b3673a` | retest QA sanitizado | 409 `MEMBERSHIP_ALREADY_ACTIVE` |
| B20 | PASS_QA | release `83820733`, SHA `17313693ec1f48f4de0663a7bf8a67c615b3673a` | retest QA sanitizado | POST 201; ownership/inventory verificados |
| B21 | PASS_QA | release `83820733`, SHA `17313693ec1f48f4de0663a7bf8a67c615b3673a` | retest QA sanitizado | DELETE 200; unregister verificado |
| B21r | PASS_QA | release `83820733`, SHA `17313693ec1f48f4de0663a7bf8a67c615b3673a` | retest QA sanitizado | Lectura 200 |
| B18 | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | evidencia QA B18 sanitizada | Dos POST HTTPS concurrentes: 201 + 409 `MEMBERSHIP_ALREADY_IN_CART`, una fila persistida, 0 HTTP 500; cleanup y revocación verificados |
| B21x | PASS_QA_NEGATIVE | release `83820733`, SHA `17313693ec1f48f4de0663a7bf8a67c615b3673a` | retest QA sanitizado | 403 `CONSUMER_NOT_AUTHORIZED` |
| B25 | FAIL_HISTORICAL | release `83820733`, SHA `17313693ec1f48f4de0663a7bf8a67c615b3673a` | corrida QA histórica | 403 genérico; se conserva sin sobrescribir |
| B25 | PASS_QA | release `83820733`, SHA `17313693ec1f48f4de0663a7bf8a67c615b3673a` | revalidación QA separada | 403 `LEO_PAYMENT_LINK_FORBIDDEN`; 0 ligas/pagos; cleanup PASS |
| A12 | PASS_QA | release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73` | `RESULTADOS-VENTANA-A12-B03-20261124.md` | Antes de expirar 200/active; después de `expires_at` natural 401 `UNAUTHENTICATED`; reset separado |

La revalidación de B25 no convierte ni borra el FAIL histórico: son corridas
distintas sobre el mismo SHA.

## Próximo bloque priorizado

| Escenario | Estado actual | Precondición faltante | Escrituras necesarias | Preparación soportada | Cleanup |
|---|---|---|---|---|---|
| B01 | READY_LOCAL / BLOCKED_QA | Variante dedicada `catalog_available` con estudio y precio verificables | Crear estudio namespace-scoped mediante `akubica:rc2-catalog-fixtures` | Comando local validado; requiere publicación/despliegue para QA | Cleanup del prefijo `RC2-CATALOG-FIX-<namespace>-` |
| B01r | READY_LOCAL / BLOCKED_QA | B01 aplicado; mismo ID, precio, filtro y paginación | Ninguna adicional; depende de B01 | Mismo comando y lectura HTTP Laravel local | Igual que B01 |
| B02 | READY_LOCAL / BLOCKED_QA | Paquete explícito, componente, composición y precios | Crear paquete/componente namespace-scoped | Comando local validado con `item_type=package`; requiere publicación/despliegue para QA | Eliminar relación, paquete y tests sólo por prefijo |
| B04 | READY_LOCAL / BLOCKED_QA | Variante dedicada `no_savings` | Crear estudio namespace-scoped con precios iguales | Comando local validado; requiere publicación/despliegue para QA | Cleanup namespace-scoped de catálogo |
| B05 | READY_LOCAL / BLOCKED_QA | Variante dedicada `data_inconsistent` | Crear estudio namespace-scoped con precio público menor | Comando local validado; requiere publicación/despliegue para QA | Cleanup namespace-scoped de catálogo |
| A12 | BLOCKED_LOCAL | Enrolamiento/operator local para emitir bearer temporal con TTL soportado | Emitir token y leer antes/después de expiración natural | La prueba de expiración existente no pudo emitir token: `AuthorizationException` en `RegisteredOtpConsumerEnrollment`; no se acortó TTL ni se simuló PASS | Revocar al final y verificar cero PAT; no confundir revocación con expiración |

### Clasificación de ejecución

La ventana focalizada emitió y revocó accesos sólo dentro del namespace
dedicado. El estado queda:

- `PASS_QA`: B01, B01r, B02, B04 y B05 en la corrida focalizada del release `83882430`; la alegación histórica de `83825089` / `b5c828c87d99a595df6d9959449f0202cfede7dc` se conserva sin sustituirla.
- `PASS_QA`: B18. La corrida QA del release `83882430` queda registrada por separado de las corridas locales; no se repite.
- `PASS_QA`: A12, B03 y B03r, en la corrida focalizada del release `83882430`; A12 acredita expiración natural, no sólo revocación.
- Los PASS anteriores no se repiten.

La preparación B01/B02/B04/B05 usa la interfaz local nueva
`akubica:rc2-catalog-fixtures` y sólo crea recursos con el prefijo dedicado;
no sustituye la acreditación QA. B18 no debe ejecutarse en serie. A12 no debe
declararse PASS por revocación: la variante pendiente es expiración natural.

## Trabajo local de esta iteración

- `Rc2CatalogFixturesCommand`: dry-run/apply/cleanup, namespace dedicado,
  paquete explícito B02 y variantes de precio B04/B05. No toca catálogo legacy.
- `MedicalServiceCartService`: reintento transaccional MySQL limitado a tres
  intentos para convertir el deadlock concurrente en el conflicto contractual.
- B18 Laravel/MySQL: antes `201/500` por deadlock; después `201/409`, una fila
  persistida y cleanup explícito. Código de salida de la prueba: 0.
- Preparación de catálogo: 3 pruebas, 24 assertions, código 0; incluye
  dry-run sin escrituras, apply idempotente, lecturas B01/B02/B04/B05,
  cleanup aislado y rechazo de namespace legacy.
- A12: el bloqueo histórico queda superado únicamente para la variante QA de
  expiración natural descrita en la evidencia nueva; la corrida histórica de
  revocación se conserva sin mezclarla.

## Integridad de la evidencia

- SHA QA histórico conservado: `17313693ec1f48f4de0663a7bf8a67c615b3673a`.
- B18 QA verificado: release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73`.
- A12/B03/B03r QA verificados en la misma release/SHA; evidencia nueva en
  `RESULTADOS-VENTANA-A12-B03-20261124.md`.
- B03 conserva la assertion congelada de rev7: `savings_cents` debe ser
  `null` cuando `comparison_status=public_price_unavailable`; la propiedad no
  tiene que estar ausente.
- La incidencia histórica del primer reset con exit 1 queda documentada por
  separado; el reset final de esta corrida fue PASS y confirmó cero temporales.
- Identity QA de B18: release y SHA coincidentes mediante `REVISION`/`inventory identity`.
- Las evidencias locales se distinguen explícitamente de las QA.
- Fixtures base, manifest legacy, A4, OpenAPI y rev7 permanecen intactos.
- Batch 2 continúa abierto; BLQ-A1-01, AJ-B y los 98 fallos históricos no se
  cierran por esta matriz.

## Conciliación de seguridad y siguiente bloque — 2026-11-24

- La conciliación de los nueve PAT de B18 es **LIMITADA**: la consulta
  independiente actual acredita cero PAT del conjunto dedicado y el reset
  posterior reportó `status=ok/deleted=29`, pero no se conservaron los nueve
  IDs individuales. Por ello no se puede demostrar la ausencia de cada ID ni
  atribuir qué operación eliminó cada uno. La primera ejecución del reset
  terminó con exit `1` después de retirar el state file mientras aún se
  observaban nueve PAT; el fallo histórico se conserva.
- La revisión local del aprovisionador confirma que la secuencia prevista
  elimina PAT antes de `assertCleanupComplete` y elimina `access-state.json`
  después de la aserción. La observación QA no es reproducible por evidencia
  individual suficiente; no se cambia runtime ni se despliega en esta
  conciliación. La emisión de nuevos accesos queda detenida únicamente por
  esta limitación.
- Siguiente bloque preparado, sin ejecución: B09, B14, B23 y B26. B09/B14
  usan `machine_native` + `native_membership_regular` sobre customer/binding
  dedicado; B23/B26 usan `machine_credential` + `bearer_none` en el customer
  dedicado `membership_none`. B14 depende de la membresía activa de B09;
  B26 depende de B12+B23 y no es aislable como prueba independiente.
- Rutas contractuales: B09 y B26 `GET /api/v1/memberships/status`; B14
  `POST /api/v1/cart/items` y espera `409/MEMBERSHIP_ALREADY_ACTIVE`; B23
  `GET /api/v1/checkout/prepare?service_line=medical_services` y exige
  `200`, `assistant_can_pay=false`, sin `payment_url`, sin gateway y sin
  efectos externos.
- Estado: `PAT_RECONCILIATION_LIMITED / ACCESS_ISSUANCE_BLOCKED`; B09/B14/B23/B26
  permanecen BLOCKED hasta disponer de trazabilidad por PAT y precondiciones
  verificables. R02b/R03b siguen siendo incidentales. B24, OTP/step-up,
  ATIX, R05 y R08 requieren intervención externa y permanecen separados.
- Evidencia detallada: `RESULTADOS-CONCILIACION-PAT-B09-B26-20261124.md`.
- Corrección local de recuperabilidad preparada en `AkubicaUatAccessProvisioner`
  y `FunctionalWindow2CustodyCommand`; valida pérdida de state con inventario
  durable sanitizado en MySQL sintético. B09/B14/B23/B26 siguen BLOCKED en QA
  hasta publicar/desplegarla.
