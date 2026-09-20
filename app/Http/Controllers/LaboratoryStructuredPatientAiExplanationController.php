<?php

namespace App\Http\Controllers;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultObservation;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationConsentService;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationEligibility;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LaboratoryStructuredPatientAiExplanationController extends Controller
{
    public function __construct(
        private readonly LaboratoryResultAiExplanationService $explanationService,
        private readonly LaboratoryResultAiExplanationConsentService $consentService,
        private readonly LaboratoryResultAiExplanationEligibility $eligibility,
    ) {}

    public function store(
        Request $request,
        LaboratoryPurchase $laboratoryPurchase,
        LaboratoryResultObservation $observation,
    ): JsonResponse {
        $this->authorize('view', $laboratoryPurchase);

        if (! $this->eligibility->isEligible($observation, $laboratoryPurchase)) {
            return response()->json(['message' => 'Resultado no disponible.'], 404);
        }

        try {
            $payload = $this->explanationService->requestExplanation(
                $laboratoryPurchase,
                $observation,
                $request->user(),
            );
        } catch (\RuntimeException) {
            return response()->json(['message' => 'Resultado no disponible.'], 404);
        }

        return response()->json(['data' => $payload]);
    }

    public function updateConsent(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $this->authorize('view', $laboratoryPurchase);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['accept', 'decline'])],
        ]);

        $customer = $request->user()->customer;
        $record = $validated['action'] === 'accept'
            ? $this->consentService->accept($customer, $request->user())
            : $this->consentService->decline($customer, $request->user());

        return response()->json([
            'data' => [
                'status' => $record->status->value,
                'consent_version' => $record->consent_version,
                'consented_at' => $record->consented_at?->toIso8601String(),
                'declined_at' => $record->declined_at?->toIso8601String(),
            ],
        ]);
    }
}
