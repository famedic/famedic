<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\Efevoo3dsSession;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PaymentAuthenticationRecoveryContextManager;
use App\Support\PaymentAuthenticationRecoveryContextResource;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'mock',
        'efevoopay.requires_3ds' => true,
        'efevoopay.recovery_context_ttl_minutes' => 30,
        'efevoopay.local_real_tests.enabled' => false,
        'app.url' => 'http://localhost',
    ]);
});

function recoveryUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function recoveryCardPayload(array $overrides = []): array
{
    return array_merge([
        'card_number' => '4242424242424242',
        'exp_month' => '12',
        'exp_year' => '29',
        'cvv' => '123',
        'card_holder' => 'TEST USER',
        'alias' => 'visa-4242',
        'terms_accepted' => '1',
        'attempt_uuid' => (string) Str::uuid(),
    ], $overrides);
}

function seedLabCart(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): LaboratoryCartItem
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => false,
    ]);

    return LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
}

function bindRecoveryGateway(?callable $initiate = null, ?callable $complete = null): void
{
    app()->instance(EfevooPayGateway::class, new class($initiate, $complete) implements EfevooPayGateway
    {
        public function __construct(private $initiate, private $complete) {}

        public function chargeCard(array $data): array
        {
            return ['success' => true];
        }

        public function tokenizeCard(array $cardData, int $customerId): array
        {
            return ['success' => true, 'token_id' => 1];
        }

        public function initiate3DS(array $cardData, int $customerId): array
        {
            if ($this->initiate) {
                return ($this->initiate)($cardData, $customerId);
            }

            $session = Efevoo3dsSession::create([
                'customer_id' => $customerId,
                'order_id' => 'ORDER-'.Str::upper(Str::random(8)),
                'card_last_four' => '4242',
                'amount' => 1.5,
                'status' => 'mock_pending',
                'url_3dsecure' => 'https://issuer.example/challenge',
                'token_3dsecure' => 'secret-creq',
            ]);

            return ['success' => true, 'session_id' => $session->id];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            if ($this->complete) {
                return ($this->complete)($session, $cardData);
            }

            $session->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return ['success' => true];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            $session->update(['status' => 'authenticated']);

            return ['phase' => 'authenticated', 'success' => true];
        }

        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            $session->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return ['success' => true, 'transaction_id' => 'RECOVERY-TOK-1'];
        }

        public function healthCheck(): array
        {
            return ['success' => true];
        }

        public function getTestCards(): array
        {
            return [];
        }
    });
}

test('alta desde configuracion crea contexto correcto', function () {
    $user = recoveryUser();

    $this->actingAs($user)
        ->get(route('payment-methods.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PaymentMethods/Create')
            ->where('recoveryContext.context_type', PaymentAuthenticationRecoveryContextType::PaymentMethodSettings->value)
            ->where('recoveryContext.supports_paypal', false)
            ->where('recoveryContext.has_saved_cart', false)
            ->has('recoveryContext.context_uuid')
            ->missing('recoveryContext.context_data')
        );

    $context = PaymentAuthenticationRecoveryContext::first();

    expect($context)->not->toBeNull()
        ->and($context->customer_id)->toBe($user->customer->id)
        ->and($context->status)->toBe(PaymentAuthenticationRecoveryContextStatus::Open)
        ->and($context->return_route_name)->toBe('payment-methods.index')
        ->and($context->context_data)->toBe([]);
});

test('laboratorio crea contexto con datos allowlisted', function () {
    $user = recoveryUser();
    seedLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    $coupon = Coupon::factory()->couponType(1000)->create();
    CouponUser::query()->create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'assigned_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'origin' => 'laboratory',
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'contact' => $contact->id,
            'address' => $address->id,
            'coupon_id' => $coupon->id,
            'step' => 'payment',
            'payment_method' => 'should-not-persist',
            'promo_validation_token' => 'raw-promo-token',
            'card_number' => '4242424242424242',
            'cvv' => '123',
        ]))
        ->assertOk();

    $context = PaymentAuthenticationRecoveryContext::first();

    expect($context->context_type)->toBe(PaymentAuthenticationRecoveryContextType::LaboratoryCheckout)
        ->and($context->context_data)->toMatchArray([
            'laboratory_brand' => 'olab',
            'contact_id' => $contact->id,
            'address_id' => $address->id,
            'coupon_id' => $coupon->id,
            'step' => 'payment',
        ])
        ->and($context->context_data)->not->toHaveKeys([
            'payment_method',
            'promo_validation_token',
            'card_number',
            'cvv',
            'return_url',
        ]);
});

