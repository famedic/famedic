<?php

use App\Enums\LaboratoryBrand;
use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;
use App\Services\LaboratoryPreparation\LaboratoryPreparationDecision;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRedactionValidator;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryNotificationService;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

function preparationSummaryPurchase(array $attributes = []): LaboratoryPurchase
{
    $user = User::factory()
        ->withRegularCustomer()
        ->withCompleteProfile()
        ->create([
            'documentation_accepted_at' => now(),
            'name' => 'Paciente Sensible',
            'email' => 'sensible@example.test',
            'phone' => '8111111111',
        ])
        ->fresh(['customer']);

    return LaboratoryPurchase::query()->create(array_merge([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-ai-'.fake()->unique()->numerify('######'),
        'name' => 'Paciente',
        'paternal_lastname' => 'Privado',
        'maternal_lastname' => 'NoEnviar',
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
        'additional_references' => 'No enviar',
        'total_cents' => 100000,
    ], $attributes));
}

function preparationSummaryItem(LaboratoryPurchase $purchase, array $attributes): LaboratoryPurchaseItem
{
    return LaboratoryPurchaseItem::query()->create(array_merge([
        'laboratory_purchase_id' => $purchase->id,
        'name' => 'Biometria hematica',
        'gda_id' => '1001',
        'indications' => 'Ayuno de 8 horas.',
        'feature_list' => ['Hemoglobina'],
        'price_cents' => 50000,
    ], $attributes));
}

function bindPreparationOpenAiFake(?array $content = null, bool $fail = false): object
{
    $fake = new class($content, $fail) extends OpenAiClient
    {
        public int $calls = 0;

        public array $messages = [];

        public function __construct(public ?array $content, private bool $fail) {}

        public function chatCompletionWithMetadata(
            array $messages,
            ?string $model = null,
            ?array $jsonSchema = null,
            ?string $schemaName = null,
            float $temperature = 0,
            ?int $timeoutSeconds = null,
        ): array {
            $this->calls++;
            $this->messages = $messages;

            if ($this->fail) {
                throw new \RuntimeException('Proveedor no disponible');
            }

            return [
                'content' => $this->content ?? [
                    'summary' => 'Ayuno de 8 horas y llevar recipiente esteril cuando aplique.',
                    'sections' => [
                        [
                            'key' => 'ayuno',
                            'title' => 'Ayuno',
                            'content' => 'Ayuno de 8 horas.',
                            'source_item_ids' => [1],
                        ],
                    ],
                    'special_instructions' => [
                        [
                            'content' => 'Llevar recipiente esteril.',
                            'source_item_ids' => [2],
                        ],
                    ],
                    'individual_instructions' => [
                        [
                            'study_name' => 'Examen general de orina',
                            'content' => 'Llevar recipiente esteril.',
                            'source_item_id' => 2,
                        ],
                    ],
                ],
                'model' => $model ?: 'gpt-4o-mini',
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                    'total_tokens' => 150,
                ],
                'raw' => [],
            ];
        }
    };

    app()->instance(OpenAiClient::class, $fake);

    return $fake;
}

it('generates and persists a preparation summary for a purchase with multiple studies', function () {
    $purchase = preparationSummaryPurchase();
    $first = preparationSummaryItem($purchase, [
        'name' => 'Biometria hematica',
        'gda_id' => '1001',
        'indications' => 'Ayuno de 8 horas.',
    ]);
    $second = preparationSummaryItem($purchase, [
        'name' => 'Examen general de orina',
        'gda_id' => '1002',
        'indications' => 'Llevar recipiente esteril.',
    ]);

    $fake = bindPreparationOpenAiFake([
        'summary' => 'Indicaciones consolidadas.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$first->id],
            ],
        ],
        'special_instructions' => [
            [
                'content' => 'Llevar recipiente esteril.',
                'source_item_ids' => [$second->id],
            ],
        ],
        'individual_instructions' => [
            [
                'study_name' => 'Examen general de orina',
                'content' => 'Llevar recipiente esteril.',
                'source_item_id' => $second->id,
            ],
        ],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->summary_text)->toBe('Indicaciones consolidadas.')
        ->and($summary->summary_json['sections'][0]['source_item_ids'])->toBe([$first->id])
        ->and($summary->summary_json['special_instructions'][0]['source_item_ids'])->toBe([$second->id])
        ->and($fake->calls)->toBe(1);

    $this->assertDatabaseHas('ai_executions', [
        'subject_type' => $purchase->getMorphClass(),
        'subject_id' => $purchase->id,
        'status' => AiExecution::STATUS_SUCCEEDED,
    ]);
});

