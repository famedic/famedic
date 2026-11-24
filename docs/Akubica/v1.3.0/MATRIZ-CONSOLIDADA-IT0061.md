# IT-0061 — matriz consolidada por escenario

Conciliación documental sin nueva ventana, sin credenciales emitidas y sin
cambios de código, permisos o deploy.

## Resultados conservados

| Escenario | Estado | Release/SHA | Evidencia | Observación |
|---|---|---|---|---|
| B03 | PASS_LOCAL | candidato local `615b9c9403fecd811093c5a0723d5b9445c5432c` | `RESULTADOS-DIAGNOSTICO-FUNCIONAL-WINDOW2-LOCAL.md` | Variante `public_price_unavailable`; no es PASS QA |
| B03r | PASS_LOCAL | candidato local `615b9c9403fecd811093c5a0723d5b9445c5432c` | mismo informe local | Listado/detalle comprobados por identidad, filtro y paginación |
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

No se emiten credenciales en esta conciliación. Por tanto, el conjunto
priorizado queda sin nuevos casos ejecutables QA en esta etapa:

- `READY_LOCAL / BLOCKED_QA`: B01, B01r, B02, B04 y B05. La preparación local es revisable, pero QA exige desplegar el comando y realizar precheck namespace-scoped.
- `PASS_QA`: B18. La corrida QA del release `83882430` queda registrada por separado de las corridas locales; no se repite.
- `BLOCKED_LOCAL`: A12, hasta disponer del operador/enrolamiento sintético necesario para emitir el bearer con el TTL configurado.
  No se acortó el TTL ni se simuló expiración.
  y una selección aprobada para esperar el TTL efectivo.
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
- A12: intento focalizado bloqueado antes de la validación funcional por falta
  de enrolamiento/operator local; no se emitieron accesos.

## Integridad de la evidencia

- SHA QA histórico conservado: `17313693ec1f48f4de0663a7bf8a67c615b3673a`.
- B18 QA verificado: release `83882430`, SHA `49c72de20a53a9a4864b2ab9262d26db7f186e73`.
- Identity QA de B18: release y SHA coincidentes mediante `REVISION`/`inventory identity`.
- Las evidencias locales se distinguen explícitamente de las QA.
- Fixtures base, manifest legacy, A4, OpenAPI y rev7 permanecen intactos.
- Batch 2 continúa abierto; BLQ-A1-01, AJ-B y los 98 fallos históricos no se
  cierran por esta matriz.
