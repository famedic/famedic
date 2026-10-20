<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryResultObservationValueType;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryResultVersion;
use App\Services\LaboratoryResults\Extraction\LaboratoryReferenceNormalizer;
use App\Services\LaboratoryResults\Extraction\LaboratoryUnitNormalizer;

class LaboratoryStructuredQaValidator
{
    public function __construct(
        private readonly LaboratoryUnitNormalizer $unitNormalizer,
        private readonly LaboratoryReferenceNormalizer $referenceNormalizer,
    ) {}

    public function validate(LaboratoryStructuredQaCandidate $candidate): LaboratoryStructuredQaValidationResult
    {
        $reasons = [];

        if ($candidate->structuredReadiness !== ''
            && $candidate->structuredReadiness !== 'READY_FOR_STRUCTURED_CANDIDATE') {
            $reasons[] = 'structured_readiness_not_ready';
        }

        if (($candidate->identity['confirmed'] ?? false) !== true) {
            $reasons[] = 'identity_not_confirmed';
        }

        $version = LaboratoryResultVersion::query()->find($candidate->laboratoryResultVersionId);

        if ($version === null) {
            $reasons[] = 'laboratory_result_version_not_found';
        }

        $analyte = LaboratoryAnalyte::query()
            ->where('code', $candidate->analyteCode)
            ->where('is_active', true)
            ->first();

        if ($analyte === null) {
            $reasons[] = 'analyte_not_found';
        }

        if ($candidate->valueType !== LaboratoryResultObservationValueType::Numeric->value) {
            $reasons[] = 'value_type_not_numeric';
        } elseif (! is_numeric($candidate->value)) {
            $reasons[] = 'value_not_numeric';
        }

        if ($candidate->sourcePage <= 0) {
            $reasons[] = 'source_page_missing';
        }

        if ($candidate->laboratoryPurchaseItemId === null) {
            $reasons[] = 'purchase_item_missing';
        } elseif (($candidate->purchaseItemAssociation['status'] ?? '') !== 'confirmed'
            && $candidate->purchaseAssociationMethod !== 'smalot_panel_deterministic') {
            $reasons[] = 'purchase_item_not_confirmed';
        }

        if ($candidate->referenceText === null || trim($candidate->referenceText) === '') {
            $reasons[] = 'reference_text_missing';
        } elseif ($candidate->referenceClass === 'categorical') {
            $reasons[] = 'reference_categorical';
        }

        if ($analyte !== null) {
            $catalogUnit = $candidate->catalogDefaultUnit ?? $analyte->default_unit;

            if ($catalogUnit === null || $catalogUnit === '') {
                $reasons[] = 'catalog_unit_missing';
            } elseif (! $this->unitNormalizer->areEquivalent($candidate->unitRaw, $catalogUnit)) {
                $reasons[] = 'unit_not_equivalent_to_catalog';
            }
        }

        if ($candidate->referenceText !== null && trim($candidate->referenceText) !== '') {
            $parsed = $this->referenceNormalizer->parse($candidate->referenceText);

            if ($parsed->kind === 'unknown' && $candidate->referenceClass === 'unknown') {
                $reasons[] = 'reference_not_interpretable';
            }
        }

        if ($reasons !== []) {
            return LaboratoryStructuredQaValidationResult::fail($reasons);
        }

        return LaboratoryStructuredQaValidationResult::pass();
    }
}
