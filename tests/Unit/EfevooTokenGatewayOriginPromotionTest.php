<?php

use App\Models\Customer;
use App\Models\EfevooToken;
use App\Models\User;
use App\Support\EfevooPayGatewayMode;
use App\Support\EfevooTokenGatewayOriginPromotion;

function promotionUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

it('forbids live to test promotion', function () {
    expect(app(EfevooTokenGatewayOriginPromotion::class)->isTransitionAllowed(
        EfevooPayGatewayMode::LIVE,
        EfevooPayGatewayMode::TEST,
        ['source' => 'reconcile-tokenized-attempts']
    ))->toBeFalse();
});

it('allows mock to live only for approved sources', function () {
    $promotion = app(EfevooTokenGatewayOriginPromotion::class);

    expect($promotion->isTransitionAllowed(EfevooPayGatewayMode::MOCK, EfevooPayGatewayMode::LIVE, [
        'source' => 'tokencard',
    ]))->toBeTrue()
        ->and($promotion->isTransitionAllowed(EfevooPayGatewayMode::MOCK, EfevooPayGatewayMode::LIVE, [
            'source' => 'reconcile-tokenized-attempts',
        ]))->toBeTrue()
        ->and($promotion->isTransitionAllowed(EfevooPayGatewayMode::MOCK, EfevooPayGatewayMode::LIVE, [
            'source' => 'manual',
        ]))->toBeFalse();
});

it('promotion is idempotent when origin already matches', function () {
    $user = promotionUser();
    /** @var Customer $customer */
    $customer = $user->customer;

    $token = EfevooToken::create([
        'customer_id' => $customer->id,
        'card_token' => 'already_live',
        'card_last_four' => '4242',
        'card_expiration' => '1129',
        'environment' => 'production',
        'is_active' => true,
        'metadata' => ['gateway_origin' => EfevooPayGatewayMode::LIVE],
    ]);

    $promoted = app(EfevooTokenGatewayOriginPromotion::class)->promote($token, EfevooPayGatewayMode::LIVE, [
        'source' => 'reconcile-tokenized-attempts',
    ]);

    expect(data_get($promoted->metadata, 'gateway_origin'))->toBe('live')
        ->and(data_get($promoted->metadata, 'gateway_origin_promoted_at'))->toBeNull();
});
