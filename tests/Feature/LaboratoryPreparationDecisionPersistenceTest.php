<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Models\User;
use App\Services\LaboratoryPreparation\LaboratoryPreparationDecision;

function preparationDecisionPurchase(array $attributes = []): LaboratoryPurchase
{
    $user = User::factory()
        ->withRegularCustomer()
        ->withCompleteProfile()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);

    return LaboratoryPurchase::query()->create(array_merge([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-decision-'.fake()->unique()->numerify('######'),
        'name' => 'Paciente',
        'paternal_lastname' => 'Privado',
        'maternal_lastname' => 'Contrato',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle Privada',
        'number' => '123',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'additional_references' => null,
        'total_cents' => 100000,
    ], $attributes));
}

function preparationDecisionSummary(array $attributes = []): LaboratoryPurchasePreparationSummary
{
    $purchase = $attributes['purchase'] ?? preparationDecisionPurchase();
    unset($attributes['purchase']);

    return LaboratoryPurchasePreparationSummary::query()->create(array_merge([
        'laboratory_purchase_id' => $purchase->id,
        'source_hash' => str_repeat('a', 64),
        'status' => LaboratoryPurchasePreparationSummary::STATUS_GENERATED,
        'summary_text' => 'Indicaciones listas.',
        'summary_json' => [
            'summary' => 'Indicaciones listas.',
            'sections' => [],
            'special_instructions' => [],
            'individual_instructions' => [],
        ],
        'generated_at' => now(),
    ], $attributes));
}

it('persists an AUTO_CONSOLIDATED functional decision with rules and version', function () {
    $decision = LaboratoryPreparationDecision::autoConsolidated(
        orderId: 'gda-decision-auto',
        rulesVersion: 'famedic-indicaciones-v3',
        rulesApplied: ['R01', 'R03'],
        consolidatedRequirements: [
            'fasting' => ['minimum_hours' => 8],
            'hydration' => ['amount' => '1.5 L'],
        ],
        originalInstructions: [
            [
                'study_id' => '1001',
                'study_name' => 'Quimica sanguinea',
                'source_instructions' => 'Ayuno de 8 horas.',
            ],
        ],
    );

    $summary = preparationDecisionSummary([
        'decision_status' => $decision->status,
        'rules_version' => $decision->rulesVersion,
        'rules_applied' => $decision->rulesApplied,
        'fallback_reason' => $decision->fallbackReason,
        'fallback_category' => $decision->fallbackCategory,
        'needs_provider_review' => $decision->needsProviderReview,
        'summary_json' => $decision->toArray(),
    ])->refresh();

    expect($summary->decision_status)->toBe(LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED)
        ->and($summary->isAutoConsolidated())->toBeTrue()
        ->and($summary->isFallbackOriginal())->toBeFalse()
        ->and($summary->rules_version)->toBe('famedic-indicaciones-v3')
        ->and($summary->rules_applied)->toBe(['R01', 'R03'])
        ->and($summary->needs_provider_review)->toBeFalse()
        ->and($summary->summary_json['consolidated_requirements']['fasting']['minimum_hours'])->toBe(8);
});

it('rejects AUTO_CONSOLIDATED decisions without validated consolidated requirements', function () {
    expect(fn () => LaboratoryPreparationDecision::autoConsolidated(
        orderId: 'gda-decision-invalid',
        rulesVersion: 'famedic-indicaciones-v3',
        rulesApplied: ['R01'],
        consolidatedRequirements: [],
        originalInstructions: [],
    ))->toThrow(InvalidArgumentException::class, 'consolidated requirements');
});

