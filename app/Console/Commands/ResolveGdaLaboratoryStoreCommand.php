<?php

namespace App\Console\Commands;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreImportResolution;
use App\Models\User;
use App\Services\LaboratoryStores\Gda\GdaStringNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResolveGdaLaboratoryStoreCommand extends Command
{
    protected $signature = 'laboratory:stores-gda-resolve
        {--brand= : Brand scope for the Excel row}
        {--store= : Store name exactly as it appears in the GDA file}
        {--decision= : match, create, or skip}
        {--db-id= : laboratory_stores ID required for match}
        {--notes= : Business or operations confirmation note}
        {--source=gda : Import source}
        {--file-hash= : Optional SHA-256 source file hash}
        {--external-key= : Optional external source key}
        {--resolved-by= : Optional users.id for the resolver}';

    protected $description = 'Register a manual GDA laboratory store import resolution without mutating business store data';

    public function handle(GdaStringNormalizer $normalizer): int
    {
        $brand = $normalizer->normalizeBrand($this->optionString('brand'));
        $sourceName = $this->optionString('store');
        $decision = $this->decision($this->optionString('decision'));
        $source = $this->optionString('source') ?: 'gda';
        $dbId = $this->optionString('db-id');

        if ($brand === null || ! in_array($brand, array_map(fn (LaboratoryBrand $brand) => $brand->value, LaboratoryBrand::cases()), true)) {
            $this->error('A valid --brand is required.');

            return self::FAILURE;
        }

        if ($sourceName === null || trim($sourceName) === '') {
            $this->error('A non-empty --store is required.');

            return self::FAILURE;
        }

        if ($decision === null) {
            $this->error('A valid --decision is required: match, create, or skip.');

            return self::FAILURE;
        }

        if ($decision === LaboratoryStoreImportResolution::DECISION_MATCH_EXISTING && ($dbId === null || ! ctype_digit($dbId))) {
            $this->error('--db-id is required for decision=match.');

            return self::FAILURE;
        }

        if ($decision !== LaboratoryStoreImportResolution::DECISION_MATCH_EXISTING && $dbId !== null) {
            $this->error('--db-id is only allowed for decision=match.');

            return self::FAILURE;
        }

        $matchedStore = null;

        if ($dbId !== null) {
            $matchedStore = LaboratoryStore::query()->withTrashed()->find((int) $dbId);

            if ($matchedStore === null) {
                $this->error("laboratory_stores ID {$dbId} does not exist.");

                return self::FAILURE;
            }

            $storeBrand = is_string($matchedStore->brand) ? $matchedStore->brand : $matchedStore->brand?->value;

            if ($storeBrand !== $brand) {
                $this->error("laboratory_stores ID {$dbId} belongs to brand {$storeBrand}, not {$brand}.");

                return self::FAILURE;
            }
        }

        $resolvedBy = $this->resolvedBy();

        if ($this->optionString('resolved-by') !== null && $resolvedBy === null) {
            $this->error('--resolved-by must be a numeric users.id.');

            return self::FAILURE;
        }

        if ($resolvedBy !== null && ! User::query()->whereKey($resolvedBy)->exists()) {
            $this->error("users ID {$resolvedBy} does not exist.");

            return self::FAILURE;
        }

        $normalizedName = $normalizer->normalize($sourceName);
        $externalKey = $this->blankToNull($this->optionString('external-key'));
        $fileHash = $this->blankToNull($this->optionString('file-hash'));
        $now = now();

        $resolution = DB::transaction(function () use ($brand, $decision, $externalKey, $fileHash, $matchedStore, $normalizedName, $now, $resolvedBy, $source, $sourceName) {
            LaboratoryStoreImportResolution::query()
                ->current()
                ->where('source', $source)
                ->where('brand', $brand)
                ->where('normalized_source_name', $normalizedName)
                ->where(function ($query) use ($externalKey) {
                    $externalKey === null
                        ? $query->whereNull('external_key')
                        : $query->where('external_key', $externalKey);
                })
                ->where(function ($query) use ($fileHash) {
                    $fileHash === null
                        ? $query->whereNull('source_file_hash')
                        : $query->where('source_file_hash', $fileHash);
                })
                ->update(['superseded_at' => $now]);

            return LaboratoryStoreImportResolution::query()->create([
                'source' => $source,
                'brand' => $brand,
                'source_name' => $sourceName,
                'normalized_source_name' => $normalizedName,
                'external_key' => $externalKey,
                'source_file_hash' => $fileHash,
                'decision' => $decision,
                'matched_store_id' => $matchedStore?->id,
                'notes' => $this->blankToNull($this->optionString('notes')),
                'resolved_by' => $resolvedBy,
                'resolved_at' => $now,
            ]);
        });

        $this->info('Manual resolution registered.');
        $this->line("Resolution ID: {$resolution->id}");
        $this->line("Scope: {$source} / {$brand} / {$normalizedName}");
        $this->line("Decision: {$resolution->decision}");

        if ($matchedStore?->trashed()) {
            $this->warn('Matched store is soft-deleted; this command did not restore it.');
        }

        return self::SUCCESS;
    }

    private function decision(?string $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'match' => LaboratoryStoreImportResolution::DECISION_MATCH_EXISTING,
            'create' => LaboratoryStoreImportResolution::DECISION_CREATE_NEW,
            'skip' => LaboratoryStoreImportResolution::DECISION_SKIP,
            default => null,
        };
    }

    private function resolvedBy(): ?int
    {
        $resolvedBy = $this->optionString('resolved-by');

        return $resolvedBy !== null && ctype_digit($resolvedBy) ? (int) $resolvedBy : null;
    }

    private function optionString(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