test('membresia crea contexto correcto', function () {
    $user = recoveryUser();

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'origin' => 'medical-attention-checkout',
            'step' => 'payment',
        ]))
        ->assertOk();

    $context = PaymentAuthenticationRecoveryContext::first();

    expect($context->context_type)->toBe(PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout)
        ->and($context->return_route_name)->toBe('medical-attention.checkout')
        ->and($context->context_data['purpose'])->toBe('subscription');
});

test('farmacia crea contexto sin paypal', function () {
    $user = recoveryUser();
    Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Pharmacy,
        'status' => MonitoringCartStatus::Active,
        'total' => 100,
    ]);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'origin' => 'online-pharmacy',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('recoveryContext.context_type', PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout->value)
            ->where('recoveryContext.supports_paypal', false)
            ->where('recoveryContext.has_saved_cart', true)
        );
});

test('url externa es rechazada', function () {
    $user = recoveryUser();

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'return_url' => 'https://evil.example/phish',
        ]))
        ->assertStatus(422);
});

test('ruta no allowlisted es rechazada', function () {
    $user = recoveryUser();

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'return_url' => '/admin',
        ]))
        ->assertStatus(422);
});

test('context ajeno responde 404', function () {
    $owner = recoveryUser();
    $other = recoveryUser();
    $context = PaymentAuthenticationRecoveryContext::factory()->create([
        'customer_id' => $owner->customer->id,
    ]);

    $this->actingAs($other)
        ->get(route('payment-methods.create', [
            'recovery_context_uuid' => $context->context_uuid,
        ]))
        ->assertNotFound();

    $this->actingAs($other)
        ->post(route('payment-methods.store'), recoveryCardPayload([
            'recovery_context_uuid' => $context->context_uuid,
        ]))
        ->assertNotFound();
});

test('contact address y cart ajenos son rechazados', function () {
    $user = recoveryUser();
    $other = recoveryUser();
    seedLabCart($user);
    $foreignContact = Contact::factory()->create(['customer_id' => $other->customer->id]);
    $foreignAddress = Address::factory()->create(['customer_id' => $other->customer->id]);

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'origin' => 'laboratory',
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'contact' => $foreignContact->id,
        ]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'origin' => 'laboratory',
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'address' => $foreignAddress->id,
        ]))
        ->assertNotFound();
});

test('promo token y pan cvv no se persisten', function () {
    $user = recoveryUser();
    seedLabCart($user);

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'origin' => 'laboratory',
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'promo_validation_token' => 'promo-secret',
            'card_number' => '4111111111111111',
            'cvv' => '999',
        ]))
        ->assertOk();

    $context = PaymentAuthenticationRecoveryContext::first();
    $encoded = json_encode($context->getAttributes());

    expect($context->context_data)->not->toHaveKey('promo_validation_token')
        ->and($encoded)->not->toContain('promo-secret')
        ->and($encoded)->not->toContain('4111111111111111')
        ->and($encoded)->not->toContain('999');
});

test('refresh reutiliza contexto y no crea multiples activos', function () {
    $user = recoveryUser();

    $this->actingAs($user)->get(route('payment-methods.create'))->assertOk();
    $first = PaymentAuthenticationRecoveryContext::first();

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'recovery_context_uuid' => $first->context_uuid,
        ]))
        ->assertOk();

    $this->actingAs($user)->get(route('payment-methods.create'))->assertOk();

    expect(PaymentAuthenticationRecoveryContext::count())->toBe(1)
        ->and(PaymentAuthenticationRecoveryContext::first()->context_uuid)->toBe($first->context_uuid);
});

test('context expirado no se usa', function () {
    $user = recoveryUser();
    $expired = PaymentAuthenticationRecoveryContext::factory()->expired()->create([
        'customer_id' => $user->customer->id,
        'expires_at' => now()->subMinute(),
        'status' => PaymentAuthenticationRecoveryContextStatus::Open,
    ]);

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'recovery_context_uuid' => $expired->context_uuid,
        ]))
        ->assertOk();

    $fresh = PaymentAuthenticationRecoveryContext::query()
        ->where('id', '!=', $expired->id)
        ->first();

    expect($fresh)->not->toBeNull()
        ->and($fresh->context_uuid)->not->toBe($expired->context_uuid)
        ->and($expired->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::Expired);

    $this->actingAs($user)
        ->post(route('payment-methods.store'), recoveryCardPayload([
            'recovery_context_uuid' => $expired->context_uuid,
        ]))
        ->assertStatus(409);
});

