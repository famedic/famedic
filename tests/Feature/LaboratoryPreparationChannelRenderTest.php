<?php

use App\Actions\Laboratories\LaboratoryPurchaseConfirmationViewData;
use App\Enums\LaboratoryBrand;
use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Models\User;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSource;
use App\Services\OpenAi\OpenAiClient;

function channelRenderPurchase(array $attributes = []): LaboratoryPurchase
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
        'gda_order_id' => 'gda-channel-'.fake()->unique()->numerify('######'),
        'gda_consecutivo' => fake()->unique()->numberBetween(100000, 999999),
        'name' => 'Paciente',
        'paternal_lastname' => 'Channel',
        'maternal_lastname' => 'Render',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle Channel',
        'number' => '123',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'additional_references' => 'Prueba',
        'total_cents' => 100000,
    ], $attributes));
}

function channelRenderItem(LaboratoryPurchase $purchase, array $attributes = []): LaboratoryPurchaseItem
{
    return LaboratoryPurchaseItem::query()->create(array_merge([
        'laboratory_purchase_id' => $purchase->id,
        'name' => 'Biometria hematica',
        'gda_id' => 'BH-001',
        'indications' => 'Ayuno de 8 horas.',
        'feature_list' => ['Hemoglobina'],
        'price_cents' => 50000,
    ], $attributes));
}

function channelRenderAiSummary(LaboratoryPurchase $purchase, array $summaryJson = [], array $attributes = []): LaboratoryPurchasePreparationSummary
{
    $source = app(LaboratoryPreparationSource::class);
    $input = $source->buildInput($purchase->fresh('laboratoryPurchaseItems'));
    $hash = $source->hash($input);
    $prompt = AiPrompt::query()->create([
        'key' => 'laboratory_preparation_summary',
        'domain' => 'laboratory',
        'version' => 99,
        'status' => AiPrompt::STATUS_ACTIVE,
        'model' => 'gpt-4o-mini',
        'system_prompt' => 'Sistema',
        'user_prompt' => 'Usuario',
        'response_schema' => [],
    ]);
    $execution = AiExecution::query()->create([
        'domain' => 'laboratory',
        'feature' => 'laboratory_preparation_summary',
        'subject_type' => $purchase->getMorphClass(),
        'subject_id' => $purchase->id,
        'prompt_id' => $prompt->id,
        'prompt_version' => $prompt->version,
        'model' => 'gpt-4o-mini',
        'status' => $attributes['execution_status'] ?? AiExecution::STATUS_SUCCEEDED,
        'input_hash' => $hash,
        'duration_ms' => 15,
    ]);

    unset($attributes['execution_status']);

    return LaboratoryPurchasePreparationSummary::query()->create(array_merge([
        'laboratory_purchase_id' => $purchase->id,
        'ai_execution_id' => $execution->id,
        'source_hash' => $hash,
        'status' => LaboratoryPurchasePreparationSummary::STATUS_GENERATED,
        'summary_text' => 'Resumen consolidado para canales.',
        'summary_json' => array_merge([
            'summary' => 'Resumen consolidado para canales.',
            'sections' => [
                [
                    'key' => 'ayuno',
                    'title' => 'Ayuno',
                    'content' => '• Presentarse con ayuno de 8 - 14 horas.',
                    'source_item_ids' => [$purchase->laboratoryPurchaseItems()->first()->id],
                ],
            ],
            'special_instructions' => [],
            'individual_instructions' => [],
        ], $summaryJson),
        'generated_at' => now(),
        'invalidated_at' => null,
    ], $attributes));
}

function channelRenderOpenAiSpy(): object
{
    $fake = new class extends OpenAiClient
    {
        public int $calls = 0;

        public function chatCompletionWithMetadata(
            array $messages,
            ?string $model = null,
            ?array $jsonSchema = null,
            ?string $schemaName = null,
            float $temperature = 0,
        ): array {
            $this->calls++;

            throw new RuntimeException('Channel render must not call OpenAI.');
        }
    };

    app()->instance(OpenAiClient::class, $fake);

    return $fake;
}

