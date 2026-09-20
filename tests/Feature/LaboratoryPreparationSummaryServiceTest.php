<?php

use App\Enums\LaboratoryBrand;
use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService;
use App\Services\OpenAi\OpenAiClient;

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
