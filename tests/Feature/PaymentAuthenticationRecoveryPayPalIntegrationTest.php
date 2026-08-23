<?php

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Actions\PayPal\CreateMedicalAttentionPayPalOrderAction;
use App\Enums\LaboratoryBrand;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Efevoo3dsSession;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\MedicalAttentionSubscription;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPalService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    config(['services.paypal.client_id' => 'test-paypal-client']);
    Queue::fake();
});

function integrationRecoveryUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function integrationRecoveryContext(
    User $user,
    PaymentAuthenticationRecoveryContextType $type = PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout,
    PaymentAuthenticationRecoveryContextStatus $status = PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable,
): PaymentAuthenticationRecoveryContext {
    return PaymentAuthenticationRecoveryContext::create([
        'context_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'context_type' => $type,
        'status' => $status,
        'return_route_name' => $type->returnRouteName(),
        'context_data' => ['step' => 'payment'],
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

function integrationDeclinedAttempt(User $user, PaymentAuthenticationRecoveryContext $context): array
{
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-'.Str::upper(Str::random(8)),
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'declined',
    ]);

    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'efevoo_3ds_session_id' => $session->id,
        'recovery_context_id' => $context->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(8)),
        'attempt_number' => 1,
        'support_reference' => 'SUP-'.Str::upper(Str::random(6)),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $context->update(['root_authentication_attempt_id' => $attempt->id]);
    $session->update(['payment_authentication_attempt_id' => $attempt->id]);

    return [$session, $attempt];
}

function fakePayPalCapturePayload(string $orderId = 'PP-ORDER-1', string $captureId = 'CAP-1'): array
{
    return [
        'id' => $orderId,
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'payments' => [
                'captures' => [[
                    'id' => $captureId,
                    'status' => 'COMPLETED',
                ]],
            ],
        ]],
    ];
}

function bindFakePayPalService(?callable $createOrder = null, ?callable $captureOrder = null): PayPalService
{
    $parser = new PayPalService;
    $paypal = Mockery::mock(PayPalService::class);
    $paypal->shouldReceive('extractCaptureInfo')->andReturnUsing(
        fn (array $payload) => $parser->extractCaptureInfo($payload)
    );

    if ($createOrder) {
        $paypal->shouldReceive('createOrder')->andReturnUsing($createOrder);
    }

    if ($captureOrder) {
        $paypal->shouldReceive('captureOrder')->andReturnUsing($captureOrder);
        $paypal->shouldReceive('getOrder')->andReturnUsing($captureOrder);
    }

    app()->instance(PayPalService::class, $paypal);

    return $paypal;
}

function integrationLabPurchaseStub(int $customerId, string $brand, int $totalCents): LaboratoryPurchase
{
    return LaboratoryPurchase::factory()->create([
        'customer_id' => $customerId,
        'brand' => $brand,
        'gda_order_id' => 'GDA-'.Str::upper(Str::random(10)),
        'name' => 'Paciente',
        'paternal_lastname' => 'Integración',
        'maternal_lastname' => 'PayPal',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => $totalCents,
    ]);
}

