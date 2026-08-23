<?php

use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Models\User;
use App\Support\EfevooPayAdminResource;
use App\Support\EfevooPayPersistenceNormalizer;
use Spatie\Permission\Models\Permission;

function efevooPersistenceAdmin(array $permissions): User
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

it('does not serialize efevoopay card tokens even with loaded relations', function () {
    $user = medicalAttentionUser();
    $token = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'card_token' => 'secret-card-token',
        'client_token' => 'secret-client-token',
        'metadata' => ['secret' => 'hidden'],
    ]);

    EfevooTransaction::query()->create([
        'efevoo_token_id' => $token->id,
        'transaction_id' => '459470',
        'reference' => 'ORD-1',
        'amount' => 100.00,
        'status' => EfevooTransaction::STATUS_APPROVED,
        'transaction_type' => EfevooTransaction::TYPE_PAYMENT,
        'metadata' => ['card_token' => 'nested-token'],
        'response_data' => ['client_token' => 'nested-client-token'],
    ]);

    $json = json_encode($token->fresh(['transactions'])->toArray());

    expect($json)
        ->not->toContain('secret-card-token')
        ->not->toContain('secret-client-token')
        ->not->toContain('nested-token')
        ->not->toContain('nested-client-token')
        ->not->toContain('metadata')
        ->not->toContain('response_data');
});

it('does not serialize sensitive gateway payload columns from models', function () {
    $attempt = PaymentAttempt::query()->create([
        'customer_id' => medicalAttentionUser()->customer->id,
        'amount_cents' => 10000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_ERROR,
        'raw_response' => ['card_token' => 'secret-card-token'],
    ]);

    $transaction = Transaction::factory()->create([
        'payment_method' => 'efevoopay',
        'gateway_response' => ['card_token' => 'secret-gateway-token'],
        'raw_response' => ['client_token' => 'secret-client-token'],
        'gateway_token' => 'secret-gateway-token-reference',
    ]);

    $session = Efevoo3dsSession::query()->create([
        'customer_id' => medicalAttentionUser()->customer->id,
        'card_last_four' => '4242',
        'amount' => 10.00,
        'status' => 'pending',
        'token_3dsecure' => 'secret-3ds-token',
        'url_3dsecure' => 'https://issuer.example/challenge?creq=secret',
        'response_data' => ['cres' => 'secret-cres'],
        'callback_data' => ['creq' => 'secret-creq'],
    ]);

    $json = json_encode([
        'attempt' => $attempt->toArray(),
        'transaction' => $transaction->toArray(),
        'session' => $session->toArray(),
    ]);

    expect($json)
        ->not->toContain('raw_response')
        ->not->toContain('gateway_response')
        ->not->toContain('gateway_token')
        ->not->toContain('response_data')
        ->not->toContain('callback_data')
        ->not->toContain('secret-card-token')
        ->not->toContain('secret-gateway-token')
        ->not->toContain('secret-client-token')
        ->not->toContain('secret-3ds-token')
        ->not->toContain('secret-cres')
        ->not->toContain('secret-creq');
});

it('normalizes persisted gateway payloads from allowlists only', function () {
    $normalized = EfevooPayPersistenceNormalizer::paymentResult([
        'success' => true,
        'transaction_id' => '459470',
        'authorization_code' => 'AUTH-1',
        'message' => 'Pago aprobado',
        'raw' => [
            'data' => [
                'id' => '459470',
                'codigo' => '00',
                'descripcion' => 'Aprobado',
                'card_token' => 'secret-card-token',
                'client_token' => 'secret-client-token',
                'track2' => 'secret-track',
                'headers' => ['Authorization' => 'Bearer secret'],
            ],
        ],
    ], 'payment');

    $json = json_encode($normalized);

    expect($normalized)
        ->toHaveKey('transaction_id', '459470')
        ->toHaveKey('authorization_code', 'AUTH-1')
        ->and($json)->not->toContain('secret-card-token')
        ->and($json)->not->toContain('secret-client-token')
        ->and($json)->not->toContain('secret-track')
        ->and($json)->not->toContain('Authorization');
});

it('builds admin DTOs without raw processor payloads', function () {
    $attempt = PaymentAttempt::query()->create([
        'customer_id' => medicalAttentionUser()->customer->id,
        'amount_cents' => 10000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_DECLINED,
        'processor_message' => 'card_token rejected',
        'raw_response' => ['card_token' => 'secret-card-token', 'codigo' => '05'],
    ]);

    $transaction = Transaction::factory()->create([
        'payment_method' => 'efevoopay',
        'gateway_response' => ['card_token' => 'secret-gateway-token'],
        'raw_response' => ['client_token' => 'secret-client-token'],
        'details' => ['payment_result' => ['card_token' => 'secret-nested-token']],
    ]);

    $json = json_encode([
        EfevooPayAdminResource::paymentAttempt($attempt),
        EfevooPayAdminResource::transaction($transaction),
    ]);

    expect($json)
        ->not->toContain('raw_response')
        ->not->toContain('gateway_response')
        ->not->toContain('payment_result')
        ->not->toContain('secret-card-token')
        ->not->toContain('secret-gateway-token')
        ->not->toContain('secret-client-token')
        ->not->toContain('secret-nested-token');
});

it('admin payment attempt endpoint does not expose raw response payloads', function () {
    $admin = efevooPersistenceAdmin(['payment-attempts.manage']);
    $attempt = PaymentAttempt::query()->create([
        'customer_id' => medicalAttentionUser()->customer->id,
        'amount_cents' => 10000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_ERROR,
        'raw_response' => ['card_token' => 'secret-card-token'],
    ]);

    $content = $this->actingAs($admin)
        ->get(route('admin.payment-attempts.show', $attempt))
        ->assertOk()
        ->getContent();

    expect($content)
        ->not->toContain('raw_response')
        ->not->toContain('secret-card-token');
});

it('admin efevoopay token endpoint does not expose operational tokens', function () {
    $admin = efevooPersistenceAdmin(['efevoo-tokens.manage']);
    $token = EfevooToken::factory()->create([
        'customer_id' => medicalAttentionUser()->customer->id,
        'card_token' => 'secret-card-token',
        'client_token' => 'secret-client-token',
    ]);

    $content = $this->actingAs($admin)
        ->get(route('admin.efevoo-tokens.show', $token))
        ->assertOk()
        ->getContent();

    expect($content)
        ->not->toContain('card_token')
        ->not->toContain('client_token')
        ->not->toContain('secret-card-token')
        ->not->toContain('secret-client-token');
});
