<?php

use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Enums\LaboratoryBrand;
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
use App\Services\LaboratoryPreparation\LaboratoryPreparationSource;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

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

    return LaboratoryPurchase::query()->create(array_merge([
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
    ], $attributes));
}

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

it('dispatches preparation summary generation after a confirmed laboratory purchase is persisted', function () {
    Queue::fake();
    Notification::fake();

    $purchase = fulfillPreparationDispatchPurchase(preparationDispatchFixture());

    expect($purchase->exists)->toBeTrue()
        ->and($purchase->laboratoryPurchaseItems()->count())->toBe(1);

    $execution = AiExecution::query()
        ->where('subject_type', $purchase->getMorphClass())
        ->where('subject_id', $purchase->id)
        ->where('feature', 'laboratory_preparation_summary')
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe(AiExecution::STATUS_QUEUED);

    Queue::assertPushed(GenerateLaboratoryPurchasePreparationSummaryJob::class, function (GenerateLaboratoryPurchasePreparationSummaryJob $job) use ($purchase, $execution) {
        return $job->laboratoryPurchaseId === $purchase->id
            && $job->aiExecutionId === $execution->id
            && $job->afterCommit === true;
    });
});

it('does not call OpenAI during the purchase fulfillment request', function () {
    Queue::fake();
    Notification::fake();
    $fake = bindPreparationDispatchOpenAiFake();

    fulfillPreparationDispatchPurchase(preparationDispatchFixture());

    expect($fake->calls)->toBe(0);
});

it('does not break purchase fulfillment when queueing the preparation summary fails', function () {
    Queue::fake();
    Notification::fake();

    $mock = Mockery::mock(LaboratoryPreparationSummaryService::class);
    $mock->shouldReceive('queueExecution')->once()->andThrow(new RuntimeException('No prompt configured'));
    app()->instance(LaboratoryPreparationSummaryService::class, $mock);

    $purchase = fulfillPreparationDispatchPurchase(preparationDispatchFixture());

    expect($purchase->exists)->toBeTrue()
        ->and($purchase->laboratoryPurchaseItems()->count())->toBe(1);

    Queue::assertNotPushed(GenerateLaboratoryPurchasePreparationSummaryJob::class);
});

it('the preparation job supports bounded retries and records failed executions when provider fails', function () {
    bindPreparationDispatchOpenAiFake(fail: true);
    $purchase = preparationDispatchPurchase();
    preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $execution = app(LaboratoryPreparationSummaryService::class)->queueExecution($purchase);

    $job = new GenerateLaboratoryPurchasePreparationSummaryJob($purchase->id, $execution->id);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300, 900])
        ->and($job->afterCommit)->toBeTrue();

    expect(fn () => $job->handle(app(LaboratoryPreparationSummaryService::class)))
        ->toThrow(RuntimeException::class);

    expect($execution->refresh()->status)->toBe(AiExecution::STATUS_FAILED)
        ->and($execution->error)->toContain('Proveedor temporalmente no disponible');
});

it('the preparation job reuses an unchanged source hash without duplicate provider calls', function () {
    $purchase = preparationDispatchPurchase();
    $item = preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $execution = app(LaboratoryPreparationSummaryService::class)->queueExecution($purchase);
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

    $job = new GenerateLaboratoryPurchasePreparationSummaryJob($purchase->id, $execution->id);
    $job->handle(app(LaboratoryPreparationSummaryService::class));
    $job->handle(app(LaboratoryPreparationSummaryService::class));

    expect($fake->calls)->toBe(1)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->count())->toBe(1)
        ->and($execution->refresh()->status)->toBe(AiExecution::STATUS_SUCCEEDED);
});

it('a missing purchase does not create a generation execution', function () {
    $job = new GenerateLaboratoryPurchasePreparationSummaryJob(999999);

    $job->handle(app(LaboratoryPreparationSummaryService::class));

    expect(AiExecution::query()->where('feature', 'laboratory_preparation_summary')->count())->toBe(0);
});

it('a changed source hash can be queued for regeneration', function () {
    $purchase = preparationDispatchPurchase();
    $item = preparationDispatchItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    $first = app(LaboratoryPreparationSummaryService::class)->queueExecution($purchase);
    $source = app(LaboratoryPreparationSource::class);
    $firstHash = $source->hash($source->buildInput($purchase->fresh('laboratoryPurchaseItems')));

    $item->update(['indications' => 'Ayuno de 12 horas.']);

    $second = app(LaboratoryPreparationSummaryService::class)->queueExecution($purchase->fresh());
    $secondHash = $source->hash($source->buildInput($purchase->fresh('laboratoryPurchaseItems')));

    expect($firstHash)->not->toBe($secondHash)
        ->and($first?->id)->not->toBe($second?->id)
        ->and($second?->status)->toBe(AiExecution::STATUS_QUEUED);
});
