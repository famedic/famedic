# GDA Laboratory Stores Import

This module supports the staged GDA laboratory store update project. The current production posture is deliberately conservative: dry-run planning, manual identity resolutions, SQL preview, and fixture-tested apply infrastructure exist, but real apply is disabled by default.

## Lifecycle

```text
Excel
  -> Parser
  -> Auto matcher
  -> Manual resolutions
  -> Import plan / audit run
  -> Validation gate
  -> Apply service
  -> DB transaction
```

The matcher never writes business data. Manual decisions are stored separately. The apply service is the only layer allowed to mutate `laboratory_stores`, `laboratory_store_hours`, `laboratory_capabilities`, `laboratory_store_capability`, or `laboratory_store_services`.

## Dry Run

```bash
php artisan laboratory:stores-gda-import storage/app/imports/DIRECTORIO.xlsx --dry-run --brand=olab
```

Dry-run writes only audit data:

- `laboratory_store_import_runs`
- `laboratory_store_import_rows`

It stores the automatic decision, the final decision, full planned payload, validation warnings, source file SHA-256, and a matched-store fingerprint for DB drift protection.

Without `--dry-run` or guarded `--apply`, the command aborts with:

```text
Apply mode is not enabled in this phase.
```

## Manual Resolutions

```bash
php artisan laboratory:stores-gda-resolve --brand=olab --store="ANZURES" --decision=match --db-id=40 --notes="Confirmado por operaciones"
```

Valid command decisions:

- `match`: stores `MATCH_EXISTING` and requires `--db-id`.
- `create`: stores `CREATE_NEW` and rejects `--db-id`.
- `skip`: stores `SKIP` and rejects `--db-id`.

Resolution scope is `source + brand + normalized_source_name`, with optional `external_key` and `source_file_hash`. A source name alone is never enough.

`MATCH_EXISTING` validates that the target store exists and belongs to the same brand. Soft-deleted targets are warned but never restored automatically. Replacing a resolution supersedes the old row with `superseded_at`; current decisions are rows where `superseded_at` is null.

Manual resolution has precedence over the auto matcher only when valid. Invalid or obsolete manual resolution produces `INVALID_RESOLUTION` and blocks apply.

## Apply Gate

Future apply command shape:

```bash
php artisan laboratory:stores-gda-import \
  storage/app/imports/DIRECTORIO.xlsx \
  --apply \
  --brand=swisslab \
  --run-id=123 \
  --confirm-hash=<sha256> \
  --confirm-apply=SWISSLAB
```

Apply is disabled unless config enables it:

```env
LABORATORY_GDA_IMPORT_APPLY_ENABLED=false
```

Default is `false` in `config/laboratory-stores.php`. Do not change real `.env` during planning phases.

Preconditions:

- feature flag enabled
- explicit `--brand` in the supported enum allowlist: `olab`, `swisslab`, `jenner`, `liacsa`, `azteca`
- explicit `--confirm-apply=<BRAND>` matching the requested brand in uppercase, for example `--brand=swisslab --confirm-apply=SWISSLAB`
- completed dry-run `--run-id`
- dry-run `brand_filter` must equal requested apply brand
- every non-null planned row brand in the run must equal requested apply brand
- current Excel SHA-256 equals run hash and `--confirm-hash`
- no `MANUAL_REVIEW`
- no `INVALID_RESOLUTION`
- no invalid store classification
- every matched store still exists
- every matched store still belongs to the same brand
- matched-store fingerprint still matches dry-run snapshot

If any precondition fails, apply aborts. No partial apply is allowed.

## Transaction

The apply service wraps the whole run in `DB::transaction(...)`. One failure rolls back store updates, creates, hours, capabilities, services, and per-row apply audit fields. Tests cover rollback after a failure following earlier writes.

## DB Drift

Each dry-run row with a matched store records:

- store id
- name
- brand
- `updated_at`

Apply compares that fingerprint before writing. If the row changed after dry-run, apply aborts with `STALE_IMPORT_PLAN`.

## File Hash

The dry-run stores SHA-256 for the reviewed Excel file. Apply recomputes the hash and also checks `--confirm-hash`. A mismatch aborts with `SOURCE_FILE_CHANGED`.

## Store Writes

`MATCH_EXISTING` updates by primary key. It never deletes and reinserts, so existing IDs are preserved.

Update whitelist:

- `name`
- `state`
- `address`
- `street`
- `exterior_number`
- `interior_number`
- `neighborhood`
- `municipality`
- `city`
- `postal_code`
- `phone`
- `latitude`
- `longitude`
- `source`
- `external_key`
- `raw_import_payload`

`brand` is not updated for existing stores. For `CREATE_NEW`, brand is inserted from the explicit row scope. `google_maps_url` is preserved for existing stores.

Invalid fields do not overwrite good existing values. Invalid or manual-review coordinates are kept null on new stores and are skipped on updates.

Field conflict protection is separate from identity resolution. A row can still be a valid `MATCH_EXISTING` while individual source fields are considered unsafe for update. When a geography conflict is detected, only the conflicting fields are omitted from the update; safe fields such as phone, coordinates, hours, capabilities, and services can still apply. The planned payload records:

- `field_conflicts.<field>.source_value`
- `field_conflicts.<field>.existing_value`
- `field_conflicts.<field>.reason`
- `field_conflicts.<field>.action = SKIPPED_CONFLICT`
- `skipped_fields`

Apply also writes `after_snapshot.field_safety.skipped_conflicts` with the existing values observed at apply time.