function channelRenderData(LaboratoryPurchase $purchase): array
{
    return LaboratoryPurchaseConfirmationViewData::build(
        $purchase->fresh(['laboratoryPurchaseItems', 'preparationSummary.aiExecution']),
        $purchase->customer->user,
        true,
    );
}

function channelRenderEmailHtml(array $data): string
{
    return view('emails.laboratory.components.preparation', [
        'preparation' => $data['preparation'],
        'studies' => $data['studies'],
        'showIntro' => true,
    ])->render();
}

function channelRenderPdfHtml(array $data): string
{
    return view('pdfs.laboratory-purchase-order', array_merge($data, ['withAppointment' => false]))->render();
}

function channelRender2448LikeSummary(LaboratoryPurchase $purchase, array $itemIds): array
{
    return [
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => "• Presentarse con ayuno de 8 - 14 horas.\n• Evitar alimentos grasos.",
                'source_item_ids' => [$itemIds[0]],
            ],
            [
                'key' => 'alimentacion',
                'title' => 'Alimentación y tiempos',
                'content' => '• El recién nacido deberá haber tenido al menos 2 ingestas de alimento.',
                'source_item_ids' => [$itemIds[1]],
            ],
            [
                'key' => 'recoleccion',
                'title' => 'Recolección de muestras',
                'content' => '• Desechar el primer chorro y recolectar el chorro medio.',
                'source_item_ids' => [$itemIds[2]],
            ],
            [
                'key' => 'gestacion',
                'title' => 'Tiempo gestacional',
                'content' => '• Realizar entre las semanas 14 a 22 de gestación.',
                'source_item_ids' => [$itemIds[3]],
            ],
        ],
        'special_instructions' => [
            [
                'content' => 'Llevar copia de interpretación de ultrasonido.',
                'source_item_ids' => [$itemIds[3]],
            ],
        ],
        'individual_instructions' => [
            [
                'study_name' => 'Tirosina',
                'content' => '• Realizar preferentemente entre el tercer y quinto día de vida.',
                'source_item_id' => $itemIds[1],
            ],
        ],
    ];
}

it('renders ai sections in email when preparation is ai ready', function () {
    $fake = channelRenderOpenAiSpy();
    $purchase = channelRenderPurchase();
    channelRenderItem($purchase, [
        'name' => 'Perfil lipidico',
        'indications' => 'Texto original largo que no debe ser el contenido principal del email.',
    ]);
    channelRenderAiSummary($purchase->fresh('laboratoryPurchaseItems'));

    $data = channelRenderData($purchase);
    $emailHtml = channelRenderEmailHtml($data);

    expect($data['preparation']['ai_status'])->toBe('AI_READY')
        ->and($fake->calls)->toBe(0)
        ->and($emailHtml)->toContain('Preparación para tus estudios')
        ->and($emailHtml)->toContain('Ayuno')
        ->and($emailHtml)->toContain('Presentarse con ayuno de 8 - 14 horas.')
        ->and($emailHtml)->not->toContain('Texto original largo que no debe ser el contenido principal del email.');
});

it('renders pending message and original instructions in email when preparation is ai pending', function () {
    $fake = channelRenderOpenAiSpy();
    $purchase = channelRenderPurchase();
    channelRenderItem($purchase, ['indications' => 'Ayuno de 12 horas antes del estudio.']);

    $source = app(LaboratoryPreparationSource::class);
    $hash = $source->hash($source->buildInput($purchase->fresh('laboratoryPurchaseItems')));
    AiExecution::query()->create([
        'domain' => 'laboratory',
        'feature' => 'laboratory_preparation_summary',
        'subject_type' => $purchase->getMorphClass(),
        'subject_id' => $purchase->id,
        'status' => AiExecution::STATUS_QUEUED,
        'input_hash' => $hash,
        'duration_ms' => null,
    ]);

    $data = channelRenderData($purchase);
    $emailHtml = channelRenderEmailHtml($data);

    expect($data['preparation']['ai_status'])->toBe('AI_PENDING')
        ->and($fake->calls)->toBe(0)
        ->and($emailHtml)->toContain('Ayuno de 12 horas antes del estudio.')
        ->and($emailHtml)->toContain('Estamos preparando un resumen más sencillo de estas indicaciones.')
        ->and($emailHtml)->not->toMatch('/OpenAI|GPT|tokens|execution/i');
});

