<?php

use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

function ordersManifestForTest(): array
{
    $primary = Customer::factory()->withRegularAccount()->create(['user_id' => User::factory()->create()->id]);
    $foreign = Customer::factory()->withRegularAccount()->create(['user_id' => User::factory()->create()->id]);
    AkubicaUatFixtureManifest::query()->create([
        'namespace' => 'akubica-uat-batch2-a4-v1', 'fixture_version' => 1, 'status' => 'active',
        'expires_at' => now()->addDays(7), 'metadata' => ['ids' => ['customers' => ['primary' => $primary->id, 'foreign' => $foreign->id]]],
    ]);
    return [$primary, $foreign];
}

test('R01 order fixtures are dedicated, idempotent and ownership safe', function () {
    [$primary, $foreign] = ordersManifestForTest();
    $args = ['--apply' => true, '--namespace' => 'akubica-uat-batch2-a4-v1', '--confirm' => 'akubica-uat-batch2-a4-v1'];

    $this->artisan('akubica:uat-fixtures-orders', $args)->assertSuccessful();
    $this->artisan('akubica:uat-fixtures-orders', $args)->assertSuccessful();

    $orders = LaboratoryPurchase::query()->whereIn('customer_id', [$primary->id, $foreign->id])->where('street', 'akubica-uat-batch2-a4-v1:rc2-r01-orders')->get();
    expect($orders)->toHaveCount(2)
        ->and($orders->pluck('customer_id')->sort()->values()->all())->toBe(collect([$primary->id, $foreign->id])->sort()->values()->all());

    $this->artisan('akubica:uat-fixtures-orders', ['--dry-run' => true, '--namespace' => 'akubica-uat-v1'])->assertFailed();
    $this->artisan('akubica:uat-fixtures-orders', ['--reset' => true, '--namespace' => 'akubica-uat-batch2-a4-v1', '--confirm' => 'akubica-uat-batch2-a4-v1'])->assertSuccessful();
    expect(LaboratoryPurchase::query()->where('street', 'akubica-uat-batch2-a4-v1:rc2-r01-orders')->count())->toBe(0);
});

test('R01 reads own order and soft-denies a foreign order', function () {
    [$primary, $foreign] = ordersManifestForTest();
    $this->artisan('akubica:uat-fixtures-orders', ['--apply' => true, '--namespace' => 'akubica-uat-batch2-a4-v1', '--confirm' => 'akubica-uat-batch2-a4-v1'])->assertSuccessful();
    $own = LaboratoryPurchase::query()->where('gda_order_id', strtoupper('akubica-uat-batch2-a4-v1').'-R01-OWN')->firstOrFail();
    $other = LaboratoryPurchase::query()->where('gda_order_id', strtoupper('akubica-uat-batch2-a4-v1').'-R01-FOREIGN')->firstOrFail();
    $issued = SyntheticConsumerRegistryFixture::issue($primary->user, 'native', ['api:operation:api:v1:orders:index', 'api:operation:api:v1:orders:status']);
    $headers = ['Authorization' => 'Bearer '.$issued['token']->plainTextToken] + SyntheticConsumerRegistryFixture::headers($issued['token']->plainTextToken);

    $ids = collect($this->getJson('/api/v1/orders', $headers)->assertOk()->json('data.orders'))->pluck('id')->all();
    expect($ids)->toContain($own->id)->not->toContain($other->id);
    expect($this->getJson('/api/v1/orders/'.$other->id.'/status', $headers)->status())->toBeIn([403, 404]);
});
