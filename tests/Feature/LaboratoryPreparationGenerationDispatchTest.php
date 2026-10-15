<?php

use App\Actions\Laboratories\CreateGDAQuotationAction;
use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Enums\GdaOrderStatus;
use App\Enums\LaboratoryBrand;
use App\Exceptions\GdaOrderResultUncertainException;
use App\Jobs\GenerateLaboratoryPurchasePreparationSummaryJob;
use App\Models\Address;
use App\Models\AiExecution;
use App\Models\Contact;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LaboratoryPreparation\LaboratoryPreparationDecision;
use App\Services\LaboratoryPreparation\LaboratoryPreparationEligibility;
use App\Services\LaboratoryPreparation\LaboratoryPreparationFidelityValidationException;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRedactionValidator;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSource;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryNotificationService;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LaboratoryPreparation\PreservesOfficialPreparationPrompts;

uses(PreservesOfficialPreparationPrompts::class);

function preparationDispatchFixture(): array
{
    $user = User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);

    $brand = LaboratoryBrand::OLAB;
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'name' => 'Biometria hematica',
        'gda_id' => 'BH-DISPATCH',
        'indications' => 'Ayuno de 8 horas.',
        'requires_appointment' => false,
        'public_price_cents' => 50000,
        'famedic_price_cents' => 39900,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    return [
        $user,
        $brand,
        Address::factory()->create(['customer_id' => $user->customer->id]),
        Contact::factory()->create(['customer_id' => $user->customer->id]),
        Transaction::factory()->create([
            'transaction_amount_cents' => 39900,
            'payment_method' => 'odessa',
            'reference_id' => 'ai-prep-dispatch-test',
        ]),
    ];
}

function fulfillPreparationDispatchPurchase(array $fixture): LaboratoryPurchase
{
    [$user, $brand, $address, $contact, $transaction] = $fixture;
    $items = $user->customer->laboratoryCartItems()
        ->ofBrand($brand)
        ->with('laboratoryTest')
        ->get();

    return app(FulfillLaboratoryCartOrderAction::class)(
        $user->customer,
        $brand,
        $address,
        $contact,
        $transaction,
        null,
        $items,
        $brand->value,
    );
}

function preparationDispatchPurchase(array $attributes = []): LaboratoryPurchase
{
    $user = User::factory()
        ->withRegularCustomer()
        ->withCompleteProfile()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);

    $purchase = LaboratoryPurchase::query()->create(array_merge([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-ai-dispatch-'.fake()->unique()->numerify('######'),
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
        'gda_status' => GdaOrderStatus::Confirmed,
        'gda_consecutivo' => 123456,
    ], $attributes));

    $transaction = Transaction::query()->create([
        'transaction_amount_cents' => 100000,
        'payment_method' => 'odessa',
        'reference_id' => 'odessa-confirmed-'.fake()->unique()->numerify('######'),
    ]);
    $transaction->laboratoryPurchases()->attach($purchase);

    return $purchase;
}

beforeEach(function () {
    Http::preventStrayRequests();

    $this->assertOfficialPreparationPromptsAvailable();

    config([
        'services.laboratory_preparation.deterministic_v3_enabled' => true,
        'services.laboratory_preparation.deterministic_v3_shadow_enabled' => false,
    ]);
});

function preparationDispatchItem(LaboratoryPurchase $purchase, array $attributes): LaboratoryPurchaseItem
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

