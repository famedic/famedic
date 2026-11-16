<?php

// RC2 B03/B06 (D6): synthetic reversible price fixtures command + contractual behaviour. LOCAL, MySQL 8.
use App\Console\Commands\Rc2PriceFixturesCommand;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

function b06Run(array $options): array
{
    $code = Artisan::call('akubica:rc2-price-fixtures', $options);

    return [$code, json_decode(trim(Artisan::output()), true)];
}

function b06Headers(array $issued): array
{
    return [
        'Authorization' => 'Bearer '.$issued['token']->plainTextToken,
        'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken,
        'Idempotency-Key' => (string) Str::uuid(),
    ];
}

beforeEach(fn () => SyntheticConsumerRegistryFixture::reset());

test('D6 dry-run is the default and writes nothing', function () {
    [$code, $out] = b06Run([]);

    expect($code)->toBe(0)->and($out['mode'])->toBe('dry-run')
        ->and($out['plan']['B03']['exists'])->toBeFalse()
        ->and(LaboratoryTest::query()->where('gda_id', 'like', Rc2PriceFixturesCommand::PREFIX.'%')->count())->toBe(0);
});

test('D6 writes require the exact confirmation and an allowed environment', function () {
    [$code, $out] = b06Run(['--apply' => true]);
    expect($code)->toBe(1)->and($out['error_code'])->toBe('FIXTURE_CONFIRMATION_MISMATCH');

    [$code, $out] = b06Run(['--apply' => true, '--cleanup' => true, '--confirm' => Rc2PriceFixturesCommand::CONFIRM]);
    expect($code)->toBe(1)->and($out['error_code'])->toBe('FIXTURE_INVALID_OPTION_COMBINATION');

    $previous = app()->environment();
    app()['env'] = 'production';
    try {
        [$code, $out] = b06Run(['--apply' => true, '--confirm' => Rc2PriceFixturesCommand::CONFIRM]);
    } finally {
        app()['env'] = $previous;
    }
    expect($code)->toBe(1)->and($out['error_code'])->toBe('FIXTURE_ENVIRONMENT_NOT_ALLOWED')
        ->and(LaboratoryTest::query()->where('gda_id', 'like', Rc2PriceFixturesCommand::PREFIX.'%')->count())->toBe(0);
});

test('B03 fixture: list and detail are consistent with public_price_unavailable and null savings', function () {
    [, $out] = b06Run(['--apply' => true, '--confirm' => Rc2PriceFixturesCommand::CONFIRM]);
    $id = $out['fixtures']['B03']['id'];

    $detail = $this->getJson("/api/v1/catalog/laboratory-tests/{$id}")->assertOk()->json('data');
    expect($detail['pricing']['comparison_status'])->toBe('public_price_unavailable')
        ->and($detail['pricing']['savings_cents'])->toBeNull()
        ->and($detail['pricing']['savings_percentage'])->toBeNull();

    $list = collect($this->getJson('/api/v1/catalog/laboratory-tests?brand=olab&per_page=50&search='.urlencode('RC2 B03'))
        ->assertOk()->json('data.laboratory_tests'));
    $row = $list->firstWhere('id', $id);
    expect($row)->not->toBeNull()
        ->and($row['pricing']['comparison_status'])->toBe('public_price_unavailable')
        ->and($row['pricing']['savings_cents'])->toBeNull()
        ->and($row['pricing']['savings_percentage'])->toBeNull();
});

test('B06 fixture: the server uses the current FAMEDIC price after a mutation, never a client price', function () {
    [, $out] = b06Run(['--apply' => true, '--confirm' => Rc2PriceFixturesCommand::CONFIRM]);
    $id = $out['fixtures']['B06']['id'];
    $native = SyntheticConsumerRegistryFixture::issue(User::factory()->withRegularCustomer()->create(), 'native');

    $before = $this->getJson("/api/v1/catalog/laboratory-tests/{$id}")->assertOk()->json('data.price_cents');
    [, $mutation] = b06Run(['--mutate' => true, '--confirm' => Rc2PriceFixturesCommand::CONFIRM]);
    expect($mutation['B06']['famedic_price_cents_before'])->toBe($before)
        ->and($mutation['B06']['famedic_price_cents_after'])->not->toBe($before);

    $this->postJson('/api/v1/cart/items', ['brand' => 'olab', 'laboratory_test_id' => $id, 'quantity' => 1, 'price_cents' => $before], b06Headers($native))
        ->assertStatus(422);

    $this->postJson('/api/v1/cart/items', ['brand' => 'olab', 'laboratory_test_id' => $id, 'quantity' => 1], b06Headers($native))
        ->assertStatus(201)
        ->assertJsonPath('data.item.price_cents', $mutation['B06']['famedic_price_cents_after']);

    $cart = $this->getJson('/api/v1/cart?brand=olab', b06Headers($native))->assertOk()->json('data');
    expect($cart['total_cents'])->toBe($mutation['B06']['famedic_price_cents_after']);
});

test('D6 cleanup removes only marked fixtures and their cart lines', function () {
    $unrelated = createOlabTest();
    [, $out] = b06Run(['--apply' => true, '--confirm' => Rc2PriceFixturesCommand::CONFIRM]);
    $user = User::factory()->withRegularCustomer()->create();
    LaboratoryCartItem::factory()->create(['customer_id' => $user->customer->id, 'laboratory_test_id' => $out['fixtures']['B06']['id']]);
    LaboratoryCartItem::factory()->create(['customer_id' => $user->customer->id, 'laboratory_test_id' => $unrelated->id]);

    [$code, $clean] = b06Run(['--cleanup' => true, '--confirm' => Rc2PriceFixturesCommand::CONFIRM]);

    expect($code)->toBe(0)
        ->and($clean['deleted']['laboratory_tests'])->toBe(2)
        ->and($clean['deleted']['laboratory_cart_items'])->toBe(1)
        ->and(LaboratoryTest::query()->withTrashed()->where('gda_id', 'like', Rc2PriceFixturesCommand::PREFIX.'%')->count())->toBe(0)
        ->and(LaboratoryTest::query()->whereKey($unrelated->id)->exists())->toBeTrue()
        ->and(LaboratoryCartItem::query()->where('laboratory_test_id', $unrelated->id)->count())->toBe(1);

    [$code] = b06Run(['--apply' => true, '--confirm' => Rc2PriceFixturesCommand::CONFIRM]);
    expect($code)->toBe(0); // re-applicable after cleanup
});