it('renders original instructions in email when preparation failed without technical errors', function () {
    $purchase = channelRenderPurchase();
    channelRenderItem($purchase, ['indications' => 'Evitar alcohol 24 horas.']);

    $source = app(LaboratoryPreparationSource::class);
    $hash = $source->hash($source->buildInput($purchase->fresh('laboratoryPurchaseItems')));
    AiExecution::query()->create([
        'domain' => 'laboratory',
        'feature' => 'laboratory_preparation_summary',
        'subject_type' => $purchase->getMorphClass(),
        'subject_id' => $purchase->id,
        'status' => AiExecution::STATUS_FAILED,
        'input_hash' => $hash,
        'duration_ms' => 10,
        'error_message' => 'Provider timeout',
    ]);

    $data = channelRenderData($purchase);
    $emailHtml = channelRenderEmailHtml($data);

    expect($data['preparation']['ai_status'])->toBe('AI_FAILED')
        ->and($emailHtml)->toContain('Evitar alcohol 24 horas.')
        ->and($emailHtml)->not->toContain('Provider timeout')
        ->and($emailHtml)->not->toContain('AI_FAILED');
});

it('renders original instructions in email when preparation is fallback', function () {
    $purchase = channelRenderPurchase();
    channelRenderItem($purchase, ['indications' => 'Tomar agua antes del estudio.']);

    $data = channelRenderData($purchase);
    $emailHtml = channelRenderEmailHtml($data);

    expect($data['preparation']['ai_status'])->toBe('FALLBACK')
        ->and($emailHtml)->toContain('Tomar agua antes del estudio.');
});