function bindPreparationDispatchOpenAiFake(?array $content = null, bool $fail = false): object
{
    $fake = new class($content, $fail) extends OpenAiClient
    {
        public int $calls = 0;

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

            if ($this->fail) {
                throw new RuntimeException('Proveedor temporalmente no disponible');
            }

            return [
                'content' => $this->content ?? [
                    'summary' => 'Ayuno de 8 horas.',
                    'sections' => [
                        [
                            'key' => 'ayuno',
                            'title' => 'Ayuno',
                            'content' => 'Ayuno de 8 horas.',
                            'source_item_ids' => [LaboratoryPurchase::query()->latest('id')->first()->laboratoryPurchaseItems()->first()->id],
                        ],
                    ],
                    'special_instructions' => [],
                    'individual_instructions' => [],
                ],
                'model' => $model ?: 'gpt-4o-mini',
                'usage' => [
                    'prompt_tokens' => 80,
                    'completion_tokens' => 40,
                    'total_tokens' => 120,
                ],
                'raw' => [],
            ];
        }
    };

    app()->instance(OpenAiClient::class, $fake);

    return $fake;
}

function handlePreparationDispatchJob(GenerateLaboratoryPurchasePreparationSummaryJob $job): void
{
    $job->handle(
        app(LaboratoryPreparationSummaryService::class),
        app(LaboratoryPreparationSummaryNotificationService::class),
        app(LaboratoryPreparationEligibility::class),
    );
}

it('does not dispatch when v3 and shadow are off', function () {
    config([
        'services.laboratory_preparation.deterministic_v3_enabled' => false,
        'services.laboratory_preparation.deterministic_v3_shadow_enabled' => false,
    ]);
    Queue::fake();
    Notification::fake();
    $gda = Mockery::mock(CreateGDAQuotationAction::class);
    $gda->shouldReceive('__invoke')->once()->andReturn([
        'id' => 'GDA-DISPATCH-OFF',
        'infogda_consecutivo' => 654320,
    ]);
    app()->instance(CreateGDAQuotationAction::class, $gda);

    $purchase = fulfillPreparationDispatchPurchase(preparationDispatchFixture());

    expect($purchase->exists)->toBeTrue()
        ->and($purchase->laboratoryPurchaseItems()->count())->toBe(1);

    $execution = AiExecution::query()
        ->where('subject_type', $purchase->getMorphClass())
        ->where('subject_id', $purchase->id)
        ->where('feature', 'laboratory_preparation_summary')
        ->first();

    expect($execution)->toBeNull();

    Queue::assertNotPushed(GenerateLaboratoryPurchasePreparationSummaryJob::class);
});

it('dispatches v3 after commit for a confirmed GDA order with explicit payment evidence', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
    Queue::fake();
    Notification::fake();

    $gda = Mockery::mock(CreateGDAQuotationAction::class);
    $gda->shouldReceive('__invoke')->once()->andReturn([
        'id' => 'GDA-DISPATCH-CONFIRMED',
        'infogda_consecutivo' => 654321,
    ]);
    app()->instance(CreateGDAQuotationAction::class, $gda);

    $purchase = fulfillPreparationDispatchPurchase(preparationDispatchFixture());
    $expectedHash = app(LaboratoryPreparationSummaryService::class)->expectedSourceHash($purchase);

    Queue::assertPushed(GenerateLaboratoryPurchasePreparationSummaryJob::class, function (GenerateLaboratoryPurchasePreparationSummaryJob $job) use ($purchase, $expectedHash): bool {
        return $job->laboratoryPurchaseId === $purchase->id
            && $job->mode === GenerateLaboratoryPurchasePreparationSummaryJob::MODE_GENERATE
            && $job->aiExecutionId === null
            && $job->sourceHash === $expectedHash
            && $job->afterCommit === true;
    });
    expect(AiExecution::query()->where('feature', 'laboratory_preparation_summary')->exists())->toBeFalse();
});

