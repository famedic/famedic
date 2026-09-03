<?php

namespace App\Services\LaboratoryStores\Gda;

use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreImportResolution;
use App\Models\LaboratoryStoreImportRow;
use App\Models\LaboratoryStoreImportRun;
use Illuminate\Support\Facades\DB;

class GdaImportPlanner
{
    public function __construct(
        private readonly GdaExcelReader $reader,
        private readonly GdaStringNormalizer $normalizer,
        private readonly GdaPostalCodeNormalizer $postalCodeNormalizer,
        private readonly GdaPhoneNormalizer $phoneNormalizer,
        private readonly GdaCoordinateNormalizer $coordinateNormalizer,
        private readonly GdaScheduleParser $scheduleParser,
        private readonly GdaCapabilityParser $capabilityParser,
        private readonly GdaStoreMatcher $matcher,
    ) {}

    public function plan(string $path, ?string $brandFilter = null, bool $persistAudit = true): GdaImportPlan
    {
        $brandFilter = $this->normalizer->normalizeBrand($brandFilter);
        $parsed = $this->reader->read($path);
        $fileHash = hash_file('sha256', $path);
        $plannedRows = [];
        $directoryTotal = 0;
        $clinicalTotal = 0;
        $opticalTotal = 0;

        foreach ($parsed->stores as $row) {
            $brand = $this->normalizer->normalizeBrand($row->brand);

            if ($brandFilter !== null && $brand !== $brandFilter) {
                continue;
            }

            $directoryTotal++;
            $plannedRows[] = $this->planStoreRow($row, $brand, $fileHash);
        }

        foreach ($parsed->clinicalHistoryServices as $row) {
            $brand = $this->normalizer->normalizeBrand($row->brand);

            if ($brandFilter !== null && $brand !== $brandFilter) {
                continue;
            }

            $clinicalTotal++;
            $plannedRows[] = $this->planServiceRow($row, $brand);
        }

        foreach ($parsed->opticalServices as $row) {
            $brand = $this->normalizer->normalizeBrand($row->brand);

            if ($brandFilter !== null && $brand !== $brandFilter) {
                continue;
            }

            $opticalTotal++;
            $plannedRows[] = $this->planServiceRow($row, $brand);
        }

        $totals = $this->totals($plannedRows, $directoryTotal, $clinicalTotal, $opticalTotal);
        $runId = $persistAudit ? $this->persistAudit($path, $brandFilter, $plannedRows, $totals) : null;

        return new GdaImportPlan($plannedRows, $totals, $runId);
    }