it('keeps current AI generated summaries without a v3 functional decision', function () {
    $purchase = preparationSummaryPurchase();
    $item = preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    bindPreparationOpenAiFake([
        'summary' => 'Ayuno de 8 horas.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase)?->refresh();

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->status)->toBe(LaboratoryPurchasePreparationSummary::STATUS_GENERATED)
        ->and($summary->decision_status)->toBeNull()
        ->and($summary->rules_version)->toBeNull()
        ->and($summary->rules_applied)->toBeNull()
        ->and($summary->fallback_reason)->toBeNull()
        ->and($summary->fallback_category)->toBeNull()
        ->and($summary->needs_provider_review)->toBeNull();
});

it('persists existing fidelity fallback as a technical original fallback without provider review', function () {
    $purchase = preparationSummaryPurchase();
    preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);

    $summary = app(LaboratoryPreparationSummaryService::class)
        ->generateFallbackFromSource($purchase)
        ?->refresh();

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->status)->toBe(LaboratoryPurchasePreparationSummary::STATUS_GENERATED)
        ->and($summary->decision_status)->toBe(LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL)
        ->and($summary->fallback_reason)->toBe('ai_fidelity_validation_failed')
        ->and($summary->fallback_category)->toBe(LaboratoryPurchasePreparationSummary::FALLBACK_TECHNICAL_AI_FAILURE)
        ->and($summary->needs_provider_review)->toBeFalse()
        ->and($summary->rules_applied)->toBe([]);
});