it('dispatches shadow-only evaluation without creating legacy executions', function () {
    config([
        'services.laboratory_preparation.deterministic_v3_enabled' => false,
        'services.laboratory_preparation.deterministic_v3_shadow_enabled' => true,
    ]);
    Queue::fake();
    Notification::fake();

    $gda = Mockery::mock(CreateGDAQuotationAction::class);
    $gda->shouldReceive('__invoke')->once()->andReturn([
        'id' => 'GDA-DISPATCH-SHADOW',
        'infogda_consecutivo' => 654322,
    ]);
    app()->instance(CreateGDAQuotationAction::class, $gda);

    $purchase = fulfillPreparationDispatchPurchase(preparationDispatchFixture());

    Queue::assertPushed(GenerateLaboratoryPurchasePreparationSummaryJob::class, function (GenerateLaboratoryPurchasePreparationSummaryJob $job) use ($purchase): bool {
        return $job->laboratoryPurchaseId === $purchase->id
            && $job->mode === GenerateLaboratoryPurchasePreparationSummaryJob::MODE_SHADOW_ONLY
            && $job->aiExecutionId === null
            && $job->afterCommit === true;
    });
    expect(AiExecution::query()->count())->toBe(0)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->exists())->toBeFalse();
});

it('does not call OpenAI during the purchase fulfillment request', function () {
    Queue::fake();
    Notification::fake();
    $fake = bindPreparationDispatchOpenAiFake();
    $gda = Mockery::mock(CreateGDAQuotationAction::class);
    $gda->shouldReceive('__invoke')->once()->andReturn([
        'id' => 'GDA-DISPATCH-NO-OPENAI',
        'infogda_consecutivo' => 654323,
    ]);
    app()->instance(CreateGDAQuotationAction::class, $gda);

    fulfillPreparationDispatchPurchase(preparationDispatchFixture());

    expect($fake->calls)->toBe(0);
});

it('rejects fulfillment and IA eligibility when the GDA consecutivo is missing', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
    Queue::fake();
    Notification::fake();
    $gda = Mockery::mock(CreateGDAQuotationAction::class);
    $gda->shouldReceive('__invoke')->once()->andReturn(['id' => 'GDA-DISPATCH-NO-CONSECUTIVO']);
    app()->instance(CreateGDAQuotationAction::class, $gda);

    expect(fn () => fulfillPreparationDispatchPurchase(preparationDispatchFixture()))
        ->toThrow(GdaOrderResultUncertainException::class);

    $purchase = preparationDispatchPurchase(['gda_consecutivo' => null]);
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $eligibility = app(LaboratoryPreparationEligibility::class)->evaluate($purchase->fresh());

    expect($purchase->gda_consecutivo)->toBeNull()
        ->and($eligibility->eligible)->toBeFalse()
        ->and($eligibility->reason)->toBe('gda_consecutive_missing')
        ->and(AiExecution::query()->where('feature', 'laboratory_preparation_summary')->count())->toBe(0);

    Queue::assertNotPushed(GenerateLaboratoryPurchasePreparationSummaryJob::class);
});

it('shadow-only dispatch evaluates deterministically without legacy or visible output', function () {
    config([
        'services.laboratory_preparation.deterministic_v3_enabled' => false,
        'services.laboratory_preparation.deterministic_v3_shadow_enabled' => true,
    ]);
    Notification::fake();
    Log::spy();

    $purchase = preparationDispatchPurchase(['gda_order_id' => 'ORD-SHADOW-AUTO']);
    $item = preparationDispatchItem($purchase, [
        'name' => 'Glucosa',
        'gda_id' => 'GLU-SHADOW',
        'indications' => 'Ayuno de 8 horas.',
    ]);
    $fake = bindPreparationDispatchOpenAiFake();

    handlePreparationDispatchJob(new GenerateLaboratoryPurchasePreparationSummaryJob(
        $purchase->id,
        null,
        app(LaboratoryPreparationSummaryService::class)->deterministicV3SourceHash($purchase),
        GenerateLaboratoryPurchasePreparationSummaryJob::MODE_SHADOW_ONLY,
    ));

    $summary = LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->first();

    expect($summary)->toBeNull()
        ->and($fake->calls)->toBe(0)
        ->and(AiExecution::query()->where('feature', 'laboratory_preparation_redactor')->count())->toBe(0)
        ->and(\App\Models\InAppNotification::query()->where('type', 'laboratory_preparation_summary_ready')->count())->toBe(0);

    Log::shouldHaveReceived('info')
        ->with('laboratory_preparation_v3_shadow_completed', Mockery::on(
            fn (array $context): bool => $context['purchase_id'] === $purchase->id
                && $context['decision_status'] === LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED
                && in_array('R01', $context['rules_applied'], true)
                && array_key_exists('duration_ms', $context)
        ))
        ->once();
});