function labRecoverySetup(): array
{
    $user = integrationRecoveryUser();
    $brand = LaboratoryBrand::SWISSLAB;
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => false,
        'public_price_cents' => 50000,
        'famedic_price_cents' => 39900,
    ]);
    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);

    $cartItems = $user->customer->laboratoryCartItems()->ofBrand($brand)->with('laboratoryTest')->get();
    $totals = app(CalculateTotalsAndDiscountAction::class)($cartItems);

    $context = PaymentAuthenticationRecoveryContext::create([
        'context_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'context_type' => PaymentAuthenticationRecoveryContextType::LaboratoryCheckout,
        'status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable,
        'return_route_name' => 'laboratory.checkout',
        'context_data' => [
            'laboratory_brand' => $brand->value,
            'step' => 'payment',
            'address_id' => $address->id,
            'contact_id' => $contact->id,
        ],
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    [$session, $attempt] = integrationDeclinedAttempt($user, $context);

    return compact('user', 'brand', 'address', 'contact', 'context', 'session', 'attempt', 'totals');
}

it('laboratory recovery paypal flow creates one order purchase and fulfillment', function () {
    extract(labRecoverySetup());
    $orderId = 'PP-LAB-FLOW-1';
    $capturePayload = fakePayPalCapturePayload($orderId);

    bindFakePayPalService(
        fn () => ['order_id' => $orderId, 'raw' => ['id' => $orderId]],
        fn () => $capturePayload,
    );

    $fulfill = Mockery::mock(FulfillLaboratoryCartOrderAction::class);
    $fulfill->shouldReceive('__invoke')->once()->andReturnUsing(function (
        $customer,
        $brandEnum,
        $address,
        $patient,
        $transaction,
    ) use ($totals) {
        $purchase = integrationLabPurchaseStub($customer->id, $brandEnum->value, $totals['total']);
        $purchase->transactions()->attach($transaction);

        return $purchase;
    });
    app()->instance(FulfillLaboratoryCartOrderAction::class, $fulfill);

    $this->actingAs($user)
        ->postJson(route('payment-methods.recovery.paypal.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertOk();

    $createPayload = [
        'patient_id' => $contact->id,
        'address_id' => $address->id,
        'laboratory_brand' => $brand->value,
        'total' => $totals['total'],
        'recovery_context_uuid' => $context->context_uuid,
    ];

    $first = $this->actingAs($user)->postJson(route('paypal.create-order'), $createPayload)->assertOk()->json();
    $second = $this->actingAs($user)->postJson(route('paypal.create-order'), $createPayload)->assertOk()->json();

    expect($first['order_id'])->toBe($orderId);
    expect($second['order_id'])->toBe($orderId);
    expect($second['transaction_id'])->toBe($first['transaction_id']);
    expect(Transaction::query()->where('payment_method', 'paypal')->count())->toBe(1);

    expect(PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::PaypalOrderReused->value)
        ->exists())->toBeTrue();

    $this->actingAs($user)->postJson(route('paypal.capture-order'), ['order_id' => $orderId])->assertOk();
    $this->actingAs($user)->postJson(route('paypal.capture-order'), ['order_id' => $orderId])->assertOk();

    expect(LaboratoryPurchase::query()->where('customer_id', $user->customer->id)->count())->toBe(1);
    expect(\App\Models\LaboratoryAppointment::query()->where('customer_id', $user->customer->id)->count())->toBe(0);

    $context = $context->fresh();
    expect($context->status)->toBe(PaymentAuthenticationRecoveryContextStatus::Recovered);
    expect($context->recovery_method)->toBe('paypal');
    expect($context->recovered_at)->not->toBeNull();
    expect($context->recovered_transaction_id)->not->toBeNull();
    expect($context->recovery_transaction_id)->toBeNull();

    expect(PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::RecoveryCompleted->value)
        ->exists())->toBeTrue();
});

it('membership recovery paypal flow creates one subscription without duplicate transactions', function () {
    $user = integrationRecoveryUser();
    $context = integrationRecoveryContext($user);
    [$session] = integrationDeclinedAttempt($user, $context);
    $orderId = 'PP-MA-FLOW-1';
    $capturePayload = fakePayPalCapturePayload($orderId);

    bindFakePayPalService(
        fn () => ['order_id' => $orderId, 'raw' => ['id' => $orderId]],
        fn () => $capturePayload,
    );

    $this->actingAs($user)
        ->postJson(route('payment-methods.recovery.paypal.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertOk();

    $first = $this->actingAs($user)->postJson(route('medical-attention.paypal.create-order'), [
        'recovery_context_uuid' => $context->context_uuid,
    ])->assertOk()->json();

    $second = $this->actingAs($user)->postJson(route('medical-attention.paypal.create-order'), [
        'recovery_context_uuid' => $context->context_uuid,
    ])->assertOk()->json();

    expect($first['transaction_id'])->toBe($second['transaction_id']);
    expect(Transaction::query()->where('payment_method', 'paypal')->count())->toBe(1);

    $this->actingAs($user)->postJson(route('medical-attention.paypal.capture-order'), ['order_id' => $orderId])->assertOk();
    $this->actingAs($user)->postJson(route('medical-attention.paypal.capture-order'), ['order_id' => $orderId])->assertOk();

    expect(MedicalAttentionSubscription::query()->where('customer_id', $user->customer->id)->count())->toBe(1);

    $context = $context->fresh();
    expect($context->status)->toBe(PaymentAuthenticationRecoveryContextStatus::Recovered);
    expect($context->recovered_at)->not->toBeNull();
});

it('paypal cancel after capture does not revert recovered context', function () {
    $user = integrationRecoveryUser();
    $context = integrationRecoveryContext($user, PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout, PaymentAuthenticationRecoveryContextStatus::Recovered);
    [$session] = integrationDeclinedAttempt($user, $context);

    $transaction = Transaction::create([
        'transaction_amount_cents' => 30000,
        'payment_method' => 'paypal',
        'payment_provider' => 'paypal',
        'gateway' => 'paypal',
        'reference_id' => 'PP-CAPTURED-1',
        'provider_order_id' => 'PP-CAPTURED-1',
        'payment_status' => 'captured',
        'details' => [
            'purpose' => CreateMedicalAttentionPayPalOrderAction::DETAILS_PURPOSE,
            'customer_id' => $user->customer->id,
            'recovery_context_uuid' => $context->context_uuid,
        ],
    ]);

    $context->update([
        'recovery_method' => 'paypal',
        'recovered_transaction_id' => $transaction->id,
        'recovered_at' => now(),
        'recovery_transaction_id' => null,
    ]);

    $this->actingAs($user)
        ->postJson(route('payment-methods.recovery.paypal.cancel'), [
            'recovery_context_uuid' => $context->context_uuid,
            'transaction_id' => $transaction->id,
        ])
        ->assertOk()
        ->assertJson(['status' => 'already_captured']);

    expect($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::Recovered);
});

it('paypal cancel repeated is idempotent on state', function () {
    $user = integrationRecoveryUser();
    $context = integrationRecoveryContext($user, PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout, PaymentAuthenticationRecoveryContextStatus::PaymentInProgress);
    integrationDeclinedAttempt($user, $context);

    $transaction = Transaction::create([
        'transaction_amount_cents' => 30000,
        'payment_method' => 'paypal',
        'payment_provider' => 'paypal',
        'gateway' => 'paypal',
        'reference_id' => 'PP-CANCEL-IDEM',
        'provider_order_id' => 'PP-CANCEL-IDEM',
        'payment_status' => 'pending',
        'details' => [
            'purpose' => CreateMedicalAttentionPayPalOrderAction::DETAILS_PURPOSE,
            'customer_id' => $user->customer->id,
            'recovery_context_uuid' => $context->context_uuid,
        ],
    ]);

    $context->update(['recovery_transaction_id' => $transaction->id, 'recovery_method' => 'paypal']);

    $payload = [
        'recovery_context_uuid' => $context->context_uuid,
        'transaction_id' => $transaction->id,
    ];

    $this->actingAs($user)->postJson(route('payment-methods.recovery.paypal.cancel'), $payload)->assertOk();
    $this->actingAs($user)->postJson(route('payment-methods.recovery.paypal.cancel'), $payload)->assertOk();

    expect($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable);
});

it('create-order timeout blocks duplicate order and marks confirmation pending', function () {
    $user = integrationRecoveryUser();
    $context = integrationRecoveryContext($user);
    integrationDeclinedAttempt($user, $context);

    $paypal = Mockery::mock(PayPalService::class);
    $paypal->shouldReceive('createOrder')->once()->andThrow(new RuntimeException('timeout simulated'));
    app()->instance(PayPalService::class, $paypal);

    $this->actingAs($user)
        ->postJson(route('medical-attention.paypal.create-order'), [
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertStatus(503)
        ->assertJson([
            'error' => 'recovery_confirmation_pending',
            'message' => 'No pudimos confirmar PayPal en este momento. No vuelvas a intentarlo mientras verificamos el estado.',
        ]);

    $pending = Transaction::query()->where('payment_method', 'paypal')->first();
    expect($pending)->not->toBeNull();
    expect($pending->details['recovery_confirmation_pending'] ?? false)->toBeTrue();
    expect($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::PaymentInProgress);

    $this->actingAs($user)
        ->postJson(route('medical-attention.paypal.create-order'), [
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertStatus(409);
});

it('foreign paypal transaction on capture returns 404', function () {
    $owner = integrationRecoveryUser();
    $intruder = integrationRecoveryUser();
    $context = integrationRecoveryContext($owner);
    integrationDeclinedAttempt($owner, $context);

    $transaction = Transaction::create([
        'transaction_amount_cents' => 30000,
        'payment_method' => 'paypal',
        'payment_provider' => 'paypal',
        'gateway' => 'paypal',
        'reference_id' => 'PP-FOREIGN-1',
        'provider_order_id' => 'PP-FOREIGN-1',
        'payment_status' => 'pending',
        'details' => [
            'purpose' => CreateMedicalAttentionPayPalOrderAction::DETAILS_PURPOSE,
            'customer_id' => $owner->customer->id,
            'recovery_context_uuid' => $context->context_uuid,
        ],
    ]);

    $context->update([
        'recovery_transaction_id' => $transaction->id,
        'recovery_method' => 'paypal',
        'status' => PaymentAuthenticationRecoveryContextStatus::PaymentInProgress,
    ]);

    bindFakePayPalService(null, fn () => fakePayPalCapturePayload('PP-FOREIGN-1'));

    $this->actingAs($intruder)
        ->postJson(route('medical-attention.paypal.capture-order'), ['order_id' => 'PP-FOREIGN-1'])
        ->assertNotFound();
});

it('paypal event metadata exposed to admin excludes sensitive keys', function () {
    $user = integrationRecoveryUser();
    $context = integrationRecoveryContext($user);
    [, $attempt] = integrationDeclinedAttempt($user, $context);

    app(\App\Support\PaymentAuthenticationAttemptRecorder::class)->record(
        $attempt,
        PaymentAuthenticationAttemptEventType::PaypalOrderCreated,
        [
            'source' => 'backend',
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'transaction_id' => 99,
                'provider_order_id' => 'PP-ADMIN-1',
                'access_token' => 'secret-token',
                'authorization' => 'Bearer secret',
            ],
        ]
    );

    $event = $attempt->events()->first();
    $safe = $event->allowlistedMetadata();

    expect($safe)->toHaveKeys(['context_uuid', 'transaction_id', 'provider_order_id']);
    expect($safe)->not->toHaveKey('access_token');
    expect($safe)->not->toHaveKey('authorization');
});

it('admin detail exposes paypal recovery labels and context summary', function () {
    Permission::firstOrCreate(['name' => 'payment-attempts.manage', 'guard_name' => 'web']);

    $admin = User::factory()->withCompleteProfile()->withAdministrator()->create([
        'documentation_accepted_at' => now(),
    ]);
    $admin->administrator->givePermissionTo('payment-attempts.manage');

    $user = integrationRecoveryUser();
    $context = integrationRecoveryContext($user, PaymentAuthenticationRecoveryContextType::LaboratoryCheckout, PaymentAuthenticationRecoveryContextStatus::Recovered);
    [, $attempt] = integrationDeclinedAttempt($user, $context);

    $transaction = Transaction::create([
        'transaction_amount_cents' => 30000,
        'payment_method' => 'paypal',
        'payment_provider' => 'paypal',
        'gateway' => 'paypal',
        'reference_id' => 'PP-ADMIN-DETAIL',
        'provider_order_id' => 'PP-ADMIN-DETAIL',
        'payment_status' => 'captured',
        'details' => ['customer_id' => $user->customer->id],
    ]);

    $context->update([
        'recovery_method' => 'paypal',
        'recovered_transaction_id' => $transaction->id,
        'recovered_at' => now(),
    ]);

    $recorder = app(\App\Support\PaymentAuthenticationAttemptRecorder::class);
    $recorder->record($attempt, PaymentAuthenticationAttemptEventType::ChangedToPaypal, [
        'source' => 'frontend',
        'dedupe_key' => 'changed_to_paypal:'.$attempt->id,
        'metadata' => ['context_uuid' => $context->context_uuid, 'context_type' => $context->context_type->value],
    ]);
    $recorder->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryCompleted, [
        'source' => 'backend',
        'dedupe_key' => 'recovery_completed:'.$context->id.':'.$transaction->id,
        'metadata' => ['transaction_id' => $transaction->id, 'provider_order_id' => 'PP-ADMIN-DETAIL'],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.show', $attempt))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/PaymentAuthenticationAttempt')
            ->where('attempt.recovery_context.recovery_method', 'paypal')
            ->where('attempt.recovery_context.recovered_transaction_id', $transaction->id)
            ->where('attempt.events.0.label', 'Cambió a PayPal')
        );
});

it('online pharmacy context cannot start paypal recovery', function () {
    $user = integrationRecoveryUser();
    $context = integrationRecoveryContext($user, PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout);
    [$session] = integrationDeclinedAttempt($user, $context);

    $this->actingAs($user)
        ->postJson(route('payment-methods.recovery.paypal.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertStatus(409);
});

it('manipulated recovery payment query alone does not enable paypal without valid context', function () {
    $user = integrationRecoveryUser();

    $this->actingAs($user)
        ->get(route('medical-attention.checkout', ['recovery_payment' => 'paypal']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('recoveryPayPal', null));
});
