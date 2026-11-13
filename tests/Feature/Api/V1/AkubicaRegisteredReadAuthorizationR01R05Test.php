<?php

use App\Models\User;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

test('registered Leo can read owned orders', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $issued = SyntheticConsumerRegistryFixture::issue($user, 'leo', [
        'api:operation:api:v1:orders:index',
    ]);

    $this->getJson('/api/v1/orders', authHeaders($issued['token']->plainTextToken))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.orders', [])
        ->assertJsonPath('data.pagination.total', 0);
});

test('registered Leo read scope does not authorize another read operation', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $issued = SyntheticConsumerRegistryFixture::issue($user, 'leo', [
        'api:operation:api:v1:user:family',
    ]);

    $this->getJson('/api/v1/orders/results', authHeaders($issued['token']->plainTextToken))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});

test('revoked registered Leo delegation cannot read family data', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $issued = SyntheticConsumerRegistryFixture::issue($user, 'leo', [
        'api:operation:api:v1:user:family',
    ]);
    $issued['delegation']->forceFill(['enabled' => false])->save();

    $this->getJson('/api/v1/user/family', authHeaders($issued['token']->plainTextToken))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});

test('Leo cannot use the read registration to write appointments or cancel orders', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $issued = SyntheticConsumerRegistryFixture::issue($user, 'leo', [
        'api:operation:api:v1:laboratory-appointments:index',
    ]);
    $headers = authHeaders($issued['token']->plainTextToken);

    $this->postJson('/api/v1/laboratory-appointments', [], $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');

    $this->putJson('/api/v1/orders/999999/cancel', [], $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});

test('Leo cannot reach results step-up or download routes through read access alone', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $issued = SyntheticConsumerRegistryFixture::issue($user, 'leo', [
        'api:operation:api:v1:orders:results',
    ]);
    $headers = authHeaders($issued['token']->plainTextToken);

    $this->postJson('/api/v1/orders/999999/results/step-up/request', [], $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');

    $this->getJson('/api/v1/orders/999999/results/download', $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});