it('does not run shadow twice when the same legacy job is handled again', function () {
    config([
        'services.laboratory_preparation.deterministic_v3_enabled' => false,
        'services.laboratory_preparation.deterministic_v3_shadow_enabled' => true,
    ]);
    Notification::fake();
    Log::spy();

    $purchase = preparationDispatchPurchase(['gda_order_id' => 'ORD-SHADOW-DEDUP']);
    $item = preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $fake = bindPreparationDispatchOpenAiFake();
    $job = new GenerateLaboratoryPurchasePreparationSummaryJob(
        $purchase->id,
        null,
        app(LaboratoryPreparationSummaryService::class)->deterministicV3SourceHash($purchase),
        GenerateLaboratoryPurchasePreparationSummaryJob::MODE_SHADOW_ONLY,
    );

    handlePreparationDispatchJob($job);
    handlePreparationDispatchJob($job);

    expect($fake->calls)->toBe(0)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->count())->toBe(0)
        ->and(\App\Models\InAppNotification::query()->where('type', 'laboratory_preparation_summary_ready')->count())->toBe(0);

    Log::shouldHaveReceived('info')
        ->with('laboratory_preparation_v3_shadow_completed', Mockery::type('array'))
        ->twice();
});

it('gives active deterministic v3 precedence over shadow when both flags are enabled', function () {
    config([
        'services.laboratory_preparation.deterministic_v3_enabled' => true,
        'services.laboratory_preparation.deterministic_v3_shadow_enabled' => true,
    ]);
    Notification::fake();
    Log::spy();

    $purchase = preparationDispatchPurchase(['gda_order_id' => 'ORD-V3-SHADOW-PRECEDENCE']);
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $source = app(LaboratoryPreparationSource::class);
    $hash = $source->deterministicHash($purchase->fresh('laboratoryPurchaseItems'));
    $fake = bindPreparationDispatchOpenAiFake([
        'title' => LaboratoryPreparationRedactionValidator::TITLE,
        'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
        'patient_text' => '• Acude con ayuno de 8 horas.',
        'bullet_count' => 1,
    ]);

    handlePreparationDispatchJob(new GenerateLaboratoryPurchasePreparationSummaryJob($purchase->id, null, $hash));

    $summary = LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->first();

    expect($summary?->decision_status)->toBe(LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED)
        ->and($summary?->summary_text)->toBe('• Acude con ayuno de 8 horas.')
        ->and($fake->calls)->toBe(1)
        ->and(app(LaboratoryPreparationSummaryService::class)->deterministicV3ShadowEffectiveEnabled())->toBeFalse();

    Log::shouldNotHaveReceived('info', ['laboratory_preparation_v3_shadow_completed', Mockery::any()]);
});

it('does not break purchase fulfillment when queueing the preparation summary fails', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => false]);
    Queue::fake();
    Notification::fake();

    $mock = Mockery::mock(LaboratoryPreparationSummaryService::class);
    $mock->shouldReceive('deterministicV3Enabled')->once()->andReturn(false);
    $mock->shouldReceive('deterministicV3ShadowEnabled')->once()->andReturn(false);
    app()->instance(LaboratoryPreparationSummaryService::class, $mock);
    $gda = Mockery::mock(CreateGDAQuotationAction::class);
    $gda->shouldReceive('__invoke')->once()->andReturn([
        'id' => 'GDA-DISPATCH-QUEUE-FAILURE',
        'infogda_consecutivo' => 654324,
    ]);
    app()->instance(CreateGDAQuotationAction::class, $gda);

    $purchase = fulfillPreparationDispatchPurchase(preparationDispatchFixture());

    expect($purchase->exists)->toBeTrue()
        ->and($purchase->laboratoryPurchaseItems()->count())->toBe(1);

    Queue::assertNotPushed(GenerateLaboratoryPurchasePreparationSummaryJob::class);
});

