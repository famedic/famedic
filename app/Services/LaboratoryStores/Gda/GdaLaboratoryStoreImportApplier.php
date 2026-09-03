<?php

namespace App\Services\LaboratoryStores\Gda;

use App\Models\LaboratoryCapability;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreImportRow;
use App\Models\LaboratoryStoreImportRun;
use App\Models\LaboratoryStoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GdaLaboratoryStoreImportApplier
{
    private const SOURCE = 'gda';

    private const DIRECTORY_SHEET = 'DIRECTORIO';

    private const UPDATE_FIELDS = [
        'name',
        'state',
        'address',
        'street',
        'exterior_number',
        'interior_number',
        'neighborhood',
        'municipality',
        'city',
        'postal_code',
        'phone',
        'latitude',
        'longitude',
        'source',
        'external_key',
        'raw_import_payload',
    ];

    public function __construct(
        private readonly GdaStringNormalizer $normalizer,
    ) {}

    public function apply(int $runId, string $path, string $brand, string $confirmHash): array
    {
        $run = $this->validatedRun($runId, $path, $brand, $confirmHash);
        $rows = $this->validatedRows($run, $brand);
        $this->assertNoDbDrift($rows, $brand);

        return DB::transaction(function () use ($run, $rows) {
            $run->update(['status' => LaboratoryStoreImportRun::STATUS_APPLYING]);
            $capabilities = $this->ensureCapabilities();
            $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
            $storesByName = [];

            foreach ($rows as $row) {
                $result = $this->applyRow($row, $capabilities, $storesByName);
                $summary[$result]++;
            }

            $run->update([
                'dry_run' => false,
                'status' => LaboratoryStoreImportRun::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return $summary;
        });
    }

    public function exportSqlPreview(int $runId, string $path, string $brand, string $confirmHash, string $exportPath): string
    {
        $run = $this->validatedRun($runId, $path, $brand, $confirmHash);
        $rows = $this->validatedRows($run, $brand);
        $this->assertNoDbDrift($rows, $brand);

        $lines = [
            'START TRANSACTION;',
            '-- Generated preview only. Do not execute as an apply script.',
            '-- Source SHA256: '.$run->file_hash,
            '-- Brand: '.$brand,
            '',
        ];

        foreach ($rows as $row) {
            $planned = $this->planned($row);
            $lines[] = '-- '.$row->excel_sheet.' row '.$row->excel_row.' '.$row->source_name;

            if ($row->action === LaboratoryStoreImportRow::ACTION_SKIP) {
                $lines[] = '-- SKIP: no business write.';
            } elseif ($row->rowIsAuxiliaryService() && $row->action === LaboratoryStoreImportRow::ACTION_MANUAL_REVIEW) {
                $lines[] = '-- UNRESOLVED AUXILIARY SERVICE: no business write in store apply.';
            } elseif ($row->rowIsAuxiliaryService()) {
                $lines[] = '-- Sync auxiliary service idempotently for store '.$row->matched_store_id.' inside the real applier transaction; no core store UPDATE.';
            } elseif ($row->classification === LaboratoryStoreImportRow::CLASSIFICATION_NEW) {
                $attributes = $this->insertAttributes($planned, $row);
                $lines[] = 'INSERT INTO laboratory_stores ('.implode(', ', array_keys($attributes)).', created_at, updated_at) VALUES ('.$this->sqlValues($attributes).');';
                $lines[] = '-- Sync hours, capabilities and auxiliary services for the inserted store inside the real applier transaction.';
            } elseif ($row->matched_store_id !== null) {
                $assignments = [];
                foreach ($this->updateAttributes($planned, $row) as $field => $value) {
                    $assignments[] = $field.' = '.$this->sql($value);
                }
                $assignments[] = 'updated_at = NOW()';
                $lines[] = 'UPDATE laboratory_stores SET '.implode(', ', $assignments).' WHERE id = '.$row->matched_store_id.' AND brand = '.$this->sql($row->brand).';';
                $lines[] = '-- Sync hours, capabilities and auxiliary services idempotently for store '.$row->matched_store_id.'.';
            }

            $lines[] = '';
        }

        $lines[] = '-- Preview ends with ROLLBACK by design.';
        $lines[] = 'ROLLBACK;';

        $this->writeStoragePath($exportPath, implode("\n", $lines)."\n");

        return $exportPath;
    }

    public function exportRollbackSql(int $runId, string $exportPath): string
    {
        $run = LaboratoryStoreImportRun::query()->with('rows')->findOrFail($runId);
        $lines = [
            'START TRANSACTION;',
            '-- Generated rollback preview only. Review before executing manually.',
            '-- Run ID: '.$run->id,
            '-- Source SHA256: '.$run->file_hash,
            '',
        ];

        foreach ($run->rows()->whereNotNull('apply_status')->orderByDesc('id')->get() as $row) {
            $lines[] = '-- '.$row->excel_sheet.' row '.$row->excel_row.' '.$row->source_name;

            if ($row->apply_status === LaboratoryStoreImportRow::APPLY_STATUS_CREATED && $row->applied_store_id !== null) {
                $lines[] = 'DELETE FROM laboratory_store_capability WHERE laboratory_store_id = '.$row->applied_store_id.';';
                $lines[] = 'DELETE FROM laboratory_store_hours WHERE laboratory_store_id = '.$row->applied_store_id.';';
                $lines[] = 'DELETE FROM laboratory_store_services WHERE laboratory_store_id = '.$row->applied_store_id.';';
                $lines[] = 'DELETE FROM laboratory_stores WHERE id = '.$row->applied_store_id.' AND NOT EXISTS (SELECT 1 FROM laboratory_appointments WHERE laboratory_store_id = '.$row->applied_store_id.');';
            } elseif (in_array($row->apply_status, [LaboratoryStoreImportRow::APPLY_STATUS_UPDATED, LaboratoryStoreImportRow::APPLY_STATUS_UNCHANGED], true) && $row->before_snapshot !== null) {
                $assignments = [];
                foreach ($this->restoreAttributes($row->before_snapshot) as $field => $value) {
                    $assignments[] = $field.' = '.$this->sql($value);
                }
                $lines[] = 'UPDATE laboratory_stores SET '.implode(', ', $assignments).' WHERE id = '.$row->applied_store_id.';';
                $lines[] = '-- Restore hours/capabilities/services from before_snapshot JSON if they were modified.';
            } else {
                $lines[] = '-- No rollback write needed.';
            }

            $lines[] = '';
        }

        $lines[] = '-- Rollback preview ends with ROLLBACK by design.';
        $lines[] = 'ROLLBACK;';

        $this->writeStoragePath($exportPath, implode("\n", $lines)."\n");

        return $exportPath;
    }

    public function backupBrand(string $brand, string $exportPath): string
    {
        $stores = LaboratoryStore::query()
            ->with(['hours', 'capabilities', 'services'])
            ->where('brand', $brand)
            ->orderBy('id')
            ->get()
            ->map(fn (LaboratoryStore $store) => $this->snapshot($store))
            ->all();

        $this->writeStoragePath($exportPath, json_encode([
            'brand' => $brand,
            'generated_at' => now()->toISOString(),
            'stores' => $this->jsonSafe($stores),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $exportPath;
    }

    private function validatedRun(int $runId, string $path, string $brand, string $confirmHash): LaboratoryStoreImportRun
    {
        if ($brand !== 'olab') {
            throw new RuntimeException('BRAND_SCOPE_REQUIRED: apply is currently limited to --brand=olab.');
        }

        if (! is_file($path)) {
            throw new RuntimeException('SOURCE_FILE_NOT_FOUND');
        }

        $actualHash = hash_file('sha256', $path);

        if ($confirmHash === '' || ! hash_equals($actualHash, $confirmHash)) {
            throw new RuntimeException('SOURCE_FILE_CHANGED');
        }

        $run = LaboratoryStoreImportRun::query()->findOrFail($runId);

        if ($run->status !== LaboratoryStoreImportRun::STATUS_COMPLETED) {
            throw new RuntimeException('IMPORT_RUN_NOT_COMPLETED');
        }

        if ($run->brand_filter !== $brand) {
            throw new RuntimeException('WRONG_BRAND');
        }

        if (! hash_equals($run->file_hash, $actualHash)) {
            throw new RuntimeException('SOURCE_FILE_CHANGED');
        }

        return $run;
    }

    private function validatedRows(LaboratoryStoreImportRun $run, string $brand): \Illuminate\Support\Collection
    {
        $rows = $run->rows()->where('brand', $brand)->orderBy('excel_sheet')->orderBy('excel_row')->get();

        if ($rows->isEmpty()) {
            throw new RuntimeException('EMPTY_IMPORT_PLAN');
        }

        $blocked = $rows->filter(function (LaboratoryStoreImportRow $row) {
            if ($row->rowIsAuxiliaryService()) {
                return false;
            }

            return $row->action === LaboratoryStoreImportRow::ACTION_MANUAL_REVIEW
                || $row->classification === LaboratoryStoreImportRow::CLASSIFICATION_AMBIGUOUS
                || $row->classification === LaboratoryStoreImportRow::CLASSIFICATION_SOFT_DELETED_MATCH
                || $row->classification === LaboratoryStoreImportRow::CLASSIFICATION_INVALID
                || $row->resolution_source === LaboratoryStoreImportRow::RESOLUTION_SOURCE_INVALID;
        });

        if ($blocked->isNotEmpty()) {
            throw new RuntimeException('UNRESOLVED_IMPORT_PLAN');
        }

        return $rows;
    }

    private function assertNoDbDrift(\Illuminate\Support\Collection $rows, string $brand): void
    {
        foreach ($rows as $row) {
            if ($row->matched_store_id === null) {
                continue;
            }

            $store = LaboratoryStore::query()->withTrashed()->find($row->matched_store_id);

            if ($store === null) {
                throw new RuntimeException('STALE_IMPORT_PLAN: matched store missing for row '.$row->id);
            }

            $storeBrand = is_string($store->brand) ? $store->brand : $store->brand?->value;

            if ($storeBrand !== $brand) {
                throw new RuntimeException('STALE_IMPORT_PLAN: matched store brand changed for row '.$row->id);
            }

            $snapshot = $row->source_store_snapshot;

            if (! is_array($snapshot) || $snapshot === []) {
                throw new RuntimeException('STALE_IMPORT_PLAN: missing source store snapshot for row '.$row->id);
            }

            foreach (['id', 'name', 'brand', 'updated_at'] as $field) {
                $current = $this->fingerprint($store)[$field] ?? null;

                if ((string) ($snapshot[$field] ?? '') !== (string) $current) {
                    throw new RuntimeException('STALE_IMPORT_PLAN: matched store changed for row '.$row->id);
                }
            }
        }
    }

    private function applyRow(LaboratoryStoreImportRow $row, array $capabilities, array &$storesByName): string
    {
        $planned = $this->planned($row);

        if ($row->rowIsAuxiliaryService()) {
            return $this->applyAuxiliaryRow($row, $planned, $capabilities, $storesByName);
        }

        if ($row->action === LaboratoryStoreImportRow::ACTION_SKIP) {
            $row->update([
                'apply_status' => LaboratoryStoreImportRow::APPLY_STATUS_SKIPPED,
                'applied_action' => $row->action,
                'applied_at' => now(),
            ]);

            return 'skipped';
        }

        if ($row->classification === LaboratoryStoreImportRow::CLASSIFICATION_NEW) {
            $store = LaboratoryStore::query()->create($this->insertAttributes($planned, $row));
            $this->syncStoreChildren($store, $planned, $row, $capabilities);
            $after = $this->snapshot($store->refresh());
            $this->rememberDirectoryStore($storesByName, $row, $planned, $store);
            $row->update([
                'apply_status' => LaboratoryStoreImportRow::APPLY_STATUS_CREATED,
                'applied_action' => LaboratoryStoreImportRow::ACTION_CREATE,
                'applied_store_id' => $store->id,
                'before_snapshot' => null,
                'after_snapshot' => $this->jsonSafe($after),
                'applied_at' => now(),
            ]);

            return 'created';
        }

        $store = LaboratoryStore::query()->with(['hours', 'capabilities', 'services'])->findOrFail($row->matched_store_id);
        $before = $this->snapshot($store);
        $changed = false;

        $store->fill($this->updateAttributes($planned, $row));
        $changed = $store->isDirty();
        $store->save();

        $childrenChanged = $this->syncStoreChildren($store, $planned, $row, $capabilities);
        $after = $this->snapshot($store->refresh()->load(['hours', 'capabilities', 'services']));
        $status = $changed || $childrenChanged
            ? LaboratoryStoreImportRow::APPLY_STATUS_UPDATED
            : LaboratoryStoreImportRow::APPLY_STATUS_UNCHANGED;
        $this->rememberDirectoryStore($storesByName, $row, $planned, $store);

        $row->update([
            'apply_status' => $status,
            'applied_action' => $changed || $childrenChanged ? LaboratoryStoreImportRow::ACTION_UPDATE : LaboratoryStoreImportRow::ACTION_NONE,
            'applied_store_id' => $store->id,
            'before_snapshot' => $this->jsonSafe($before),
            'after_snapshot' => $this->jsonSafe($after),
            'applied_at' => now(),
        ]);

        return $status === LaboratoryStoreImportRow::APPLY_STATUS_UPDATED ? 'updated' : 'unchanged';
    }

    private function applyAuxiliaryRow(LaboratoryStoreImportRow $row, array $planned, array $capabilities, array $storesByName): string
    {
        $store = $this->resolveAuxiliaryStore($row, $planned, $storesByName);

        if ($store === null || $row->action === LaboratoryStoreImportRow::ACTION_SKIP) {
            $row->update([
                'apply_status' => LaboratoryStoreImportRow::APPLY_STATUS_SKIPPED,
                'applied_action' => $row->action,
                'applied_at' => now(),
            ]);

            return 'skipped';
        }

        $store->load(['hours', 'capabilities', 'services']);
        $before = $this->snapshot($store);
        $childrenChanged = $this->syncStoreChildren($store, $planned, $row, $capabilities);
        $after = $this->snapshot($store->refresh()->load(['hours', 'capabilities', 'services']));
        $status = $childrenChanged
            ? LaboratoryStoreImportRow::APPLY_STATUS_UPDATED
            : LaboratoryStoreImportRow::APPLY_STATUS_UNCHANGED;

        $row->update([
            'apply_status' => $status,
            'applied_action' => $childrenChanged ? LaboratoryStoreImportRow::ACTION_UPDATE : LaboratoryStoreImportRow::ACTION_NONE,
            'applied_store_id' => $store->id,
            'before_snapshot' => $this->jsonSafe($before),
            'after_snapshot' => $this->jsonSafe($after),
            'applied_at' => now(),
        ]);

        return $status === LaboratoryStoreImportRow::APPLY_STATUS_UPDATED ? 'updated' : 'unchanged';
    }

    private function resolveAuxiliaryStore(LaboratoryStoreImportRow $row, array $planned, array $storesByName): ?LaboratoryStore
    {
        foreach ($this->lookupNames($row->source_name, $planned['name'] ?? null) as $name) {
            $id = $storesByName[$name] ?? null;

            if ($id !== null) {
                return LaboratoryStore::query()->find($id);
            }
        }

        if ($row->matched_store_id !== null && $row->action !== LaboratoryStoreImportRow::ACTION_MANUAL_REVIEW) {
            return LaboratoryStore::query()->find($row->matched_store_id);
        }

        return null;
    }

    private function rememberDirectoryStore(array &$storesByName, LaboratoryStoreImportRow $row, array $planned, LaboratoryStore $store): void
    {
        if ($row->excel_sheet !== self::DIRECTORY_SHEET) {
            return;
        }

        foreach ($this->lookupNames($row->source_name, $planned['name'] ?? $store->name) as $name) {
            $storesByName[$name] = $store->id;
        }
    }

    private function lookupNames(?string ...$names): array
    {
        $lookups = [];

        foreach ($names as $name) {
            $normalized = $this->normalizer->normalize($name);

            if ($normalized === '') {
                continue;
            }

            $lookups[] = $normalized;
            $lookups[] = trim(preg_replace('/^(flc|queretaro)\s+/', '', $normalized) ?? $normalized);
        }

        return array_values(array_unique(array_filter($lookups)));
    }

    private function planned(LaboratoryStoreImportRow $row): array
    {
        return $row->planned_payload ?? $row->diff['planned'] ?? [];
    }

    private function insertAttributes(array $planned, LaboratoryStoreImportRow $row): array
    {
        $attributes = $this->storeAttributes($planned, $row);
        $attributes = ['brand' => $row->brand] + $attributes;
        $attributes['weekly_hours'] = $this->legacyHours($planned['hours'] ?? [], range(1, 5));
        $attributes['saturday_hours'] = $this->legacyHours($planned['hours'] ?? [], [6]);
        $attributes['sunday_hours'] = $this->legacyHours($planned['hours'] ?? [], [7]);
        $attributes['google_maps_url'] = $this->googleMapsUrl($planned);

        return $attributes;
    }

    private function updateAttributes(array $planned, LaboratoryStoreImportRow $row): array
    {
        $attributes = $this->storeAttributes($planned, $row);

        foreach ($row->invalid_fields ?? [] as $field) {
            unset($attributes[$field]);
        }

        return $attributes;
    }

    private function storeAttributes(array $planned, LaboratoryStoreImportRow $row): array
    {
        $attributes = [
            'name' => $planned['name'] ?? $row->source_name,
            'brand' => $row->brand,
            'state' => $planned['state'] ?? '',
            'address' => $planned['address'] ?? '',
            'street' => $planned['street'] ?? null,
            'exterior_number' => $planned['exterior_number'] ?? null,
            'interior_number' => $planned['interior_number'] ?? null,
            'neighborhood' => $planned['neighborhood'] ?? null,
            'municipality' => $planned['municipality'] ?? null,
            'city' => $planned['city'] ?? null,
            'postal_code' => $planned['postal_code'] ?? null,
            'phone' => $planned['phone'] ?? null,
            'latitude' => $planned['latitude'] ?? null,
            'longitude' => $planned['longitude'] ?? null,
            'source' => self::SOURCE,
            'external_key' => null,
            'raw_import_payload' => $row->raw_payload,
        ];

        return array_intersect_key($attributes, array_flip(self::UPDATE_FIELDS));
    }

    private function ensureCapabilities(): array
    {
        $result = [];
        $order = 0;

        foreach (GdaCapabilityCatalog::MAP as $column => $capability) {
            $model = LaboratoryCapability::query()->updateOrCreate(
                ['slug' => $capability['slug']],
                [
                    'name' => $capability['name'],
                    'source_column' => $column,
                    'sort_order' => $order++,
                    'is_active' => true,
                ],
            );
            $result[$capability['slug']] = $model->id;
        }

        return $result;
    }

    private function syncStoreChildren(LaboratoryStore $store, array $planned, LaboratoryStoreImportRow $row, array $capabilities): bool
    {
        $beforeHours = $store->hours()->count();
        $beforeServices = $store->services()->count();
        $beforeCapabilities = $store->capabilities()->count();

        if (! $row->rowIsAuxiliaryService()) {
            foreach ($planned['hours'] ?? [] as $hour) {
                $store->hours()->updateOrCreate(
                    ['day_of_week' => $hour['day_of_week'], 'source' => self::SOURCE],
                    [
                        'opens_at' => $hour['opens_at'] ?? null,
                        'closes_at' => $hour['closes_at'] ?? null,
                        'is_closed' => (bool) ($hour['is_closed'] ?? false),
                        'raw_text' => $hour['raw_text'] ?? null,
                    ],
                );
            }

            $enabled = collect($planned['capabilities'] ?? [])
                ->map(fn (string $slug) => $capabilities[$slug] ?? null)
                ->filter()
                ->values()
                ->all();
            $store->capabilities()->sync($enabled);
        }

        if ($row->rowIsAuxiliaryService()) {
            LaboratoryStoreService::query()->updateOrCreate(
                [
                    'laboratory_store_id' => $store->id,
                    'service_type' => $planned['service_type'] ?? null,
                    'source' => self::SOURCE,
                    'name' => $planned['name'] ?? null,
                ],
                [
                    'schedule_raw' => $planned['schedule_raw'] ?? null,
                    'phone' => $planned['phone'] ?? null,
                    'address' => $planned['address'] ?? null,
                    'metadata' => ['excel_row' => $row->excel_row, 'excel_sheet' => $row->excel_sheet],
                    'is_active' => true,
                ],
            );
        }

        $store->load(['hours', 'capabilities', 'services']);

        return $beforeHours !== $store->hours->count()
            || $beforeCapabilities !== $store->capabilities->count()
            || $beforeServices !== $store->services->count();
    }

    private function legacyHours(array $hours, array $days): string
    {
        $selected = collect($hours)->first(fn (array $hour) => in_array($hour['day_of_week'] ?? null, $days, true) && ! ($hour['is_closed'] ?? false));

        if ($selected === null) {
            return 'Cerrado';
        }

        return substr((string) ($selected['opens_at'] ?? ''), 0, 5).'-'.substr((string) ($selected['closes_at'] ?? ''), 0, 5);
    }

    private function googleMapsUrl(array $planned): string
    {
        if (($planned['latitude'] ?? null) !== null && ($planned['longitude'] ?? null) !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='.$planned['latitude'].','.$planned['longitude'];
        }

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string) ($planned['address'] ?? $planned['name'] ?? ''));
    }

    private function jsonSafe(array $value): array
    {
        return json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
    }

    private function snapshot(LaboratoryStore $store): array
    {
        return [
            'store' => $this->fingerprint($store) + collect($store->only(self::UPDATE_FIELDS))->all() + [
                'google_maps_url' => $store->google_maps_url,
                'weekly_hours' => $store->weekly_hours,
                'saturday_hours' => $store->saturday_hours,
                'sunday_hours' => $store->sunday_hours,
            ],
            'hours' => $store->relationLoaded('hours') ? $store->hours->map->only(['day_of_week', 'opens_at', 'closes_at', 'is_closed', 'raw_text', 'source'])->values()->all() : [],
            'capabilities' => $store->relationLoaded('capabilities') ? $store->capabilities->pluck('slug')->sort()->values()->all() : [],
            'services' => $store->relationLoaded('services') ? $store->services->map->only(['service_type', 'name', 'schedule_raw', 'phone', 'address', 'metadata', 'is_active', 'source'])->values()->all() : [],
        ];
    }

    public function fingerprint(LaboratoryStore $store): array
    {
        $storeBrand = is_string($store->brand) ? $store->brand : $store->brand?->value;

        return [
            'id' => $store->id,
            'name' => $store->name,
            'brand' => $storeBrand,
            'updated_at' => optional($store->updated_at)->toJSON(),
        ];
    }

    private function restoreAttributes(array $snapshot): array
    {
        return collect($snapshot['store'] ?? [])
            ->only([...self::UPDATE_FIELDS, 'google_maps_url', 'weekly_hours', 'saturday_hours', 'sunday_hours'])
            ->all();
    }

    private function sqlValues(array $attributes): string
    {
        $values = [];

        foreach ($attributes as $value) {
            $values[] = $this->sql($value);
        }

        $values[] = 'NOW()';
        $values[] = 'NOW()';

        return implode(', ', $values);
    }

    private function sql(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return DB::getPdo()->quote(json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        if (is_numeric($value) && ! is_string($value)) {
            return (string) $value;
        }

        return DB::getPdo()->quote((string) $value);
    }

    private function writeStoragePath(string $path, string $contents): void
    {
        if (str_starts_with($path, storage_path('app'))) {
            $absolutePath = $path;
        } else {
            $absolutePath = Storage::disk('local')->path($path);
        }

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0775, true);
        }

        file_put_contents($absolutePath, $contents);
    }
}
