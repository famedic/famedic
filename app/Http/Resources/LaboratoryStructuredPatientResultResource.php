<?php

namespace App\Http\Resources;

use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\LaboratoryResultReport;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationFeatureGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaboratoryResultReport
 */
class LaboratoryStructuredPatientResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LaboratoryResultReport $report */
        $report = $this->resource;

        return [
            'meta' => [
                'ai_explanation_enabled' => app(LaboratoryResultAiExplanationFeatureGuard::class)->isEffectiveEnabled(),
            ],
            'report' => [
                'id' => $report->id,
                'version_id' => $report->laboratory_result_version_id,
                'status' => LaboratoryResultStructuredStatus::Published->value,
                'source' => $report->source?->value,
                'reported_at' => $report->reported_at?->toIso8601String(),
                'specimen_collected_at' => $report->specimen_collected_at?->toIso8601String(),
            ],
            'observations' => LaboratoryStructuredPatientObservationResource::collection(
                $report->observations ?? collect()
            ),
        ];
    }
}