it('uses LaboratoryPurchaseItem indications and does not reconstruct from LaboratoryTest', function () {
    $purchase = preparationSummaryPurchase();
    $item = preparationSummaryItem($purchase, [
        'name' => 'Perfil tiroideo',
        'gda_id' => 'GDA-777',
        'indications' => 'Snapshot historico: ayuno de 8 horas.',
    ]);

    LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_id' => 'GDA-777',
        'name' => 'Perfil tiroideo',
        'indications' => 'Indicacion actual del catalogo que no debe usarse.',
    ]);

    $fake = bindPreparationOpenAiFake([
        'summary' => 'Usa snapshot.',
        'sections' => [
            [
                'key' => 'snapshot',
                'title' => 'Snapshot',
                'content' => 'Snapshot historico: ayuno de 8 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    $sent = json_encode($fake->messages, JSON_UNESCAPED_UNICODE);

    expect($sent)->toContain('Snapshot historico: ayuno de 8 horas.')
        ->and($sent)->not->toContain('Indicacion actual del catalogo que no debe usarse.');
});

it('does not call OpenAI again when source hash is unchanged', function () {
    $purchase = preparationSummaryPurchase();
    $item = preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $fake = bindPreparationOpenAiFake([
        'summary' => 'Resumen unico.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $service = app(LaboratoryPreparationSummaryService::class);
    $first = $service->generate($purchase);
    $second = $service->generate($purchase->fresh());

    expect($first?->id)->toBe($second?->id)
        ->and($fake->calls)->toBe(1)
        ->and(AiExecution::query()->count())->toBe(1);
});

it('changes source hash when source indications change and keeps one current summary row', function () {
    $purchase = preparationSummaryPurchase();
    $item = preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $fake = bindPreparationOpenAiFake([
        'summary' => 'Resumen inicial.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $service = app(LaboratoryPreparationSummaryService::class);
    $first = $service->generate($purchase);

    $item->update(['indications' => 'Ayuno de 12 horas.']);

    $fake->content = [
        'summary' => 'Resumen actualizado.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 12 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ];

    $second = $service->generate($purchase->fresh());

    expect($first?->source_hash)->not->toBe($second?->source_hash)
        ->and($second?->summary_text)->toBe('Resumen actualizado.')
        ->and($fake->calls)->toBe(2)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->count())->toBe(1);
});

it('records failed AI execution without breaking the purchase flow', function () {
    $purchase = preparationSummaryPurchase();
    preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    bindPreparationOpenAiFake(fail: true);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1)
        ->and(LaboratoryPurchasePreparationSummary::query()->count())->toBe(0);
});

it('does not include patient PII in the provider payload or redacted request payload', function () {
    $purchase = preparationSummaryPurchase([
        'name' => 'NombreSecreto',
        'paternal_lastname' => 'ApellidoSecreto',
        'phone' => '8199999999',
        'street' => 'Calle Super Secreta',
    ]);
    $item = preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $fake = bindPreparationOpenAiFake([
        'summary' => 'Resumen sin PII.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    $providerPayload = json_encode($fake->messages, JSON_UNESCAPED_UNICODE);
    $storedPayload = json_encode(AiExecution::query()->first()->request_payload_redacted, JSON_UNESCAPED_UNICODE);

    foreach ([$providerPayload, $storedPayload] as $payload) {
        expect($payload)->not->toContain('NombreSecreto')
            ->and($payload)->not->toContain('ApellidoSecreto')
            ->and($payload)->not->toContain('8199999999')
            ->and($payload)->not->toContain('Calle Super Secreta')
            ->and($payload)->not->toContain('sensible@example.test');
    }
});

it('keeps the legacy summary flow when deterministic v3 flag is off', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => false]);

    $purchase = preparationSummaryPurchase();
    $item = preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $fake = bindPreparationOpenAiFake([
        'summary' => 'Resumen legacy.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase)?->refresh();

    expect($summary->decision_status)->toBeNull()
        ->and($summary->summary_text)->toBe('Resumen legacy.')
        ->and($fake->calls)->toBe(1)
        ->and(AiExecution::query()->where('feature', 'laboratory_preparation_summary')->count())->toBe(1)
        ->and(AiExecution::query()->where('feature', 'laboratory_preparation_redactor')->count())->toBe(0);
});

it('runs deterministic v3 auto flow and persists redacted patient text when the flag is on', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-V3-AUTO']);
    preparationSummaryItem($purchase, [
        'name' => 'Glucosa',
        'gda_id' => 'GLU-001',
        'indications' => 'Ayuno de 8 horas.',
    ]);
    $fake = bindPreparationOpenAiFake([
        'title' => LaboratoryPreparationRedactionValidator::TITLE,
        'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
        'patient_text' => '• Acude con ayuno de 8 horas.',
        'bullet_count' => 1,
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase)?->refresh();

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->decision_status)->toBe(LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED)
        ->and($summary->rules_version)->toBe('famedic-indicaciones-v3')
        ->and($summary->rules_applied)->toContain('R01')
        ->and($summary->needs_provider_review)->toBeFalse()
        ->and($summary->summary_text)->toBe('• Acude con ayuno de 8 horas.')
        ->and($summary->summary_json['sections'])->toHaveCount(1)
        ->and($summary->summary_json['decision']['status'])->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($summary->ai_execution_id)->not->toBeNull()
        ->and($fake->calls)->toBe(1)
        ->and(AiExecution::query()->where('feature', 'laboratory_preparation_summary')->count())->toBe(0)
        ->and(AiExecution::query()->where('feature', 'laboratory_preparation_redactor')->count())->toBe(1);
});

it('includes patient context and rules version in deterministic v3 source hash', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);

    $purchase = preparationSummaryPurchase([
        'gda_order_id' => 'ORD-V3-HASH',
        'birth_date' => now()->subYears(30)->toDateString(),
    ]);
    preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $fake = bindPreparationOpenAiFake([
        'title' => LaboratoryPreparationRedactionValidator::TITLE,
        'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
        'patient_text' => '• Acude con ayuno de 8 horas.',
        'bullet_count' => 1,
    ]);

    $service = app(LaboratoryPreparationSummaryService::class);
    $first = $service->generate($purchase)?->refresh();
    $firstHash = $first->source_hash;

    $purchase->update(['birth_date' => now()->subYears(2)->toDateString()]);

    $second = $service->generate($purchase->fresh())?->refresh();

    expect($firstHash)->not->toBe($second->source_hash)
        ->and($second->rules_version)->toBe('famedic-indicaciones-v3')
        ->and($fake->calls)->toBe(2)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->count())->toBe(1);
});

it('runs deterministic v3 fallback flow without calling OpenAI', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-V3-FALLBACK']);
    preparationSummaryItem($purchase, [
        'name' => 'Estudio A',
        'indications' => 'Ayuno de 6 a 8 horas.',
    ]);
    preparationSummaryItem($purchase, [
        'name' => 'Estudio B',
        'indications' => 'Ayuno de 10 a 12 horas.',
    ]);
    $fake = bindPreparationOpenAiFake(fail: true);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase)?->refresh();

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->decision_status)->toBe(LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL)
        ->and($summary->fallback_category)->toBe(LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT)
        ->and($summary->needs_provider_review)->toBeTrue()
        ->and($summary->ai_execution_id)->toBeNull()
        ->and($summary->summary_json['decision']['original_instructions'])->toHaveCount(2)
        ->and($fake->calls)->toBe(0)
        ->and(AiExecution::query()->count())->toBe(0);
});

