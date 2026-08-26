<?php

use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
use App\Exceptions\EfevooPaymentException;
use App\Models\Customer;
use App\Models\EfevooToken;
use App\Models\User;
use App\Support\EfevooPayGatewayMode;
use App\Support\EfevooTokenGatewayOriginPolicy;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'mock',
        'efevoopay.environment' => 'test',
    ]);
});

function gatewayOriginUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

it('legacy null mock metadata is visible only in mock gateway', function () {
    $user = gatewayOriginUser();
    $token = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'test',
        'metadata' => ['mock' => true],
        'is_active' => true,
    ]);

    config(['efevoopay.gateway' => 'mock']);
    expect(EfevooTokenGatewayOriginPolicy::resolvedOrigin($token))->toBe(EfevooPayGatewayMode::MOCK)
        ->and(EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, EfevooPayGatewayMode::MOCK))->toBeTrue()
        ->and(EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, EfevooPayGatewayMode::LIVE))->toBeFalse();

    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    expect(EfevooToken::query()->compatibleWithCurrentGateway()->whereKey($token->id)->exists())->toBeFalse();
});

it('legacy null production token stays visible in live but not mock', function () {
    $user = gatewayOriginUser();
    $token = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'production',
        'metadata' => null,
        'is_active' => true,
    ]);

    expect(EfevooTokenGatewayOriginPolicy::resolvedOrigin($token))->toBe(EfevooPayGatewayMode::LIVE)
        ->and(EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, EfevooPayGatewayMode::LIVE))->toBeTrue()
        ->and(EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, EfevooPayGatewayMode::MOCK))->toBeFalse()
        ->and(EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, EfevooPayGatewayMode::TEST))->toBeFalse();
});

it('legacy null test token is visible only in test gateway until classified', function () {
    $user = gatewayOriginUser();
    $token = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'test',
        'metadata' => null,
        'is_active' => true,
    ]);

    expect(EfevooTokenGatewayOriginPolicy::isAmbiguousLegacy($token))->toBeTrue()
        ->and(EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, EfevooPayGatewayMode::TEST))->toBeTrue()
        ->and(EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, EfevooPayGatewayMode::MOCK))->toBeFalse()
        ->and(EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, EfevooPayGatewayMode::LIVE))->toBeFalse();
});

it('persisted gateway_origin overrides legacy inference', function () {
    $user = gatewayOriginUser();
    $token = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'production',
        'metadata' => ['gateway_origin' => EfevooPayGatewayMode::MOCK, 'mock' => false],
        'is_active' => true,
    ]);

    expect(EfevooTokenGatewayOriginPolicy::resolvedOrigin($token))->toBe(EfevooPayGatewayMode::MOCK);
});

it('rejects backend charge when mock token is submitted in live gateway', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);

    $user = gatewayOriginUser();
    /** @var Customer $customer */
    $customer = $user->customer;

    $mockToken = EfevooToken::factory()->create([
        'customer_id' => $customer->id,
        'environment' => 'test',
        'metadata' => ['mock' => true, 'gateway_origin' => EfevooPayGatewayMode::MOCK],
        'is_active' => true,
        'card_token' => 'mock_tok_backend_guard',
    ]);

    expect(fn () => app(ChargeEfevooPaymentMethodAction::class)->__invoke(
        $customer,
        1000,
        (string) $mockToken->id
    ))->toThrow(EfevooPaymentException::class, 'no está disponible');
});

it('classify command dry-run does not persist gateway_origin', function () {
    $user = gatewayOriginUser();
    $token = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'production',
        'metadata' => null,
    ]);

    $this->artisan('efevoo:tokens:classify-gateway-origin', [
        '--token-id' => $token->id,
    ])->assertSuccessful();

    expect(data_get($token->fresh()->metadata, 'gateway_origin'))->toBeNull();
});

it('classify command apply persists origin for production legacy token', function () {
    $user = gatewayOriginUser();
    $token = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'production',
        'metadata' => null,
    ]);

    $this->artisan('efevoo:tokens:classify-gateway-origin', [
        '--token-id' => $token->id,
        '--origin' => 'live',
        '--apply' => true,
    ])->assertSuccessful();

    expect(data_get($token->fresh()->metadata, 'gateway_origin'))->toBe('live');
});

it('simulated last4 2944 mock token is hidden from live index', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = gatewayOriginUser();

    EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'card_last_four' => '2944',
        'environment' => 'test',
        'metadata' => ['mock' => true],
        'is_active' => true,
    ]);

    EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'card_last_four' => '4242',
        'environment' => 'production',
        'metadata' => ['gateway_origin' => 'live'],
        'is_active' => true,
    ]);

    $visible = EfevooToken::query()
        ->where('customer_id', $user->customer->id)
        ->currentEnvironment()
        ->compatibleWithCurrentGateway()
        ->active()
        ->pluck('card_last_four')
        ->all();

    expect($visible)->toBe(['4242'])
        ->and($visible)->not->toContain('2944');
});