test('primer attempt establece root y retry conserva contexto', function () {
    bindRecoveryGateway();
    $user = recoveryUser();

    $this->actingAs($user)->get(route('payment-methods.create'))->assertOk();
    $uuid = PaymentAuthenticationRecoveryContext::first()->context_uuid;

    $this->actingAs($user)
        ->post(route('payment-methods.store'), recoveryCardPayload([
            'recovery_context_uuid' => $uuid,
        ]))
        ->assertRedirect();

    $first = PaymentAuthenticationAttempt::first();
    $context = $first->recoveryContext;

    expect($context)->not->toBeNull()
        ->and($context->root_authentication_attempt_id)->toBe($first->id)
        ->and($context->status)->toBe(PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress);

    $first->update([
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'finished_at' => now(),
    ]);
    app(PaymentAuthenticationRecoveryContextManager::class)->syncFromAttempt($first->fresh());

    expect($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable);

    $this->actingAs($user)
        ->post(route('payment-methods.store'), recoveryCardPayload([
            'recovery_context_uuid' => $uuid,
            'retry_of_attempt_id' => $first->id,
        ]))
        ->assertRedirect();

    $retry = PaymentAuthenticationAttempt::where('retry_of_attempt_id', $first->id)->first();

    expect($retry->recovery_context_id)->toBe($context->id)
        ->and($context->fresh()->root_authentication_attempt_id)->toBe($first->id)
        ->and(PaymentAuthenticationRecoveryContext::count())->toBe(1)
        ->and($retry->efevoo_3ds_session_id)->not->toBe($first->efevoo_3ds_session_id);
});