it('does not send a summary-ready notification for deterministic v3 original fallback', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
    Notification::fake();

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-V3-NOTIFY']);
    preparationSummaryItem($purchase, ['name' => 'Estudio A', 'indications' => 'Ayuno de 6 a 8 horas.']);
    preparationSummaryItem($purchase, ['name' => 'Estudio B', 'indications' => 'Ayuno de 10 a 12 horas.']);
    bindPreparationOpenAiFake(fail: true);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase)?->refresh();
    $sent = app(LaboratoryPreparationSummaryNotificationService::class)->notifyIfNeeded($summary);

    expect($sent)->toBeFalse()
        ->and($summary->refresh()->notified_at)->toBeNull();
    Notification::assertNothingSent();
});

it('does not send a pending deterministic v3 auto notification after rollback to flag off', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
    Notification::fake();

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-V3-ROLLBACK-NOTIFY']);
    preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    bindPreparationOpenAiFake([
        'title' => LaboratoryPreparationRedactionValidator::TITLE,
        'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
        'patient_text' => '• Acude con ayuno de 8 horas.',
        'bullet_count' => 1,
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase)?->refresh();

    config(['services.laboratory_preparation.deterministic_v3_enabled' => false]);
    $sent = app(LaboratoryPreparationSummaryNotificationService::class)->notifyIfNeeded($summary);

    expect($sent)->toBeFalse()
        ->and($summary->refresh()->notified_at)->toBeNull();
    Notification::assertNothingSent();
});

it('persists deterministic v3 technical OpenAI failures as original fallback without provider review', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-V3-TECH']);
    preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $fake = bindPreparationOpenAiFake(fail: true);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase)?->refresh();

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->decision_status)->toBe(LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL)
        ->and($summary->fallback_category)->toBe(LaboratoryPurchasePreparationSummary::FALLBACK_TECHNICAL_AI_FAILURE)
        ->and($summary->fallback_reason)->toBe('ai_redaction_failed')
        ->and($summary->needs_provider_review)->toBeFalse()
        ->and($summary->ai_execution_id)->not->toBeNull()
        ->and($summary->aiExecution->status)->toBe(AiExecution::STATUS_FAILED)
        ->and($fake->calls)->toBe(1);
});

it('records deterministic v3 shadow fallback without changing the visible legacy summary', function () {
    config([
        'services.laboratory_preparation.deterministic_v3_enabled' => false,
        'services.laboratory_preparation.deterministic_v3_shadow_enabled' => true,
    ]);
    Log::spy();

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-SHADOW-FALLBACK']);
    preparationSummaryItem($purchase, ['name' => 'Estudio A', 'indications' => 'Ayuno de 6 a 8 horas.']);
    preparationSummaryItem($purchase, ['name' => 'Estudio B', 'indications' => 'Ayuno de 10 a 12 horas.']);
    LaboratoryPurchasePreparationSummary::query()->create([
        'laboratory_purchase_id' => $purchase->id,
        'source_hash' => 'legacy-visible-hash',
        'status' => LaboratoryPurchasePreparationSummary::STATUS_GENERATED,
        'summary_text' => 'Resumen legacy intacto.',
        'summary_json' => ['summary' => 'Resumen legacy intacto.'],
        'generated_at' => now(),
    ]);

    $decision = app(LaboratoryPreparationSummaryService::class)->runDeterministicV3Shadow($purchase);

    expect($decision?->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision?->fallbackCategory)->toBe(LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT)
        ->and($purchase->preparationSummary()->first()?->summary_text)->toBe('Resumen legacy intacto.')
        ->and(AiExecution::query()->count())->toBe(0);

    Log::shouldHaveReceived('info')
        ->with('laboratory_preparation_v3_shadow_completed', Mockery::on(
            fn (array $context): bool => $context['purchase_id'] === $purchase->id
                && $context['decision_status'] === LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL
                && $context['fallback_category'] === LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT
                && $context['needs_provider_review'] === true
                && ! array_key_exists('original_instructions', $context)
        ))
        ->once();
});