    private function planStoreRow(GdaStoreRow $row, ?string $brand, string $fileHash): GdaImportPlannedRow
    {
        $postal = $this->postalCodeNormalizer->normalize($row->postalCode);
        $phone = $this->phoneNormalizer->normalize($row->phone);
        $latitude = $this->coordinateNormalizer->normalize($row->latitude, 'latitude');
        $longitude = $this->coordinateNormalizer->normalize($row->longitude, 'longitude');
        $schedule = $this->scheduleParser->parse($row->scheduleRaw);
        $capabilities = $this->capabilityParser->parse($row->rawPayload);

        $planned = [
            'name' => $row->name,
            'brand' => $brand,
            'state' => $row->state,
            'address' => $row->address(),
            'street' => $row->street,
            'exterior_number' => $row->exteriorNumber,
            'interior_number' => $row->interiorNumber,
            'neighborhood' => $row->neighborhood,
            'municipality' => $row->municipality,
            'city' => $row->city,
            'postal_code' => $postal->value,
            'phone' => $phone->value,
            'latitude' => $latitude->value,
            'longitude' => $longitude->value,
            'hours' => array_values($schedule['days']),
            'capabilities' => $capabilities['enabled'],
        ];

        $match = $this->matcher->match($row, $brand, $row->name, $planned);
        $autoMatch = $match;
        $manualResolution = $this->manualResolution($brand, $row->name, $this->normalizer->normalize($row->name), $fileHash);
        $manualErrors = [];
        $manualWarnings = [];

        if ($manualResolution !== null) {
            [$match, $manualErrors, $manualWarnings] = $this->applyManualResolution($manualResolution, $match, $planned);
        }

        $invalidFields = $this->invalidFields([
            'postal_code' => $postal,
            'phone' => $phone,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        $invalidFields = $manualErrors === [] ? $invalidFields : [...$invalidFields, 'manual_resolution'];
        $manualReviewFields = $this->manualReviewFields([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        $warnings = array_merge(
            $postal->warnings,
            $phone->warnings,
            $latitude->warnings,
            $longitude->warnings,
            $schedule['warnings'],
            $capabilities['warnings'],
            $manualWarnings,
            array_map(fn ($field) => "{$field} requires manual review", $manualReviewFields),
        );
        $errors = array_merge(
            $match['errors'],
            $manualErrors,
            $postal->errors,
            $phone->errors,
            $latitude->errors,
            $longitude->errors,
            $warnings,
        );

        $validationStatus = $invalidFields !== [] || $manualReviewFields !== []
            ? LaboratoryStoreImportRow::VALIDATION_INVALID_FIELDS
            : ($warnings !== [] ? LaboratoryStoreImportRow::VALIDATION_WARNING : LaboratoryStoreImportRow::VALIDATION_VALID);

        return new GdaImportPlannedRow(
            $row,
            $brand,
            $row->name,
            $this->normalizer->normalize($row->name),
            $match['matched_store_id'],
            $match['classification'],
            $match['confidence'],
            $match['action'],
            $manualResolution === null ? LaboratoryStoreImportRow::RESOLUTION_SOURCE_AUTO : (
                $manualErrors === [] ? LaboratoryStoreImportRow::RESOLUTION_SOURCE_MANUAL : LaboratoryStoreImportRow::RESOLUTION_SOURCE_INVALID
            ),
            $manualResolution?->decision,
            $manualResolution?->id,
            $autoMatch['classification'],
            $autoMatch['action'],
            $autoMatch['matched_store_id'],
            $validationStatus,
            array_values(array_unique([...$invalidFields, ...$manualReviewFields])),
            array_values(array_unique($warnings)),
            $match['evidence'],
            $match['diff'],
            array_values(array_unique($errors)),
            $row->rawPayload,
            $planned,
        );
    }

    private function planServiceRow(GdaSpecialServiceRow $row, ?string $brand): GdaImportPlannedRow
    {
        $planned = [
            'service_type' => $row->serviceType,
            'name' => $row->name,
            'schedule_raw' => $row->scheduleRaw,
            'phone' => $row->phone,
            'address' => $row->address,
        ];

        $match = $this->matcher->match($row, $brand, $row->storeName, $planned);

        return new GdaImportPlannedRow(
            $row,
            $brand,
            $row->storeName,
            $this->normalizer->normalize($row->storeName),
            $match['matched_store_id'],
            $match['classification'],
            $match['confidence'],
            $match['action'] === LaboratoryStoreImportRow::ACTION_CREATE ? LaboratoryStoreImportRow::ACTION_MANUAL_REVIEW : $match['action'],
            LaboratoryStoreImportRow::RESOLUTION_SOURCE_AUTO,
            null,
            null,
            $match['classification'],
            $match['action'],
            $match['matched_store_id'],
            LaboratoryStoreImportRow::VALIDATION_VALID,
            [],
            [],
            $match['evidence'],
            $match['diff'],
            $match['errors'],
            $row->rawPayload,
            $planned,
        );
    }

    private function totals(array $rows, int $directoryTotal, int $clinicalTotal, int $opticalTotal): array
    {
        $totals = [
            'processed' => count($rows),
            'directory_rows' => $directoryTotal,
            'clinical_history_rows' => $clinicalTotal,
            'optical_rows' => $opticalTotal,
            'warnings' => 0,
            'directory' => [
                'matched' => 0,
                'new' => 0,
                'ambiguous' => 0,
                'invalid' => 0,
                'soft_deleted_match' => 0,
                'validation_valid' => 0,
                'validation_warning' => 0,
                'validation_invalid_fields' => 0,
            ],
            'clinical_history' => ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0],
            'optical' => ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0],
        ];

        foreach ($rows as $row) {
            $section = match ($row->row->sheet) {
                'DIRECTORIO' => 'directory',
                'HISTORIA CLINICO' => 'clinical_history',
                'OPTICAS' => 'optical',
                default => 'directory',
            };

            if ($section === 'directory') {
                $key = strtolower($row->classification);
                $totals['directory'][$key] = ($totals['directory'][$key] ?? 0) + 1;
                $validationKey = 'validation_'.strtolower($row->validationStatus);
                $totals['directory'][$validationKey] = ($totals['directory'][$validationKey] ?? 0) + 1;
            } else {
                $key = match ($row->classification) {
                    LaboratoryStoreImportRow::CLASSIFICATION_MATCHED => 'matched',
                    LaboratoryStoreImportRow::CLASSIFICATION_AMBIGUOUS => 'ambiguous',
                    default => 'unmatched',
                };
                $totals[$section][$key]++;
            }

            $totals['warnings'] += count($row->errors);
        }

        return $totals;
    }

    private function invalidFields(array $results): array
    {
        return collect($results)
            ->filter(fn (NormalizationResult $result) => $result->errors !== [])
            ->keys()
            ->all();
    }

    private function manualReviewFields(array $results): array
    {
        return collect($results)
            ->filter(fn (NormalizationResult $result) => $result->manualReview)
            ->keys()
            ->all();
    }

    private function manualResolution(?string $brand, ?string $sourceName, string $normalizedName, ?string $fileHash = null): ?LaboratoryStoreImportResolution
    {
        if ($brand === null || $sourceName === null || trim($sourceName) === '') {
            return null;
        }

        return LaboratoryStoreImportResolution::query()
            ->current()
            ->where('source', 'gda')
            ->where('brand', $brand)
            ->where('normalized_source_name', $normalizedName)
            ->whereNull('external_key')
            ->where(function ($query) use ($fileHash) {
                $query->whereNull('source_file_hash');

                if ($fileHash !== null) {
                    $query->orWhere('source_file_hash', $fileHash);
                }
            })
            ->orderByRaw('CASE WHEN source_file_hash IS NULL THEN 0 ELSE 1 END DESC')
            ->latest('id')
            ->first();
    }

    private function applyManualResolution(LaboratoryStoreImportResolution $resolution, array $autoMatch, array $planned): array
    {
        $evidence = [
            'strength' => 'MANUAL',
            'reason' => 'manual resolution registered for source + brand + normalized source name',
            'resolution_id' => $resolution->id,
            'auto' => [
                'classification' => $autoMatch['classification'],
                'action' => $autoMatch['action'],
                'matched_store_id' => $autoMatch['matched_store_id'],
                'confidence' => $autoMatch['confidence'],
                'evidence' => $autoMatch['evidence'],
            ],
        ];

        if ($resolution->decision === LaboratoryStoreImportResolution::DECISION_MATCH_EXISTING) {
            $store = $resolution->matchedStore;

            if ($store === null) {
                return [[
                    ...$autoMatch,
                    'classification' => LaboratoryStoreImportRow::CLASSIFICATION_INVALID,
                    'action' => LaboratoryStoreImportRow::ACTION_SKIP,
                    'evidence' => $evidence,
                ], ['Manual MATCH_EXISTING resolution points to a missing store'], []];
            }

            $storeBrand = is_string($store->brand) ? $store->brand : $store->brand?->value;

            if ($storeBrand !== $resolution->brand) {
                return [[
                    ...$autoMatch,
                    'classification' => LaboratoryStoreImportRow::CLASSIFICATION_INVALID,
                    'action' => LaboratoryStoreImportRow::ACTION_SKIP,
                    'evidence' => $evidence,
                ], ['Manual MATCH_EXISTING resolution points to a store with a different brand'], []];
            }

            $warnings = $store->trashed()
                ? ['Manual MATCH_EXISTING resolution points to a soft-deleted store; dry-run will not restore it']
                : [];

            return [$this->matcher->manualMatch($store, $planned, $evidence), [], $warnings];
        }

        if ($resolution->decision === LaboratoryStoreImportResolution::DECISION_CREATE_NEW) {
            if ($resolution->matched_store_id !== null) {
                return [[
                    ...$autoMatch,
                    'classification' => LaboratoryStoreImportRow::CLASSIFICATION_INVALID,
                    'action' => LaboratoryStoreImportRow::ACTION_SKIP,
                    'evidence' => $evidence,
                ], ['Manual CREATE_NEW resolution must not contain matched_store_id'], []];
            }

            if ($autoMatch['classification'] === LaboratoryStoreImportRow::CLASSIFICATION_MATCHED) {
                return [$autoMatch, [], ['Manual CREATE_NEW resolution ignored because the source row now matches an existing store']];
            }

            return [[
                'matched_store_id' => null,
                'classification' => LaboratoryStoreImportRow::CLASSIFICATION_NEW,
                'confidence' => 100,
                'action' => LaboratoryStoreImportRow::ACTION_CREATE,
                'diff' => ['planned' => $planned],
                'errors' => [],
                'evidence' => $evidence,
            ], [], []];
        }

        if ($resolution->decision === LaboratoryStoreImportResolution::DECISION_SKIP) {
            if ($resolution->matched_store_id !== null) {
                return [[
                    ...$autoMatch,
                    'classification' => LaboratoryStoreImportRow::CLASSIFICATION_INVALID,
                    'action' => LaboratoryStoreImportRow::ACTION_SKIP,
                    'evidence' => $evidence,
                ], ['Manual SKIP resolution must not contain matched_store_id'], []];
            }

            return [[
                ...$autoMatch,
                'action' => LaboratoryStoreImportRow::ACTION_SKIP,
                'confidence' => 100,
                'evidence' => $evidence,
                'errors' => [],
            ], [], []];
        }

        return [[
            ...$autoMatch,
            'classification' => LaboratoryStoreImportRow::CLASSIFICATION_INVALID,
            'action' => LaboratoryStoreImportRow::ACTION_SKIP,
            'evidence' => $evidence,
        ], ["Unknown manual resolution decision: {$resolution->decision}"], []];
    }

    private function jsonSafe(array $value): array
    {
        return json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
    }

    private function sourceStoreSnapshot(?int $storeId): ?array
    {
        if ($storeId === null) {
            return null;
        }

        $store = LaboratoryStore::query()->withTrashed()->find($storeId);

        if ($store === null) {
            return null;
        }

        $storeBrand = is_string($store->brand) ? $store->brand : $store->brand?->value;

        return [
            'id' => $store->id,
            'name' => $store->name,
            'brand' => $storeBrand,
            'updated_at' => optional($store->updated_at)->toJSON(),
        ];
    }

    private function persistAudit(string $path, ?string $brandFilter, array $rows, array $totals): int
    {
        return DB::transaction(function () use ($path, $brandFilter, $rows, $totals) {
            $run = LaboratoryStoreImportRun::query()->create([
                'file_path' => $path,
                'file_hash' => hash_file('sha256', $path),
                'brand_filter' => $brandFilter,
                'dry_run' => true,
                'status' => LaboratoryStoreImportRun::STATUS_RUNNING,
                'totals' => $totals,
                'started_at' => now(),
            ]);

            foreach ($rows as $row) {
                LaboratoryStoreImportRow::query()->create([
                    'run_id' => $run->id,
                    'excel_sheet' => $row->row->sheet,
                    'excel_row' => $row->row->rowNumber,
                    'brand' => $row->brand,
                    'source_name' => $row->sourceName,
                    'normalized_name' => $row->normalizedName,
                    'matched_store_id' => $row->matchedStoreId,
                    'classification' => $row->classification,
                    'confidence' => $row->confidence,
                    'action' => $row->action,
                    'resolution_source' => $row->resolutionSource,
                    'resolution_decision' => $row->resolutionDecision,
                    'manual_resolution_id' => $row->manualResolutionId,
                    'auto_classification' => $row->autoClassification,
                    'auto_action' => $row->autoAction,
                    'auto_matched_store_id' => $row->autoMatchedStoreId,
                    'source_store_snapshot' => $this->sourceStoreSnapshot($row->matchedStoreId),
                    'validation_status' => $row->validationStatus,
                    'invalid_fields' => $this->jsonSafe($row->invalidFields),
                    'warnings' => $this->jsonSafe($row->warnings),
                    'evidence' => $this->jsonSafe($row->evidence),
                    'diff' => $this->jsonSafe($row->diff),
                    'errors' => $this->jsonSafe($row->errors),
                    'raw_payload' => $this->jsonSafe($row->rawPayload),
                    'planned_payload' => $this->jsonSafe($row->plannedPayload),
                ]);
            }

            $run->update([
                'status' => LaboratoryStoreImportRun::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return $run->id;
        });
    }
}