it('preserves the purchase and falls back when v3 redaction fails', function () {
    $fake = bindPreparationDispatchOpenAiFake(fail: true);
    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $originalGdaOrderId = $purchase->gda_order_id;
    $job = new GenerateLaboratoryPurchasePreparationSummaryJob(
        $purchase->id,
        null,
        app(LaboratoryPreparationSummaryService::class)->expectedSourceHash($purchase),
    );

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300, 900])
        ->and($job->afterCommit)->toBeTrue();

    expect(fn () => handlePreparationDispatchJob($job))
        ->not->toThrow(RuntimeException::class);

    expect(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->first()?->decision_status)
        ->toBe(LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL)
        ->and($fake->calls)->toBe(1)
        ->and($purchase->fresh()->gda_order_id)->toBe($originalGdaOrderId)
        ->and(AiExecution::query()->where('feature', 'laboratory_preparation_redactor')->where('status', AiExecution::STATUS_FAILED)->exists())
        ->toBeTrue();
});

it('does not throw when v3 preserves original instructions for incomplete source', function () {
    $purchase = preparationDispatchPurchase();
    $item = preparationDispatchItem($purchase, [
        'indications' => 'Ayuno de 8 horas.',
    ]);
    preparationDispatchItem($purchase, [
        'name' => 'Paquete orina',
        'indications' => null,
        'feature_list' => ['EXAMEN GENERAL DE ORINA'],
    ]);
    bindPreparationDispatchOpenAiFake([
        'title' => LaboratoryPreparationRedactionValidator::TITLE,
        'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
        'patient_text' => '• Acude con ayuno de 8 horas.',
        'bullet_count' => 1,
    ]);

    $job = new GenerateLaboratoryPurchasePreparationSummaryJob(
        $purchase->id,
        null,
        app(LaboratoryPreparationSummaryService::class)->expectedSourceHash($purchase),
    );

    expect(fn () => handlePreparationDispatchJob($job))
        ->not->toThrow(LaboratoryPreparationFidelityValidationException::class);

    expect(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->exists())->toBeTrue();
});

it('records an original fallback for transient redactor errors', function () {
    $fake = bindPreparationDispatchOpenAiFake(fail: true);
    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $job = new GenerateLaboratoryPurchasePreparationSummaryJob(
        $purchase->id,
        null,
        app(LaboratoryPreparationSummaryService::class)->expectedSourceHash($purchase),
    );

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300, 900]);

    expect(fn () => handlePreparationDispatchJob($job))
        ->not->toThrow(RuntimeException::class);

    expect(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->first()?->decision_status)
        ->toBe(LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL)
        ->and($fake->calls)->toBe(1);
});

it('the preparation job reuses an unchanged source hash without duplicate provider calls', function () {
    $purchase = preparationDispatchPurchase();
    $item = preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $fake = bindPreparationDispatchOpenAiFake([
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

    $job = new GenerateLaboratoryPurchasePreparationSummaryJob(
        $purchase->id,
        null,
        app(LaboratoryPreparationSummaryService::class)->expectedSourceHash($purchase),
    );
    handlePreparationDispatchJob($job);
    handlePreparationDispatchJob($job);

    expect($fake->calls)->toBe(1)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->count())->toBe(1)
        ->and(AiExecution::query()->where('feature', 'laboratory_preparation_summary')->count())->toBe(0);
});

it('skips stale deterministic v3 jobs when indications changed before processing', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);

    $purchase = preparationDispatchPurchase();
    $item = preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $source = app(LaboratoryPreparationSource::class);
    $hash = $source->deterministicHash($purchase->fresh('laboratoryPurchaseItems'));
    $fake = bindPreparationDispatchOpenAiFake([
        'title' => LaboratoryPreparationRedactionValidator::TITLE,
        'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
        'patient_text' => '• Acude con ayuno de 8 horas.',
        'bullet_count' => 1,
    ]);

    $item->update(['indications' => 'Ayuno de 12 horas.']);

    handlePreparationDispatchJob(new GenerateLaboratoryPurchasePreparationSummaryJob($purchase->id, null, $hash));

    expect($fake->calls)->toBe(0)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->count())->toBe(0)
        ->and(AiExecution::query()->count())->toBe(0);
});