it('handles deterministic v3 shadow parser errors without persisting or throwing', function () {
    config(['services.laboratory_preparation.deterministic_v3_shadow_enabled' => true]);
    Log::spy();

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-SHADOW-PARSER-ERROR']);
    preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);

    $parser = Mockery::mock(LaboratoryInstructionParser::class);
    $parser->shouldReceive('parseItems')->once()->andThrow(new RuntimeException('parser down'));
    app()->instance(LaboratoryInstructionParser::class, $parser);

    $decision = app(LaboratoryPreparationSummaryService::class)->runDeterministicV3Shadow($purchase);

    expect($decision)->toBeNull()
        ->and(LaboratoryPurchasePreparationSummary::query()->count())->toBe(0)
        ->and(AiExecution::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->with('laboratory_preparation_v3_shadow_failed', Mockery::on(
            fn (array $context): bool => $context['purchase_id'] === $purchase->id
                && $context['exception'] === RuntimeException::class
        ))
        ->once();
});

it('handles deterministic v3 shadow engine errors without persisting or throwing', function () {
    config(['services.laboratory_preparation.deterministic_v3_shadow_enabled' => true]);
    Log::spy();

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-SHADOW-ENGINE-ERROR']);
    preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);

    $engine = Mockery::mock(LaboratoryPreparationRuleEngine::class);
    $engine->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('engine down'));
    app()->instance(LaboratoryPreparationRuleEngine::class, $engine);

    $decision = app(LaboratoryPreparationSummaryService::class)->runDeterministicV3Shadow($purchase);

    expect($decision)->toBeNull()
        ->and(LaboratoryPurchasePreparationSummary::query()->count())->toBe(0)
        ->and(AiExecution::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->with('laboratory_preparation_v3_shadow_failed', Mockery::on(
            fn (array $context): bool => $context['purchase_id'] === $purchase->id
                && $context['exception'] === RuntimeException::class
        ))
        ->once();
});

it('records deterministic v3 shadow fallback for an order without indications', function () {
    config(['services.laboratory_preparation.deterministic_v3_shadow_enabled' => true]);
    Log::spy();

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-SHADOW-NO-INDICATIONS']);
    preparationSummaryItem($purchase, ['indications' => null]);

    $decision = app(LaboratoryPreparationSummaryService::class)->runDeterministicV3Shadow($purchase);

    expect($decision?->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision?->fallbackReason)->toBe('no_phase_3a_requirements_detected')
        ->and(AiExecution::query()->count())->toBe(0);

    Log::shouldHaveReceived('info')
        ->with('laboratory_preparation_v3_shadow_completed', Mockery::on(
            fn (array $context): bool => $context['purchase_id'] === $purchase->id
                && $context['fallback_reason'] === 'no_phase_3a_requirements_detected'
        ))
        ->once();
});

it('skips deterministic v3 shadow when the expected snapshot is stale', function () {
    config(['services.laboratory_preparation.deterministic_v3_shadow_enabled' => true]);
    Log::spy();

    $purchase = preparationSummaryPurchase(['gda_order_id' => 'ORD-SHADOW-STALE']);
    $item = preparationSummaryItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $service = app(LaboratoryPreparationSummaryService::class);
    $expectedHash = $service->deterministicV3SourceHash($purchase->fresh('laboratoryPurchaseItems'));

    $item->update(['indications' => 'Ayuno de 12 horas.']);

    $decision = $service->runDeterministicV3Shadow($purchase->fresh('laboratoryPurchaseItems'), $expectedHash);

    expect($decision)->toBeNull()
        ->and(LaboratoryPurchasePreparationSummary::query()->count())->toBe(0)
        ->and(AiExecution::query()->count())->toBe(0);

    Log::shouldHaveReceived('info')
        ->with('laboratory_preparation_v3_shadow_skipped_stale_source', Mockery::on(
            fn (array $context): bool => $context['purchase_id'] === $purchase->id
                && $context['expected_source_hash'] === $expectedHash
        ))
        ->once();
});
