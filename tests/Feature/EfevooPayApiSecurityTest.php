<?php

use App\Contracts\EfevooPayGateway;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\EfevooPay\MockEfevooPayGateway;
use Spatie\Permission\Models\Permission;

function efevooApiCustomerUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);
}

function efevooApiAdminUser(array $permissions): User
{
    $user = User::factory()
        ->withCompleteProfile()
        ->withAdministrator()
        ->create([
            'documentation_accepted_at' => now(),
        ]);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate([
            'name' => $permission,
            'guard_name' => 'web',
        ]);
    }

    $user->administrator->givePermissionTo($permissions);

    return $user->fresh(['administrator.permissions']);
}

it('requires authentication for efevoopay api routes', function () {
    $this->getJson('/api/efevoopay/tokens')
        ->assertUnauthorized();
});

it('returns only the authenticated customers sanitized tokens', function () {
    $owner = efevooApiCustomerUser();
    $other = efevooApiCustomerUser();

    EfevooToken::factory()->create([
        'customer_id' => $owner->customer->id,
        'client_token' => 'secret-client-token',
        'card_token' => 'secret-card-token',
        'card_last_four' => '4242',
        'card_brand' => 'Visa',
        'card_expiration' => '1229',
        'card_holder' => 'SECRET HOLDER',
    ]);

    EfevooToken::factory()->create([
        'customer_id' => $other->customer->id,
        'card_last_four' => '9999',
    ]);

    $response = $this->actingAs($owner)
        ->getJson('/api/efevoopay/tokens')
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('tokens.0.card_last_four', '4242');

    $json = $response->getContent();

    expect($json)
        ->not->toContain('secret-client-token')
        ->not->toContain('secret-card-token')
        ->not->toContain('SECRET HOLDER')
        ->not->toContain('9999');
});

it('does not allow deleting another customers token', function () {
    $owner = efevooApiCustomerUser();
    $intruder = efevooApiCustomerUser();

    $token = EfevooToken::factory()->create([
        'customer_id' => $owner->customer->id,
        'is_active' => true,
    ]);

    $this->actingAs($intruder)
        ->deleteJson("/api/efevoopay/tokens/{$token->id}")
        ->assertNotFound();

    expect($token->fresh()->is_active)->toBeTrue();
});

it('blocks refunds for authenticated non administrators', function () {
    $user = efevooApiCustomerUser();

    $transaction = EfevooTransaction::query()->create([
        'transaction_id' => '459470',
        'reference' => 'ORD-SEC-1',
        'amount' => 100.00,
        'status' => EfevooTransaction::STATUS_APPROVED,
        'transaction_type' => EfevooTransaction::TYPE_PAYMENT,
    ]);

    $this->instance(EfevooPayGateway::class, Mockery::mock(MockEfevooPayGateway::class, function ($mock) {
        $mock->shouldNotReceive('refundTransaction');
    }));

    $this->actingAs($user)
        ->postJson('/api/efevoopay/refund', [
            'transaction_id' => $transaction->id,
        ])->assertForbidden();
});

it('requires a local efevoopay transaction before searching the provider', function () {
    $admin = efevooApiAdminUser(['payment-attempts.manage']);

    $this->instance(EfevooPayGateway::class, Mockery::mock(MockEfevooPayGateway::class, function ($mock) {
        $mock->shouldNotReceive('searchTransactions');
    }));

    $this->actingAs($admin)
        ->postJson('/api/efevoopay/transactions/search', [
            'transaction_id' => 459470,
        ])->assertNotFound();
});

it('allows permitted admins to search a known local efevoopay transaction only by id', function () {
    $admin = efevooApiAdminUser(['payment-attempts.manage']);

    Transaction::factory()->create([
        'payment_method' => 'efevoopay',
        'gateway_transaction_id' => '459470',
    ]);

    $this->instance(EfevooPayGateway::class, Mockery::mock(MockEfevooPayGateway::class, function ($mock) {
        $mock->shouldReceive('searchTransactions')
            ->once()
            ->with(['transaction_id' => 459470])
            ->andReturn([
                'success' => true,
                'data' => [
                    'data' => [
                        [
                            'transaction_id' => '459470',
                            'card_token' => 'provider-secret-card-token',
                        ],
                    ],
                ],
            ]);
    }));

    $response = $this->actingAs($admin)
        ->postJson('/api/efevoopay/transactions/search', [
            'transaction_id' => 459470,
        ])->assertOk();

    expect($response->getContent())->not->toContain('provider-secret-card-token');
});

it('applies configured rate limits to efevoopay token routes', function () {
    config([
        'efevoopay.rate_limits.tokens.max_attempts' => 1,
        'efevoopay.rate_limits.tokens.decay_minutes' => 1,
    ]);

    $user = efevooApiCustomerUser();
    $this->actingAs($user)->getJson('/api/efevoopay/tokens')->assertOk();
    $this->actingAs($user)->getJson('/api/efevoopay/tokens')->assertTooManyRequests();
});