it('persists a FALLBACK_ORIGINAL decision for provider review causes', function () {
    $decision = LaboratoryPreparationDecision::fallbackOriginal(
        orderId: 'gda-decision-fallback',
        rulesVersion: 'famedic-indicaciones-v3',
        rulesApplied: ['R01', 'R09'],
        fallbackReason: 'incompatible_fasting_interval',
        fallbackCategory: LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT,
        needsProviderReview: true,
        originalInstructions: [
            [
                'study_id' => '2001',
                'study_name' => 'Estudio A',
                'source_instructions' => 'Ayuno de 6 a 8 horas.',
            ],
            [
                'study_id' => '2002',
                'study_name' => 'Estudio B',
                'source_instructions' => 'Ayuno de 10 a 12 horas.',
            ],
        ],
    );

    $summary = preparationDecisionSummary([
        'decision_status' => $decision->status,
        'rules_version' => $decision->rulesVersion,
        'rules_applied' => $decision->rulesApplied,
        'fallback_reason' => $decision->fallbackReason,
        'fallback_category' => $decision->fallbackCategory,
        'needs_provider_review' => $decision->needsProviderReview,
        'summary_json' => $decision->toArray(),
    ])->refresh();

    expect($summary->decision_status)->toBe(LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL)
        ->and($summary->isFallbackOriginal())->toBeTrue()
        ->and($summary->rules_applied)->toBe(['R01', 'R09'])
        ->and($summary->fallback_reason)->toBe('incompatible_fasting_interval')
        ->and($summary->fallback_category)->toBe(LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT)
        ->and($summary->needs_provider_review)->toBeTrue()
        ->and($summary->summary_json['original_instructions'])->toHaveCount(2);
});

it('persists technical AI fallback without provider review', function () {
    $decision = LaboratoryPreparationDecision::fallbackOriginal(
        orderId: 'gda-decision-ai-fallback',
        rulesVersion: null,
        rulesApplied: [],
        fallbackReason: 'openai_timeout',
        fallbackCategory: LaboratoryPreparationDecision::FALLBACK_TECHNICAL_AI_FAILURE,
        needsProviderReview: false,
        originalInstructions: [
            [
                'study_id' => '3001',
                'study_name' => 'Estudio tecnico',
                'source_instructions' => 'Ayuno de 8 horas.',
            ],
        ],
    );

    $summary = preparationDecisionSummary([
        'decision_status' => $decision->status,
        'rules_version' => $decision->rulesVersion,
        'rules_applied' => $decision->rulesApplied,
        'fallback_reason' => $decision->fallbackReason,
        'fallback_category' => $decision->fallbackCategory,
        'needs_provider_review' => $decision->needsProviderReview,
        'summary_json' => $decision->toArray(),
    ])->refresh();

    expect($summary->fallback_category)->toBe(LaboratoryPurchasePreparationSummary::FALLBACK_TECHNICAL_AI_FAILURE)
        ->and($summary->needs_provider_review)->toBeFalse()
        ->and($summary->rules_version)->toBeNull()
        ->and($summary->rules_applied)->toBe([]);
});

it('rejects technical AI fallback decisions marked for provider review', function () {
    expect(fn () => LaboratoryPreparationDecision::fallbackOriginal(
        orderId: 'gda-decision-ai-review',
        rulesVersion: null,
        rulesApplied: [],
        fallbackReason: 'openai_timeout',
        fallbackCategory: LaboratoryPreparationDecision::FALLBACK_TECHNICAL_AI_FAILURE,
        needsProviderReview: true,
        originalInstructions: [],
    ))->toThrow(InvalidArgumentException::class, 'provider review');
});

it('reads historical preparation summaries without v3 decision metadata', function () {
    $summary = preparationDecisionSummary()->refresh();

    expect($summary->decision_status)->toBeNull()
        ->and($summary->rules_version)->toBeNull()
        ->and($summary->rules_applied)->toBeNull()
        ->and($summary->fallback_reason)->toBeNull()
        ->and($summary->fallback_category)->toBeNull()
        ->and($summary->needs_provider_review)->toBeNull()
        ->and($summary->hasFunctionalDecision())->toBeFalse();
});