it('skips queued deterministic v3 jobs after rollback to flag off', function () {
    config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);

    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $source = app(LaboratoryPreparationSource::class);
    $hash = $source->deterministicHash($purchase->fresh('laboratoryPurchaseItems'));
    $fake = bindPreparationDispatchOpenAiFake([
        'title' => LaboratoryPreparationRedactionValidator::TITLE,
        'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
        'patient_text' => '• Acude con ayuno de 8 horas.',
        'bullet_count' => 1,
    ]);

    config(['services.laboratory_preparation.deterministic_v3_enabled' => false]);

    handlePreparationDispatchJob(new GenerateLaboratoryPurchasePreparationSummaryJob($purchase->id, null, $hash));

    expect($fake->calls)->toBe(0)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->count())->toBe(0)
        ->and(AiExecution::query()->count())->toBe(0);
});

it('a missing purchase does not create a generation execution', function () {
    $job = new GenerateLaboratoryPurchasePreparationSummaryJob(999999);

    handlePreparationDispatchJob($job);

    expect(AiExecution::query()->where('feature', 'laboratory_preparation_summary')->count())->toBe(0);
});

it('allows only explicit positive payment evidence for supported payment contracts', function () {
    $eligibility = app(LaboratoryPreparationEligibility::class);
    $cases = [
        ['payment_method' => 'odessa', 'reference_id' => 'ODS-123'],
        ['payment_method' => 'paypal', 'payment_status' => 'captured', 'gateway_status' => 'COMPLETED', 'gateway_transaction_id' => 'CAP-123'],
        ['payment_method' => 'efevoopay', 'gateway_status' => 'completed', 'gateway_transaction_id' => 'EFE-123'],
        ['payment_method' => 'coupon_balance', 'transaction_amount_cents' => 0, 'gateway' => 'coupon_balance', 'gateway_status' => 'completed'],
    ];

    foreach ($cases as $case) {
        $purchase = preparationDispatchPurchase();
        preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
        $transaction = $purchase->transactions()->first();
        $transaction->update(array_merge([
            'payment_status' => null,
            'gateway_status' => null,
            'gateway' => null,
            'gateway_transaction_id' => null,
            'provider_transaction_id' => null,
            'details' => null,
        ], $case));

        expect($eligibility->evaluate($purchase->fresh())->eligible)->toBeTrue();
    }
});

it('rejects pending failed refunded and unresolved payment evidence', function () {
    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $transaction = $purchase->transactions()->first();
    $eligibility = app(LaboratoryPreparationEligibility::class);

    foreach ([
        ['payment_status' => 'pending', 'reason' => 'payment_pending'],
        ['payment_status' => 'failed', 'reason' => 'payment_failed'],
        ['payment_status' => 'refunded', 'reason' => 'payment_refunded'],
        ['payment_method' => 'historical', 'reference_id' => 'OLD-REF', 'reason' => 'payment_evidence_unresolved'],
    ] as $case) {
        $expectedReason = $case['reason'];
        unset($case['reason']);
        $transaction->update(array_merge([
            'payment_method' => 'odessa',
            'payment_status' => null,
            'gateway_status' => null,
            'reference_id' => 'ODS-123',
        ], $case));

        preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
        expect($eligibility->evaluate($purchase->fresh())->reason)->toBe($expectedReason);
        $purchase->laboratoryPurchaseItems()->delete();
    }
});

