<?php

namespace App\Services\LaboratoryResults\AiExplanation;

use App\Enums\LaboratoryResultAiExplanationStatus;
use App\Jobs\GenerateLaboratoryResultAiExplanationJob;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultAiExplanation;
use App\Models\LaboratoryResultObservation;
use App\Models\User;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationContract;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationInputBuilder;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationInputHash;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LaboratoryResultAiExplanationService
{
    public function __construct(
        private readonly LaboratoryResultAiExplanationConsentService $consentService,
        private readonly LaboratoryResultAiExplanationEligibility $eligibility,
        private readonly LaboratoryResultAiExplanationInputBuilder $inputBuilder,
        private readonly LaboratoryResultAiExplanationFeatureGuard $featureGuard,
    ) {}

    public function isEnabled(): bool
    {
        return $this->featureGuard->isEffectiveEnabled();
    }

    /** @return array{status: string, explanation: ?string, limitations: ?string, explanation_id: ?int} */
    public function requestExplanation(
        LaboratoryPurchase $purchase,
        LaboratoryResultObservation $observation,
        User $user,
    ): array {
        if (! $this->isEnabled()) {
            return $this->responsePayload('feature_disabled');
        }

        if (! $this->consentService->hasAcceptedConsent($user->customer)) {
            return $this->responsePayload('consent_required');
        }

        if (! $this->eligibility->isEligible($observation, $purchase)) {
            throw new RuntimeException('Observation is not eligible for AI explanation.');
        }

        $promptVersion = LaboratoryResultAiExplanationContract::PROMPT_VERSION;
        $input = $this->inputBuilder->fromObservation($observation);
        $inputHash = LaboratoryResultAiExplanationInputHash::compute($input, $promptVersion);

        $existingReady = LaboratoryResultAiExplanation::query()
            ->where('laboratory_result_observation_id', $observation->id)
            ->where('input_hash', $inputHash)
            ->where('prompt_version', $promptVersion)
            ->where('status', LaboratoryResultAiExplanationStatus::Ready)
            ->first();

        if ($existingReady) {
            return $this->responsePayload('ready', $existingReady);
        }

        $inFlight = LaboratoryResultAiExplanation::query()
            ->where('laboratory_result_observation_id', $observation->id)
            ->where('input_hash', $inputHash)
            ->where('prompt_version', $promptVersion)
            ->whereIn('status', [
                LaboratoryResultAiExplanationStatus::Pending,
                LaboratoryResultAiExplanationStatus::Generating,
            ])
            ->latest('id')
            ->first();

        if ($inFlight) {
            return $this->responsePayload($inFlight->status->value, $inFlight);
        }

        $explanation = DB::transaction(function () use ($observation, $inputHash, $promptVersion) {
            return LaboratoryResultAiExplanation::query()->updateOrCreate(
                [
                    'laboratory_result_observation_id' => $observation->id,
                    'input_hash' => $inputHash,
                    'prompt_version' => $promptVersion,
                ],
                [
                    'status' => LaboratoryResultAiExplanationStatus::Pending,
                    'explanation' => null,
                    'limitations' => null,
                    'ai_execution_id' => null,
                    'generated_at' => null,
                ],
            );
        });

        GenerateLaboratoryResultAiExplanationJob::dispatch($explanation->id);

        return $this->responsePayload('pending', $explanation->fresh());
    }

    /** @return array{status: string, explanation: ?string, limitations: ?string, explanation_id: ?int} */
    private function responsePayload(string $status, ?LaboratoryResultAiExplanation $explanation = null): array
    {
        return [
            'status' => $status,
            'explanation' => $explanation?->status === LaboratoryResultAiExplanationStatus::Ready
                ? $explanation->explanation
                : null,
            'limitations' => $explanation?->status === LaboratoryResultAiExplanationStatus::Ready
                ? $explanation->limitations
                : null,
            'explanation_id' => $explanation?->id,
        ];
    }
}