it('serializes and deserializes decision JSON fields through model casts', function () {
    $decision = LaboratoryPreparationDecision::autoConsolidated(
        orderId: 'gda-json-roundtrip',
        rulesVersion: 'famedic-indicaciones-v3',
        rulesApplied: ['R10'],
        consolidatedRequirements: [
            'documents' => ['Receta medica original'],
        ],
        originalInstructions: [
            [
                'study_id' => null,
                'study_name' => 'Estudio con receta',
                'source_instructions' => 'Presentar receta medica original.',
            ],
        ],
    );

    $summary = preparationDecisionSummary([
        'decision_status' => $decision->status,
        'rules_version' => $decision->rulesVersion,
        'rules_applied' => $decision->rulesApplied,
        'needs_provider_review' => $decision->needsProviderReview,
        'summary_json' => $decision->toArray(),
    ])->refresh();

    expect($summary->rules_applied)->toBe(['R10'])
        ->and($summary->summary_json['status'])->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($summary->summary_json['original_instructions'][0]['study_name'])->toBe('Estudio con receta');
});

it('serializes AUTO_CONSOLIDATED decisions as schema v2 compatible payloads', function () {
    $decision = LaboratoryPreparationDecision::autoConsolidated(
        orderId: 'gda-schema-auto',
        rulesVersion: 'famedic-indicaciones-v3',
        rulesApplied: ['R01'],
        consolidatedRequirements: [
            'fasting' => ['minimum_hours' => 8],
        ],
        originalInstructions: [
            [
                'study_id' => '1001',
                'study_name' => 'Perfil metabolico',
                'source_instructions' => 'Ayuno de 8 horas.',
            ],
        ],
    );

    $payload = $decision->toSchemaPayload();

    expect(array_keys($payload))->toBe([
        'status',
        'order_id',
        'consolidated_requirements',
        'operational_requirements',
        'original_instructions',
        'fallback_reason',
        'needs_provider_review',
        'trace',
    ])
        ->and($payload['status'])->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($payload['fallback_reason'])->toBeNull()
        ->and($payload['needs_provider_review'])->toBeFalse()
        ->and($payload['trace'])->toBe([
            'rules_version' => 'famedic-indicaciones-v3',
            'rules_applied' => ['R01'],
            'fallback_category' => null,
        ]);
});

it('serializes FALLBACK_ORIGINAL decisions as schema v2 compatible payloads', function () {
    $decision = LaboratoryPreparationDecision::fallbackOriginal(
        orderId: 'gda-schema-fallback',
        rulesVersion: 'famedic-indicaciones-v3',
        rulesApplied: ['R09'],
        fallbackReason: 'medication_conflict',
        fallbackCategory: LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT,
        needsProviderReview: true,
        originalInstructions: [
            [
                'study_id' => null,
                'study_name' => 'Contraste',
                'source_instructions' => 'Consultar indicacion de medicamento.',
            ],
        ],
    );

    $payload = $decision->toSchemaPayload();

    expect(array_diff(array_keys($payload), [
        'status',
        'order_id',
        'patient_context',
        'consolidated_requirements',
        'operational_requirements',
        'original_instructions',
        'fallback_reason',
        'needs_provider_review',
        'trace',
    ]))->toBe([])
        ->and($payload['status'])->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($payload['consolidated_requirements'])->toBeNull()
        ->and($payload['fallback_reason'])->toBe('medication_conflict')
        ->and($payload['needs_provider_review'])->toBeTrue()
        ->and($payload['trace']['fallback_category'])->toBe(LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT);
});

it('rejects original instructions that do not match schema v2 item shape', function () {
    expect(fn () => LaboratoryPreparationDecision::fallbackOriginal(
        orderId: 'gda-schema-invalid-original',
        rulesVersion: 'famedic-indicaciones-v3',
        rulesApplied: [],
        fallbackReason: 'unknown_instruction',
        fallbackCategory: LaboratoryPreparationDecision::FALLBACK_FUNCTIONAL_AMBIGUITY,
        needsProviderReview: true,
        originalInstructions: [
            [
                'study_id' => 123,
                'study_name' => 'Estudio',
                'source_instructions' => 'Indicacion.',
            ],
        ],
    ))->toThrow(InvalidArgumentException::class, 'study ids');
});
