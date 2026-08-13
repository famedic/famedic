# TI-10 - UAT fixtures staging

## Identidad

- Tarea: TI-10 - Materializacion/control de fixtures pre-UAT
- Fecha: 2026-08-13T14:07:58-06:00
- Environment: staging
- Forge release runtime: `75401580`
- SHA runtime: `0b46a096a3902630e6ae0e3088c266c790d31b0f`
- Namespace: `akubica-uat-v1`

## Alcance

Esta evidencia documenta el cierre operativo de fixtures sinteticos UAT. No contiene emails, telefonos, tokens, OTP, grants, signed URLs, cookies, passwords, DSN, PII real ni metadata completa del manifest.

## Preflight

- Dry-run: reportado como PASS antes del apply.
- Purity dry-run: reportada como PASS antes del apply.
- Production: no utilizada.
- Reset: no ejecutado.

Nota de custodia: inventory after, manifest validation, storage validation, smoke read-only y ephemeral records fueron verificados directamente en staging por ejecucion operativa manual sanitizada. Esta evidencia registra solo conteos, estados y checks booleanos; no incluye PII ni metadata completa.

## Apply

Comando ejecutado externamente en staging:

```bash
php artisan akubica:uat-fixtures --apply --confirm=akubica-uat-v1
```

Resultado verificado:

| Campo | Valor |
|---|---|
| status | `ok` |
| action | `apply` |
| namespace | `akubica-uat-v1` |
| created | `38` |
| updated | `0` |
| deleted | `0` |
| fixture_status | `active` |
| fixture_expired | `false` |
| plan_hash | `ac8018392cdc51b185c49d6cc4fd98891b21b133a5555a50ce348e7b748361bb` |

## Manifest

| Control | Valor observado | Resultado |
|---|---|---|
| manifest | `present` | PASS |
| namespace | `akubica-uat-v1` | PASS |
| status | `active` | PASS |
| fixture_version | `1` | PASS |
| expires_at | `2026-08-27T14:05:06-06:00` | PASS |
| expired | `false` | PASS |
| plan_hash metadata | `not_stored` | PASS |
| ids_top_level_count | `17` | PASS |
| storage_paths_count | `7` | PASS |
| storage_hashes_count | `7` | PASS |
| natural_key_hashes_count | `7` | PASS |
| metadata completa | no impresa | PASS |

El manifest conserva inventario suficiente para reset futuro mediante IDs tecnicos, hashes, rutas allowlisted y claves naturales, segun implementacion EUL-U01 y cobertura automatizada existente.

## Inventory after

| Entidad | Conteo verificado | Resultado |
|---|---:|---|
| manifest | `1 active` | PASS |
| created records total | `38` | PASS |
| users synthetic | `2` | PASS |
| regular_accounts | `2` | PASS |
| customers synthetic | `2` | PASS |
| contacts | `2` | PASS |
| addresses | `2` | PASS |
| tax profiles | `2` | PASS |
| laboratory tests | `2` | PASS |
| categories | `1` | PASS |
| stores | `1` | PASS |
| cart items | `2` | PASS |
| checkout drafts | `1` | PASS |
| orders/purchases | `5` | PASS |
| purchase items | `5` | PASS |
| invoice requests | `2` | PASS |
| coupons | `3` | PASS |
| coupon assignments | `2` | PASS |

## Storage

| Control | Valor observado | Resultado |
|---|---|---|
| disk | `local` | PASS |
| prefix | `akubica-uat-v1/` | PASS |
| manifest_paths | `7` | PASS |
| storage_files | `7` | PASS |
| paths_outside_prefix | `0` | PASS |
| hash_count | `7` | PASS |
| hash_mismatches | `0` | PASS |
| contenido PDF/XML | no abierto ni impreso | PASS |

## Smoke read-only

| Caso | Metodo | Resultado |
|---|---|---|
| catalog_tests_exist | consulta DB read-only por IDs del manifest | `true` |
| catalog_category_exists | consulta DB read-only por ID del manifest | `true` |
| catalog_store_exists | consulta DB read-only por ID del manifest | `true` |
| purchase_results_ready_exists | consulta DB read-only por escenario sintetico | `true` |
| purchase_results_pending_exists | consulta DB read-only por escenario sintetico | `true` |
| purchase_invoice_ready_exists | consulta DB read-only por escenario sintetico | `true` |
| purchase_invoice_request_pending_exists | consulta DB read-only por escenario sintetico | `true` |
| purchase_foreign_order_exists | consulta DB read-only por escenario sintetico | `true` |
| invoice_request_pending_exists | consulta DB read-only por ID del manifest | `true` |
| foreign_invoice_request_exists | consulta DB read-only por ID del manifest | `true` |
| ownership_primary_foreign_distinct | comparacion read-only de IDs internos | `true` |
| foreign_order_belongs_to_foreign | consulta DB read-only por relacion interna | `true` |

No se usaron credenciales reales ni endpoints mutantes. Los checks fueron read-only y sanitizados.

## Ephemeral records

| Recurso | Conteo verificado | Resultado |
|---|---:|---|
| pat_tokens | `0` | PASS |
| otp_challenges | `0` | PASS |
| otp_delivery_operations | `0` | PASS |
| otp_rate_limits | `0` | PASS |
| step_up_grants | `0` | PASS |
| secure_links | `0` | PASS |
| idempotency_records_recorded | `0` | PASS |

## Side effects

- No collision errors: verificado por apply `status=ok`.
- Created/updated/deleted: `38/0/0`.
- Queue/SMS/Mail/HTTP externo/Stripe/GDA/Vonage: cubierto por los 56 tests EUL-U01; no se afirma telemetry directa de staging para estos servicios.

## Expiry y reset

- TTL: 14 dias.
- Expires: `2026-08-27T14:05:06-06:00`.
- Prune automatico: no existe.
- Reset ejecutado: no.
- Motivo: el set debe quedar activo para pre-UAT.

Procedimiento posterior:

```bash
php artisan akubica:uat-fixtures --reset --confirm=akubica-uat-v1
```

Ejecutar reset controlado al terminar UAT o al llegar expiry. Antes de UAT, verificar manifest `active` y `fixture_expired=false`. Si UAT ocurre despues de `expires_at`, revalidar/rematerializar segun runbook; no asumir vigencia.

## Estado final

| Control | Estado |
|---|---|
| fixtures_active | `true` |
| manifest_active | `true` |
| fixture_expired | `false` |
| storage_files | `7` |
| ready_pre_uat | `true`, condicionado a TI-11 si sigue abierto |

## Sanitizacion

PASS. Esta evidencia no incluye valores reales de email, telefono, password, token, OTP, cookie, grant, signed URL, DSN, PII ni metadata completa del manifest. Nombres de variables/configuracion, namespace, release, SHA, conteos y hashes estan permitidos.

## Resultado

TI-10 LISTO PARA CIERRE. El apply quedo verificado, los fixtures permanecen activos para pre-UAT y reset queda documentado pero no ejecutado.