## Address Strategy

`address` remains the compatibility field used by existing UI, checkout, emails, PDFs, and admin surfaces. The importer generates it deterministically from the source address parts and updates it only through the whitelisted planned payload. Structured fields are stored alongside it for future UI/reporting improvements.

## Google Maps URL

Existing stores keep their current `google_maps_url`. New stores get a deterministic Google Maps search URL using valid coordinates when available, otherwise the generated address. No external API is called and no “real place URL” is invented.

## Hours

Apply writes normalized daily rows to `laboratory_store_hours` with ISO day numbers and raw text. For new stores it also fills legacy columns:

- `weekly_hours`
- `saturday_hours`
- `sunday_hours`

Existing legacy columns are not overwritten in this phase; current screens remain compatible.

## Capabilities

Apply creates or updates the 29 GDA capability catalog rows by stable `slug`, then syncs the pivot for each directory row. The Excel is treated as a GDA snapshot for stores included in the file. Repeated apply does not duplicate capabilities or pivot rows.

## Services

`HISTORIA CLINICO` and `OPTICAS` are handled as auxiliary services after the store identity has been resolved. They write idempotently into `laboratory_store_services`:

- `service_type = historia_clinica`
- `service_type = optica`
- source metadata including Excel row/sheet

They do not update core store identity fields.

## Missing Stores

Stores absent from the source are not soft-deleted, deactivated, or marked missing in this phase. Missing-from-source remains report-only until a separate explicit business decision approves it.

## SQL Preview

```bash
php artisan laboratory:stores-gda-import \
  storage/app/imports/DIRECTORIO.xlsx \
  --dry-run \
  --brand=swisslab \
  --export-sql=imports/swisslab-preview.sql
```

The preview file is not executed. It starts with `START TRANSACTION;`, includes source hash and brand comments, emits readable `INSERT`/`UPDATE` statements plus comments for sync operations, and ends with `ROLLBACK;` by design.

Example:

```sql
START TRANSACTION;
-- Generated preview only. Do not execute as an apply script.
-- Source SHA256: <sha256>
-- Brand: swisslab
UPDATE laboratory_stores SET name = 'MONTERREY', updated_at = NOW() WHERE id = 10 AND brand = 'swisslab';
-- SKIPPED_CONFLICT postal_code: postal_code 01080 is outside the expected range for Nuevo Leon
-- Preview ends with ROLLBACK by design.
ROLLBACK;
```

## Rollback Preview

```bash
php artisan laboratory:stores-gda-import \
  storage/app/imports/DIRECTORIO.xlsx \
  --run-id=123 \
  --brand=olab \
  --export-rollback=imports/olab-rollback.sql
```

Rollback preview is generated from apply audit snapshots for the requested brand scope. Created stores are removed only when no appointments reference them. Updated stores produce restoration SQL from `before_snapshot`, with comments for restoring children from JSON snapshots. The generated file also ends with `ROLLBACK;`.

Example:

```sql
START TRANSACTION;
-- Generated rollback preview only. Review before executing manually.
DELETE FROM laboratory_store_capability WHERE laboratory_store_id = 999;
DELETE FROM laboratory_store_hours WHERE laboratory_store_id = 999;
DELETE FROM laboratory_store_services WHERE laboratory_store_id = 999;
DELETE FROM laboratory_stores WHERE id = 999 AND NOT EXISTS (SELECT 1 FROM laboratory_appointments WHERE laboratory_store_id = 999);
ROLLBACK;
```

## Backup Strategy

The command can export a logical JSON backup for a brand before an approved apply window:

```bash
php artisan laboratory:stores-gda-import storage/app/imports/gda.xlsx \
  --brand=swisslab \
  --export-backup=imports/backups/swisslab-before-<timestamp>.json
```

Backup content includes the scoped brand stores, hours, capabilities, capability pivot identity, and services. SQL rollback preview is generated separately so operations can review manual MySQL scripts.

## Production

Production is not more permissive than local/test. It still requires feature flag, brand scope, run id, matching hash, and explicit confirmation. The flag is temporary and should only be enabled during an approved apply window.

## Baseline Regression Notes

The broad related command from FASE 3D.1 was:

```bash
php artisan test tests/Feature/Laboratory tests/Unit/GDA
```

Observed failures are classified as `PRE_EXISTING_BASELINE_FAILURE` for this initiative because the failing files and related production classes have no diff in the GDA Laboratory Stores worktree slice:

- `tests/Feature/Laboratory/EmptyLaboratoryCartRedirectTest.php`: `Documentation` mass assignment for `privacy_policy`.
- `tests/Feature/Laboratory/GdaResultsNotAvailableTest.php`: unfaked/invalid `infogda-fullV3` host caused connection failures.
- `tests/Feature/Laboratory/LaboratoryCheckoutAppointmentFirstFlowTest.php`: outside Laboratory Stores import scope.
- `tests/Feature/Laboratory/LaboratoryCheckoutAppointmentFirstPhase4Test.php`: outside Laboratory Stores import scope.
- `tests/Feature/Laboratory/LaboratoryCheckoutAppointmentFirstPhase5Test.php`: outside Laboratory Stores import scope.
- `tests/Feature/Laboratory/LaboratoryCheckoutFlowEligibilityTest.php`: outside Laboratory Stores import scope.
- `tests/Feature/Laboratory/PromoCodeCheckoutTest.php`: SQLite test DB reported missing `users` table.

No baseline failure was traced to parser, matcher, planner, manual resolutions, import runs, import rows, or the apply service added here.