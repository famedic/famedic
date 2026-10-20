<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryStructuredResultPromotionStatus;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Services\LaboratoryResults\Extraction\LaboratoryReferenceNormalizer;
use App\Services\LaboratoryResults\Extraction\LaboratoryUnitNormalizer;

class LaboratoryStructuredResultPromotionGate
{
    private const NUMERIC_EPSILON = 0.001;

    public function __construct(
        private readonly LaboratoryUnitNormalizer $unitNormalizer,
        private readonly LaboratoryReferenceNormalizer $referenceNormalizer,
    ) {}

    public function evaluate(LaboratoryResultObservation $observation): LaboratoryStructuredResultPromotionGateResult
    {
        $observation->loadMissing(['report.resultVersion', 'analyte']);

        $reasonCodes = [];
        $reasonsHuman = [];

        if ($observation->report === null || ! $observation->report->isShadowQa()) {
            return $this->result(
                LaboratoryStructuredResultPromotionStatus::Rejected,
                ['not_shadow_qa'],
                ['La observación no pertenece a un report Shadow QA.'],
            );
        }

        if ($observation->laboratory_analyte_id === null || $observation->analyte === null) {
            $reasonCodes[] = 'analyte_not_identified';
            $reasonsHuman[] = 'Analyte no identificado en catálogo.';
        } elseif ($observation->analyte_code === null
            || $observation->analyte_code !== $observation->analyte->code) {
            $reasonCodes[] = 'analyte_code_invalid';
            $reasonsHuman[] = 'Código de analyte inválido o inconsistente.';
        }

        if ($observation->value_type === LaboratoryResultObservationValueType::Numeric
            && $observation->numeric_value === null) {
            $reasonCodes[] = 'numeric_value_missing';
            $reasonsHuman[] = 'Valor numérico ausente.';
        }

        if ($observation->value_type === LaboratoryResultObservationValueType::Qualitative
            && ($observation->text_value === null || trim((string) $observation->text_value) === '')) {
            $reasonCodes[] = 'qualitative_value_missing';
            $reasonsHuman[] = 'Valor cualitativo ausente.';
        }

        if ($observation->value_type === LaboratoryResultObservationValueType::Comment) {
            $reasonCodes[] = 'comment_not_promotable';
            $reasonsHuman[] = 'Observación tipo comentario no promovible.';
        }

        if ($observation->analyte !== null) {
            $unit = $observation->unit ?? $observation->unit_raw;
            $catalogUnit = $observation->analyte->default_unit;

            if ($unit !== null && $catalogUnit !== null && ! $this->unitNormalizer->areEquivalent($unit, $catalogUnit)) {
                $reasonCodes[] = 'unit_incompatible';
                $reasonsHuman[] = 'Unidad incompatible con catálogo del analyte.';
            }
        }

        if ($observation->report->laboratory_result_version_id === null) {
            $reasonCodes[] = 'version_missing';
            $reasonsHuman[] = 'Versión documental ausente.';
        }

        if (($observation->metadata['pii_unsafe'] ?? false) === true) {
            $reasonCodes[] = 'pii_unsafe';
            $reasonsHuman[] = 'Extracción marcada como no PII-safe.';
        }

        if ($this->hasTextVisionConflict($observation)) {
            $reasonCodes[] = 'text_vision_conflict';
            $reasonsHuman[] = 'Conflicto de valor entre Text publicado y Vision Shadow.';
        }

        if ($this->hasHardRejection($reasonCodes)) {
            return $this->result(LaboratoryStructuredResultPromotionStatus::Rejected, $reasonCodes, $reasonsHuman);
        }

        if ($observation->value_type === LaboratoryResultObservationValueType::Qualitative) {
            return $this->result(LaboratoryStructuredResultPromotionStatus::Validated, [], []);
        }

        $minConfidence = (float) config(
            'laboratory-results.promotion_gate.min_confidence',
            config('laboratory-results.vision_extraction.fallback.min_confidence', 0.75),
        );

        if ($observation->confidence !== null && (float) $observation->confidence < $minConfidence) {
            $reasonCodes[] = 'confidence_below_threshold';
            $reasonsHuman[] = 'Confianza por debajo del umbral mínimo.';
        }

        if ($observation->laboratory_purchase_item_id === null
            && empty($observation->metadata['purchase_association_method'])) {
            $reasonCodes[] = 'purchase_association_unresolved';
            $reasonsHuman[] = 'Asociación con purchase item no resuelta.';
        }

        if ($this->referenceRequiresReview($observation)) {
            $reasonCodes[] = 'reference_not_fully_evaluable';
            $reasonsHuman[] = 'Referencia no completamente evaluable.';
        }

        if ($this->requiresOutOfRangeEvaluation($observation) && ! $this->hasOutOfRangeEvaluation($observation)) {
            $reasonCodes[] = 'out_of_range_not_evaluated';
            $reasonsHuman[] = 'Evaluación out-of-range pendiente para referencia numérica.';
        }

        if (($observation->metadata['identity_evidence'] ?? null) === null
            && ($observation->metadata['shadow_qa_identity'] ?? null) === null) {
            $reasonCodes[] = 'identity_evidence_missing';
            $reasonsHuman[] = 'Evidencia de identidad insuficiente.';
        }

        if ($reasonCodes !== []) {
            return $this->result(LaboratoryStructuredResultPromotionStatus::NeedsReview, $reasonCodes, $reasonsHuman);
        }

        return $this->result(LaboratoryStructuredResultPromotionStatus::Validated, [], []);
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $reasonsHuman
     */
    private function result(
        LaboratoryStructuredResultPromotionStatus $status,
        array $reasonCodes,
        array $reasonsHuman,
    ): LaboratoryStructuredResultPromotionGateResult {
        return new LaboratoryStructuredResultPromotionGateResult($status, $reasonCodes, $reasonsHuman);
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function hasHardRejection(array $reasonCodes): bool
    {
        $hard = [
            'not_shadow_qa',
            'analyte_not_identified',
            'analyte_code_invalid',
            'numeric_value_missing',
            'qualitative_value_missing',
            'comment_not_promotable',
            'unit_incompatible',
            'version_missing',
            'pii_unsafe',
            'text_vision_conflict',
        ];

        return array_intersect($reasonCodes, $hard) !== [];
    }

    private function hasTextVisionConflict(LaboratoryResultObservation $shadowObservation): bool
    {
        $versionId = $shadowObservation->report?->laboratory_result_version_id;
        $analyteCode = $shadowObservation->analyte_code;

        if ($versionId === null || $analyteCode === null || $analyteCode === '') {
            return false;
        }

        $textObservation = LaboratoryResultObservation::query()
            ->where('analyte_code', $analyteCode)
            ->whereHas('report', function ($query) use ($versionId) {
                $query
                    ->where('laboratory_result_version_id', $versionId)
                    ->where('extraction_method', LaboratoryResultExtractionMethod::PdfText)
                    ->activePublished();
            })
            ->first();

        if ($textObservation === null || $shadowObservation->numeric_value === null) {
            return false;
        }

        if ($textObservation->numeric_value === null) {
            return false;
        }

        return abs((float) $shadowObservation->numeric_value - (float) $textObservation->numeric_value) > self::NUMERIC_EPSILON;
    }

    private function referenceRequiresReview(LaboratoryResultObservation $observation): bool
    {
        if ($observation->value_type !== LaboratoryResultObservationValueType::Numeric) {
            return false;
        }

        $referenceText = $observation->reference_text;

        if ($referenceText === null || trim($referenceText) === '') {
            return true;
        }

        $parsed = $this->referenceNormalizer->parse($referenceText);

        return $parsed->kind === 'unknown' || $parsed->kind === 'empty';
    }

    private function requiresOutOfRangeEvaluation(LaboratoryResultObservation $observation): bool
    {
        if ($observation->value_type !== LaboratoryResultObservationValueType::Numeric) {
            return false;
        }

        $parsed = $this->referenceNormalizer->parse($observation->reference_text);

        return $parsed->referenceLow !== null
            || $parsed->referenceHigh !== null
            || $observation->reference_low !== null
            || $observation->reference_high !== null;
    }

    private function hasOutOfRangeEvaluation(LaboratoryResultObservation $observation): bool
    {
        $referenceEvaluation = $observation->metadata['reference_evaluation'] ?? null;

        if (is_array($referenceEvaluation) && ($referenceEvaluation['evaluated'] ?? false) === true) {
            return true;
        }

        return ! in_array($observation->reference_status, [
            LaboratoryResultReferenceStatus::Unknown,
            LaboratoryResultReferenceStatus::NotApplicable,
        ], true);
    }
}