it('requires a valid GDA folio and positive consecutivo', function () {
    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $eligibility = app(LaboratoryPreparationEligibility::class);

    foreach ([
        ['gda_order_id' => '', 'gda_consecutivo' => 123456, 'reason' => 'gda_folio_invalid'],
        ['gda_order_id' => '0', 'gda_consecutivo' => 123456, 'reason' => 'gda_folio_invalid'],
        ['gda_order_id' => 'GDA-1', 'gda_consecutivo' => null, 'reason' => 'gda_consecutive_missing'],
        ['gda_order_id' => 'GDA-1', 'gda_consecutivo' => 0, 'reason' => 'gda_consecutive_missing'],
    ] as $case) {
        $purchase->update([
            'gda_order_id' => $case['gda_order_id'],
            'gda_consecutivo' => $case['gda_consecutivo'],
        ]);

        expect($eligibility->evaluate($purchase->fresh())->reason)->toBe($case['reason']);
    }
});

it('rejects preparation eligibility when explicit GDA status is absent', function () {
    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $purchase->update(['gda_status' => null]);

    expect(app(LaboratoryPreparationEligibility::class)->evaluate($purchase)->reason)
        ->toBe('gda_not_confirmed');
});

it('blocks non-confirmed GDA states before redaction or persistence', function () {
    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $originalGdaOrderId = $purchase->gda_order_id;
    $transaction = $purchase->transactions()->first();
    $originalTransaction = $transaction->only(['payment_status', 'gateway_status', 'reference_id', 'gateway_transaction_id']);
    $fake = bindPreparationDispatchOpenAiFake();

    foreach ([GdaOrderStatus::Pending, GdaOrderStatus::Failed, GdaOrderStatus::Uncertain] as $status) {
        $purchase->update(['gda_status' => $status]);
        $job = new GenerateLaboratoryPurchasePreparationSummaryJob(
            $purchase->id,
            null,
            app(LaboratoryPreparationSummaryService::class)->expectedSourceHash($purchase),
        );

        handlePreparationDispatchJob($job);

        expect(app(LaboratoryPreparationEligibility::class)->evaluate($purchase->fresh())->reason)->toBe('gda_not_confirmed')
            ->and($fake->calls)->toBe(0)
            ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->exists())->toBeFalse()
            ->and($purchase->fresh()->gda_status)->toBe($status)
            ->and($transaction->fresh()->only(array_keys($originalTransaction)))->toBe($originalTransaction);
    }

    $purchase->update(['gda_status' => null]);
    expect(app(LaboratoryPreparationEligibility::class)->evaluate($purchase->fresh())->reason)->toBe('gda_not_confirmed')
        ->and($purchase->fresh()->gda_order_id)->toBe($originalGdaOrderId)
        ->and($transaction->fresh()->only(array_keys($originalTransaction)))->toBe($originalTransaction);
});

it('blocks confirmed GDA with unconfirmed payment evidence', function () {
    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $transaction = $purchase->transactions()->first();
    $transaction->update(['payment_method' => 'paypal', 'payment_status' => 'pending', 'gateway_status' => 'PENDING']);

    expect(app(LaboratoryPreparationEligibility::class)->evaluate($purchase->fresh())->reason)->toBe('payment_pending');
});

it('revalidates payment and GDA eligibility when a generation job starts', function () {
    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $sourceHash = app(LaboratoryPreparationSummaryService::class)->expectedSourceHash($purchase);
    $fake = bindPreparationDispatchOpenAiFake();

    $purchase->update(['gda_status' => GdaOrderStatus::Uncertain]);

    handlePreparationDispatchJob(new GenerateLaboratoryPurchasePreparationSummaryJob($purchase->id, null, $sourceHash));

    expect($fake->calls)->toBe(0)
        ->and($purchase->fresh()->gda_status)->toBe(GdaOrderStatus::Uncertain)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->exists())->toBeFalse();
});