it('renders special and individual instructions in email when ai ready', function () {
    $purchase = channelRenderPurchase();
    $item = channelRenderItem($purchase, ['indications' => 'Indicacion historica.']);
    channelRenderAiSummary($purchase->fresh('laboratoryPurchaseItems'), [
        'sections' => [
            [
                'key' => 'general',
                'title' => 'Preparacion general',
                'content' => '• Seguir indicaciones de ayuno.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [
            [
                'content' => 'Confirmar cita con 24 horas de anticipacion.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'individual_instructions' => [
            [
                'study_name' => 'Urocultivo',
                'content' => '• Recolectar primera orina de la manana.',
                'source_item_id' => $item->id,
            ],
        ],
    ]);

    $emailHtml = channelRenderEmailHtml(channelRenderData($purchase));

    expect($emailHtml)->toContain('Importante')
        ->and($emailHtml)->toContain('Confirmar cita con 24 horas de anticipacion.')
        ->and($emailHtml)->toContain('Indicaciones específicas')
        ->and($emailHtml)->toContain('Urocultivo')
        ->and($emailHtml)->toContain('Recolectar primera orina de la manana.');
});

it('renders ai sections in pdf when preparation is ai ready', function () {
    $fake = channelRenderOpenAiSpy();
    $purchase = channelRenderPurchase();
    channelRenderItem($purchase, [
        'indications' => 'Texto original del PDF que no debe dominar la salida.',
    ]);
    channelRenderAiSummary($purchase->fresh('laboratoryPurchaseItems'));

    $data = channelRenderData($purchase);
    $pdfHtml = channelRenderPdfHtml($data);

    expect($data['preparation']['ai_status'])->toBe('AI_READY')
        ->and($fake->calls)->toBe(0)
        ->and($pdfHtml)->toContain('Preparación para tus estudios')
        ->and($pdfHtml)->toContain('Ayuno')
        ->and($pdfHtml)->toContain('Presentarse con ayuno de 8 - 14 horas.')
        ->and($pdfHtml)->not->toContain('Texto original del PDF que no debe dominar la salida.');
});

it('renders original instructions in pdf when preparation is ai pending', function () {
    $purchase = channelRenderPurchase();
    channelRenderItem($purchase, ['indications' => 'Ayuno de 10 horas.']);

    $source = app(LaboratoryPreparationSource::class);
    $hash = $source->hash($source->buildInput($purchase->fresh('laboratoryPurchaseItems')));
    AiExecution::query()->create([
        'domain' => 'laboratory',
        'feature' => 'laboratory_preparation_summary',
        'subject_type' => $purchase->getMorphClass(),
        'subject_id' => $purchase->id,
        'status' => AiExecution::STATUS_PROCESSING,
        'input_hash' => $hash,
        'duration_ms' => null,
    ]);

    $pdfHtml = channelRenderPdfHtml(channelRenderData($purchase));

    expect($pdfHtml)->toContain('Ayuno de 10 horas.')
        ->and($pdfHtml)->toContain('Estamos preparando un resumen más sencillo de estas indicaciones.');
});

it('renders original instructions in pdf when preparation is fallback', function () {
    $purchase = channelRenderPurchase();
    channelRenderItem($purchase, ['indications' => 'Presentarse hidratado.']);

    $pdfHtml = channelRenderPdfHtml(channelRenderData($purchase));

    expect($pdfHtml)->toContain('Presentarse hidratado.');
});

it('renders special and individual instructions in pdf when ai ready', function () {
    $purchase = channelRenderPurchase();
    $item = channelRenderItem($purchase);
    channelRenderAiSummary($purchase->fresh('laboratoryPurchaseItems'), [
        'special_instructions' => [
            ['content' => 'Usar ropa comoda.', 'source_item_ids' => [$item->id]],
        ],
        'individual_instructions' => [
            [
                'study_name' => 'Glucosa',
                'content' => '• Ayuno estricto de 8 horas.',
                'source_item_id' => $item->id,
            ],
        ],
    ]);

    $pdfHtml = channelRenderPdfHtml(channelRenderData($purchase));

    expect($pdfHtml)->toContain('Importante')
        ->and($pdfHtml)->toContain('Usar ropa comoda.')
        ->and($pdfHtml)->toContain('Indicaciones específicas')
        ->and($pdfHtml)->toContain('Glucosa');
});

it('renders multi section ai preparation consistently in email and pdf like purchase 2448', function () {
    $fake = channelRenderOpenAiSpy();
    $purchase = channelRenderPurchase();
    $first = channelRenderItem($purchase, ['name' => 'Perfil completo', 'indications' => 'Ayuno original.']);
    $second = channelRenderItem($purchase, ['name' => 'Tirosina', 'indications' => 'Ingestas original.']);
    $third = channelRenderItem($purchase, ['name' => 'Urocultivo', 'indications' => 'Orina original.']);
    $fourth = channelRenderItem($purchase, ['name' => 'Tetra Marcador', 'indications' => 'Gestacion original.']);

    channelRenderAiSummary(
        $purchase->fresh('laboratoryPurchaseItems'),
        channelRender2448LikeSummary($purchase, [$first->id, $second->id, $third->id, $fourth->id]),
    );

    $data = channelRenderData($purchase);
    $emailHtml = channelRenderEmailHtml($data);
    $pdfHtml = channelRenderPdfHtml($data);

    foreach ([
        'Alimentación y tiempos',
        'Recolección de muestras',
        'Tiempo gestacional',
        'copia de interpretación de ultrasonido',
        'Tirosina',
        'tercer y quinto día de vida',
    ] as $needle) {
        expect($emailHtml)->toContain($needle)
            ->and($pdfHtml)->toContain($needle);
    }

    expect($fake->calls)->toBe(0)
        ->and($emailHtml)->not->toContain('Gestacion original.')
        ->and($pdfHtml)->not->toContain('Gestacion original.');
});

it('shows purchase item indications in email and pdf when no preparation summary exists', function () {
    $fake = channelRenderOpenAiSpy();
    $purchase = channelRenderPurchase();
    channelRenderItem($purchase, ['indications' => 'Indicacion historica sin resumen AI.']);

    $data = channelRenderData($purchase);
    $emailHtml = channelRenderEmailHtml($data);
    $pdfHtml = channelRenderPdfHtml($data);

    expect($data['preparation']['ai_status'])->toBe('FALLBACK')
        ->and($fake->calls)->toBe(0)
        ->and($emailHtml)->toContain('Indicacion historica sin resumen AI.')
        ->and($pdfHtml)->toContain('Indicacion historica sin resumen AI.');
});