test('segunda pestana no crea pago ni contexto indebido', function () {
    bindRecoveryGateway();
    $user = recoveryUser();

    $this->actingAs($user)->get(route('payment-methods.create'))->assertOk();
    $this->actingAs($user)->get(route('payment-methods.create'))->assertOk();

    expect(PaymentAuthenticationRecoveryContext::count())->toBe(1);

    $uuid = PaymentAuthenticationRecoveryContext::first()->context_uuid;

    $this->actingAs($user)
        ->post(route('payment-methods.store'), recoveryCardPayload([
            'recovery_context_uuid' => $uuid,
        ]))
        ->assertRedirect();

    $this->actingAs($user)
        ->postJson(route('payment-methods.store'), recoveryCardPayload([
            'recovery_context_uuid' => $uuid,
            'attempt_uuid' => (string) Str::uuid(),
        ]))
        ->assertConflict();

    expect(PaymentAuthenticationRecoveryContext::count())->toBe(1)
        ->and(PaymentAuthenticationAttempt::count())->toBe(1)
        ->and(LaboratoryPurchase::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

test('fallo terminal deja recovery available y unknown mantiene bloqueo', function () {
    $user = recoveryUser();
    $context = PaymentAuthenticationRecoveryContext::factory()->create([
        'customer_id' => $user->customer->id,
        'status' => PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress,
    ]);
    $declined = PaymentAuthenticationAttempt::factory()->create([
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $context->id,
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'finished_at' => now(),
    ]);
    $context->update(['root_authentication_attempt_id' => $declined->id]);

    $manager = app(PaymentAuthenticationRecoveryContextManager::class);
    $manager->syncFromAttempt($declined->fresh());

    expect($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable);

    $blockedContext = PaymentAuthenticationRecoveryContext::factory()->create([
        'customer_id' => $user->customer->id,
        'status' => PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress,
    ]);
    $unknown = PaymentAuthenticationAttempt::factory()->create([
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $blockedContext->id,
        'status' => PaymentAuthenticationAttemptStatus::Unknown->value,
    ]);

    $manager->syncFromAttempt($unknown->fresh());

    expect($blockedContext->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress);

    $resource = app(PaymentAuthenticationRecoveryContextResource::class)->make(
        $blockedContext->fresh(),
        $user->customer,
        $unknown->fresh()
    );

    expect($resource['supports_paypal'])->toBeFalse()
        ->and($resource['supports_another_card'])->toBeFalse()
        ->and($resource['supports_retry'])->toBeFalse();
});

test('tokenizacion completa deja card verified sin purchase ni transaction', function () {
    bindRecoveryGateway();
    $user = recoveryUser();
    seedLabCart($user);

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'origin' => 'laboratory',
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'step' => 'payment',
        ]))
        ->assertOk();

    $context = PaymentAuthenticationRecoveryContext::first();

    $this->actingAs($user)
        ->post(route('payment-methods.store'), recoveryCardPayload([
            'recovery_context_uuid' => $context->context_uuid,
        ]))
        ->assertRedirect();

    $session = Efevoo3dsSession::first();
    $session->paymentAuthenticationAttempt?->update([
        'status' => PaymentAuthenticationAttemptStatus::Pending->value,
    ]);
    $session->update([
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('payment-methods.3ds-result', ['sessionId' => $session->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PaymentMethods/ThreeDSResult')
            ->where('success', true)
            ->where('recoveryContext.status', PaymentAuthenticationRecoveryContextStatus::CardVerified->value)
            ->where('recoveryContext.return_action.route_name', 'laboratory.checkout')
            ->missing('recoveryContext.context_data')
        );

    expect($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::CardVerified)
        ->and($context->fresh()->card_verified_at)->not->toBeNull()
        ->and($context->fresh()->recovered_at)->toBeNull()
        ->and(LaboratoryPurchase::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

test('retorno laboratorio membresia farmacia y configuracion usan rutas seguras', function () {
    $user = recoveryUser();
    seedLabCart($user);
    Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Pharmacy,
        'status' => MonitoringCartStatus::Active,
        'total' => 100,
    ]);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    $this->actingAs($user)->get(route('payment-methods.create', [
        'origin' => 'laboratory',
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
        'step' => 'payment',
        'contact' => $contact->id,
        'address' => $address->id,
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('recoveryContext.return_action.route_name', 'laboratory.checkout')
        ->where('recoveryContext.return_action.params.laboratory_brand', 'olab')
    );

    expect(LaboratoryPurchase::count())->toBe(0);

    $this->actingAs($user)->get(route('payment-methods.create', [
        'origin' => 'medical-attention-checkout',
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('recoveryContext.return_action.route_name', 'medical-attention.checkout')
    );

    $this->actingAs($user)->get(route('payment-methods.create', [
        'origin' => 'online-pharmacy',
        'contact' => $contact->id,
        'address' => $address->id,
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('recoveryContext.return_action.route_name', 'online-pharmacy.checkout')
    );

    $this->actingAs($user)->get(route('payment-methods.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('recoveryContext.return_action.route_name', 'payment-methods.index')
        );
});

test('legacy return url segura funciona y la externa se rechaza', function () {
    $user = recoveryUser();
    seedLabCart($user);

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'return_url' => route('laboratory.checkout', ['laboratory_brand' => 'olab', 'step' => 'payment'], false),
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('recoveryContext.context_type', PaymentAuthenticationRecoveryContextType::LaboratoryCheckout->value)
            ->where('recoveryContext.return_action.route_name', 'laboratory.checkout')
        );

    $context = PaymentAuthenticationRecoveryContext::first();
    expect($context->context_data)->not->toHaveKey('return_url');

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'return_url' => 'https://paypal.com/checkout',
        ]))
        ->assertStatus(422);
});

test('resource calcula flags y no expone campos sensibles', function () {
    $user = recoveryUser();
    seedLabCart($user);
    $context = PaymentAuthenticationRecoveryContext::factory()->laboratory()->create([
        'customer_id' => $user->customer->id,
        'status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable,
        'context_data' => [
            'laboratory_brand' => 'olab',
            'step' => 'payment',
            'promo_validation_token' => 'must-not-leak',
        ],
    ]);

    $resource = app(PaymentAuthenticationRecoveryContextResource::class)->make($context, $user->customer);

    expect($resource['supports_paypal'])->toBeTrue()
        ->and($resource['supports_another_card'])->toBeTrue()
        ->and($resource['supports_retry'])->toBeTrue()
        ->and($resource['has_saved_cart'])->toBeTrue()
        ->and($resource)->not->toHaveKeys(['context_data', 'cart_id', 'id', 'customer_id'])
        ->and(json_encode($resource))->not->toContain('must-not-leak')
        ->and(json_encode($resource))->not->toContain('4242')
        ->and(json_encode($context->toArray()))->not->toContain('must-not-leak');
});

test('eventos no contienen context data completo', function () {
    bindRecoveryGateway();
    $user = recoveryUser();
    seedLabCart($user);

    $this->actingAs($user)
        ->get(route('payment-methods.create', [
            'origin' => 'laboratory',
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'step' => 'payment',
        ]))
        ->assertOk();

    $context = PaymentAuthenticationRecoveryContext::first();

    $this->actingAs($user)
        ->post(route('payment-methods.store'), recoveryCardPayload([
            'recovery_context_uuid' => $context->context_uuid,
        ]))
        ->assertRedirect();

    $events = PaymentAuthenticationAttemptEvent::query()
        ->whereIn('event_type', [
            PaymentAuthenticationAttemptEventType::RecoveryContextCreated->value,
            PaymentAuthenticationAttemptEventType::RecoveryContextAttached->value,
        ])
        ->get();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event->metadata ?? [])->not->toHaveKey('context_data')
            ->and(json_encode($event->getAttributes()))->not->toContain('card_number')
            ->and($event->metadata['context_uuid'] ?? null)->toBe($context->context_uuid);
    }
});

test('intentos legacy muestran context type unknown y no fallan', function () {
    $user = recoveryUser();
    $attempt = PaymentAuthenticationAttempt::factory()->create([
        'customer_id' => $user->customer->id,
        'recovery_context_id' => null,
    ]);

    expect($attempt->displayContextType())->toBe(PaymentAuthenticationRecoveryContextType::UNKNOWN);
});
