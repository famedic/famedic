<?php

use App\Actions\Laboratories\GenerateLaboratoryPurchaseConfirmationPdf;
use App\Actions\Laboratories\ResolveLaboratoryPurchasePdfPath;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\User;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hashTestPurchase(): LaboratoryPurchase
{
    $user = User::factory()
        ->withRegularCustomer()
        ->withCompleteProfile()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);

    $purchase = LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'hash-test-001',
        'gda_consecutivo' => 700001,
        'name' => 'Paciente',
        'paternal_lastname' => 'Hash',
        'maternal_lastname' => 'Seguro',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::FEMALE->value,
        'street' => 'Calle Test',
        'number' => '123',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'additional_references' => 'Prueba',
        'total_cents' => 100000,
    ]);

    LaboratoryPurchaseItem::query()->create([
        'laboratory_purchase_id' => $purchase->id,
        'name' => 'Biometria hematica',
        'gda_id' => 'BH-001',
        'indications' => 'Ayuno de 8 horas.',
        'feature_list' => ['Hemoglobina'],
        'price_cents' => 50000,
    ]);

    return $purchase->fresh('laboratoryPurchaseItems');
}

function hashForPurchaseWithPreparation(LaboratoryPurchase $purchase, array $preparation): string
{
    app()->instance(
        LaboratoryPreparationPresenter::class,
        Mockery::mock(LaboratoryPreparationPresenter::class)
            ->shouldReceive('present')
            ->andReturn($preparation)
            ->getMock(),
    );

    $resolver = new ResolveLaboratoryPurchasePdfPath(new GenerateLaboratoryPurchaseConfirmationPdf);
    $method = new ReflectionMethod($resolver, 'calculateContentHash');
    $method->setAccessible(true);

    return $method->invoke($resolver, $purchase->fresh('laboratoryPurchaseItems'));
}

it('keeps purchase pdf hash stable when only preparation metadata changes', function () {
    $purchase = hashTestPurchase();

    $visiblePreparation = [
        'ai_status' => LaboratoryPreparationPresenter::STATUS_AI_READY,
        'summary' => [
            'sections' => [
                ['key' => 'ayuno', 'title' => 'Ayuno', 'content' => 'Ayuno de 8 horas.'],
            ],
            'special_instructions' => [],
            'individual_instructions' => [],
        ],
        'studies' => [
            ['id' => $purchase->laboratoryPurchaseItems->first()->id, 'name' => 'Biometria hematica', 'instructions' => 'Ayuno de 8 horas.'],
        ],
    ];

    $firstHash = hashForPurchaseWithPreparation($purchase, array_merge($visiblePreparation, [
        'has_ai_summary' => true,
        'source' => 'ai',
        'source_hash' => 'source-a',
        'generated_at' => now()->subMinute()->toIso8601String(),
        'prompt_version' => 7,
    ]));

    $secondHash = hashForPurchaseWithPreparation($purchase, array_merge($visiblePreparation, [
        'has_ai_summary' => true,
        'source' => 'ai',
        'source_hash' => 'source-b',
        'generated_at' => now()->toIso8601String(),
        'prompt_version' => 8,
    ]));

    expect($secondHash)->toBe($firstHash);
});

it('rotates purchase pdf hash when visible preparation content changes', function () {
    $purchase = hashTestPurchase();

    $base = [
        'ai_status' => LaboratoryPreparationPresenter::STATUS_AI_READY,
        'summary' => [
            'sections' => [
                ['key' => 'ayuno', 'title' => 'Ayuno', 'content' => 'Ayuno de 8 horas.'],
            ],
            'special_instructions' => [],
            'individual_instructions' => [],
        ],
        'studies' => [],
    ];

    $changed = $base;
    $changed['summary']['sections'][0]['content'] = 'Ayuno de 10 horas.';

    expect(hashForPurchaseWithPreparation($purchase, $changed))
        ->not->toBe(hashForPurchaseWithPreparation($purchase, $base));
});
