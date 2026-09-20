<?php

use App\Actions\Laboratories\LaboratoryPurchaseConfirmationViewData;
use App\Enums\LaboratoryBrand;
use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPresenter;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSource;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Http\Request;

function preparationPresenterPurchase(array $attributes = []): LaboratoryPurchase
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
        'gda_order_id' => 'gda-presenter-'.fake()->unique()->numerify('######'),
        'gda_consecutivo' => fake()->unique()->numberBetween(100000, 999999),
        'name' => 'Paciente',
        'paternal_lastname' => 'Presenter',
        'maternal_lastname' => 'Seguro',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle Presenter',
        'number' => '123',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'additional_references' => 'Prueba',
        'total_cents' => 100000,
    ], $attributes));
}

function preparationPresenterItem(LaboratoryPurchase $purchase, array $attributes = []): LaboratoryPurchaseItem
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

function preparationPresenterAiSummary(LaboratoryPurchase $purchase, array $summaryJson = [], array $attributes = []): LaboratoryPurchasePreparationSummary
{
    $source = app(LaboratoryPreparationSource::class);
    $input = $source->buildInput($purchase->fresh('laboratoryPurchaseItems'));
    $hash = $source->hash($input);
    $prompt = AiPrompt::query()->create([
        'key' => 'laboratory_preparation_summary',
        'domain' => 'laboratory',
        'version' => 7,
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
        'summary_text' => 'Resumen IA consolidado.',
        'summary_json' => array_merge([
            'summary' => 'Resumen IA consolidado.',
            'sections' => [
                [
                    'key' => 'ayuno',
                    'title' => 'Ayuno',
                    'content' => 'Ayuno de 8 horas.',
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

function bindPresenterOpenAiSpy(): object
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

            throw new RuntimeException('Presenter must not call OpenAI.');
        }
    };

    app()->instance(OpenAiClient::class, $fake);

    return $fake;
}

it('uses ai summary text and json when a valid summary exists', function () {
    $purchase = preparationPresenterPurchase();
    $item = preparationPresenterItem($purchase);
    preparationPresenterAiSummary($purchase->fresh('laboratoryPurchaseItems'), [
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [
            [
                'content' => 'Presentarse hidratado.',
                'source_item_ids' => [$item->id],
            ],
        ],
    ]);

    $presented = app(LaboratoryPreparationPresenter::class)->present($purchase->fresh());

    expect($presented['has_ai_summary'])->toBeTrue()
        ->and($presented['ai_status'])->toBe('AI_READY')
        ->and($presented['source'])->toBe('ai')
        ->and($presented['summary']['text'])->toBe('Resumen IA consolidado.')
        ->and($presented['summary']['sections'][0]['source_item_ids'])->toBe([$item->id])
        ->and($presented['summary']['special_instructions'][0]['source_item_ids'])->toBe([$item->id])
        ->and($presented['prompt_version'])->toBe(7);
});

it('falls back to purchase item indications when no valid summary exists', function () {
    $purchase = preparationPresenterPurchase();
    preparationPresenterItem($purchase, ['name' => 'Perfil tiroideo', 'indications' => 'Snapshot historico.']);

    $presented = app(LaboratoryPreparationPresenter::class)->present($purchase);

    expect($presented['has_ai_summary'])->toBeFalse()
        ->and($presented['ai_status'])->toBe('FALLBACK')
        ->and($presented['source'])->toBe('fallback')
        ->and($presented['summary']['text'])->toBeNull()
        ->and($presented['individual_instructions'][0]['study_name'])->toBe('Perfil tiroideo')
        ->and($presented['individual_instructions'][0]['indications'])->toBe('Snapshot historico.')
        ->and($presented['studies'][0]['instructions'])->toBe('Snapshot historico.');
});

it('uses fallback for failed stale invalidated corrupt or empty summaries', function (array $attributes) {
    $purchase = preparationPresenterPurchase();
    preparationPresenterItem($purchase, ['indications' => 'Indicacion historica.']);
    preparationPresenterAiSummary($purchase->fresh('laboratoryPurchaseItems'), [], $attributes);

    $presented = app(LaboratoryPreparationPresenter::class)->present($purchase->fresh());

    expect($presented['source'])->toBe('fallback')
        ->and($presented['has_ai_summary'])->toBeFalse()
        ->and($presented['studies'][0]['instructions'])->toBe('Indicacion historica.');
})->with([
    'failed execution' => [['execution_status' => AiExecution::STATUS_FAILED]],
    'stale status' => [['status' => LaboratoryPurchasePreparationSummary::STATUS_STALE]],
    'invalidated' => [['invalidated_at' => now()]],
    'missing text' => [['summary_text' => null]],
    'corrupt json contract' => [['summary_json' => ['summary' => 'Incompleto']]],
]);

it('never consults LaboratoryTest indications when presenting a historical purchase', function () {
    $purchase = preparationPresenterPurchase();
    preparationPresenterItem($purchase, [
        'gda_id' => 'CAT-001',
        'name' => 'Quimica sanguinea',
        'indications' => 'Snapshot de compra.',
    ]);
    LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_id' => 'CAT-001',
        'name' => 'Quimica sanguinea',
        'indications' => 'Indicacion actual de catalogo que no debe aparecer.',
    ]);

    $presented = app(LaboratoryPreparationPresenter::class)->present($purchase);
    $payload = json_encode($presented, JSON_UNESCAPED_UNICODE);

    expect($payload)->toContain('Snapshot de compra.')
        ->and($payload)->not->toContain('Indicacion actual de catalogo que no debe aparecer.');
});

it('falls back when the persisted summary hash no longer matches purchase item indications', function () {
    $purchase = preparationPresenterPurchase();
    $item = preparationPresenterItem($purchase, ['indications' => 'Ayuno 8 horas.']);
    preparationPresenterAiSummary($purchase->fresh('laboratoryPurchaseItems'));

    $item->update(['indications' => 'Ayuno 12 horas.']);

    $presented = app(LaboratoryPreparationPresenter::class)->present($purchase->fresh());

    expect($presented['source'])->toBe('fallback')
        ->and($presented['ai_status'])->toBe('FALLBACK')
        ->and($presented['has_ai_summary'])->toBeFalse()
        ->and($presented['studies'][0]['instructions'])->toBe('Ayuno 12 horas.');
});

it('returns AI_PENDING when a current execution is queued or processing', function (string $status) {
    $purchase = preparationPresenterPurchase();
    preparationPresenterItem($purchase, ['indications' => 'Ayuno 8 horas.']);
    $source = app(LaboratoryPreparationSource::class);
    $input = $source->buildInput($purchase->fresh('laboratoryPurchaseItems'));

    AiExecution::query()->create([
        'domain' => 'laboratory',
        'feature' => 'laboratory_preparation_summary',
        'subject_type' => $purchase->getMorphClass(),
        'subject_id' => $purchase->id,
        'status' => $status,
        'input_hash' => $source->hash($input),
    ]);

    $presented = app(LaboratoryPreparationPresenter::class)->present($purchase->fresh());

    expect($presented['has_ai_summary'])->toBeFalse()
        ->and($presented['ai_status'])->toBe('AI_PENDING')
        ->and($presented['source'])->toBe('fallback')
        ->and($presented['studies'][0]['instructions'])->toBe('Ayuno 8 horas.');
})->with([
    AiExecution::STATUS_QUEUED,
    AiExecution::STATUS_PROCESSING,
]);

it('returns AI_FAILED when the current generation execution failed', function () {
    $purchase = preparationPresenterPurchase();
    preparationPresenterItem($purchase, ['indications' => 'Ayuno 8 horas.']);
    $source = app(LaboratoryPreparationSource::class);
    $input = $source->buildInput($purchase->fresh('laboratoryPurchaseItems'));

    AiExecution::query()->create([
        'domain' => 'laboratory',
        'feature' => 'laboratory_preparation_summary',
        'subject_type' => $purchase->getMorphClass(),
        'subject_id' => $purchase->id,
        'status' => AiExecution::STATUS_FAILED,
        'input_hash' => $source->hash($input),
        'error' => 'Proveedor no disponible',
    ]);

    $presented = app(LaboratoryPreparationPresenter::class)->present($purchase->fresh());

    expect($presented['has_ai_summary'])->toBeFalse()
        ->and($presented['ai_status'])->toBe('AI_FAILED')
        ->and($presented['source'])->toBe('fallback')
        ->and($presented['studies'][0]['instructions'])->toBe('Ayuno 8 horas.');
});

it('does not call OpenAI and keeps individual instructions available', function () {
    $fake = bindPresenterOpenAiSpy();
    $purchase = preparationPresenterPurchase();
    $first = preparationPresenterItem($purchase, ['name' => 'Estudio A', 'indications' => 'Ayuno.']);
    $second = preparationPresenterItem($purchase, ['name' => 'Estudio B', 'indications' => 'Llevar muestra.']);
    preparationPresenterAiSummary($purchase->fresh('laboratoryPurchaseItems'), [
        'sections' => [
            [
                'key' => 'general',
                'title' => 'General',
                'content' => 'Ayuno y muestra.',
                'source_item_ids' => [$first->id, $second->id],
            ],
        ],
    ]);

    $presented = app(LaboratoryPreparationPresenter::class)->present($purchase->fresh());

    expect($fake->calls)->toBe(0)
        ->and($presented['individual_instructions'])->toHaveCount(2)
        ->and($presented['summary']['sections'][0]['source_item_ids'])->toBe([$first->id, $second->id]);
});

it('represents one study multiple studies and missing indications safely', function () {
    $oneStudy = preparationPresenterPurchase();
    preparationPresenterItem($oneStudy, ['indications' => 'Ayuno.']);

    $multiple = preparationPresenterPurchase();
    preparationPresenterItem($multiple, ['name' => 'Sin indicaciones', 'indications' => null]);
    preparationPresenterItem($multiple, ['name' => 'Vacias', 'indications' => '']);
    preparationPresenterItem($multiple, ['name' => 'Con texto', 'indications' => 'Tomar agua.']);

    $one = app(LaboratoryPreparationPresenter::class)->present($oneStudy);
    $many = app(LaboratoryPreparationPresenter::class)->present($multiple);

    expect($one['studies'])->toHaveCount(1)
        ->and($one['studies'][0]['instructions'])->toBe('Ayuno.')
        ->and($many['studies'])->toHaveCount(3)
        ->and($many['individual_instructions'][0]['indications'])->toBeNull()
        ->and($many['studies'][0]['instructions'])->toBe('—')
        ->and($many['studies'][1]['instructions'])->toBe('—')
        ->and($many['studies'][2]['instructions'])->toBe('Tomar agua.');
});

it('makes confirmation email and pdf data consume the preparation representation', function () {
    $fake = bindPresenterOpenAiSpy();
    $purchase = preparationPresenterPurchase();
    preparationPresenterItem($purchase, ['name' => 'Perfil lipidico', 'indications' => 'Ayuno 12 horas.']);
    preparationPresenterAiSummary($purchase->fresh('laboratoryPurchaseItems'));
    $notifiable = $purchase->customer->user;

    $data = LaboratoryPurchaseConfirmationViewData::build($purchase->fresh(), $notifiable, true);
    $emailHtml = view('emails.laboratory.components.preparation', [
        'preparation' => $data['preparation'],
        'studies' => $data['studies'],
        'showIntro' => true,
    ])->render();
    $pdfHtml = view('pdfs.laboratory-purchase-order', array_merge($data, ['withAppointment' => false]))->render();

    expect($data['preparation']['source'])->toBe('ai')
        ->and($fake->calls)->toBe(0)
        ->and($emailHtml)->toContain('Preparación para tus estudios')
        ->and($emailHtml)->toContain('Ayuno')
        ->and($emailHtml)->toContain('Ayuno de 8 horas.')
        ->and($emailHtml)->not->toContain('Ayuno 12 horas.')
        ->and($pdfHtml)->toContain('Preparación para tus estudios')
        ->and($pdfHtml)->toContain('Ayuno de 8 horas.')
        ->and($pdfHtml)->not->toContain('Ayuno 12 horas.');
});

it('adds preparation to shared resource while keeping compatibility fields', function () {
    $purchase = preparationPresenterPurchase();
    preparationPresenterItem($purchase, ['name' => 'Biometria hematica', 'indications' => 'Ayuno 8 horas']);
    preparationPresenterAiSummary($purchase->fresh('laboratoryPurchaseItems'));

    $payload = (new App\Http\Resources\SharedLaboratoryPurchaseResource($purchase->fresh([
        'customer.user',
        'laboratoryPurchaseItems',
        'preparationSummary.aiExecution',
    ])))->toArray(Request::create('/'));

    expect($payload['studies'][0]['indications'])->toBe('Ayuno 8 horas')
        ->and($payload['preparation']['source'])->toBe('ai')
        ->and($payload['preparation']['studies'][0]['instructions'])->toBe('Ayuno 8 horas')
        ->and($payload)->toHaveKey('studies')
        ->and($payload)->toHaveKey('preparation');
});
