<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\User;
use App\Support\EfevooPay3dsResultClassifier;
use App\Support\PaymentAuthentication3dsResultResource;
use App\Support\PaymentAuthenticationAttemptAdminResource;
use App\Support\PaymentAuthenticationRecoveryContextReconciler;
use App\Support\PaymentAuthenticationRecoveryPolicy;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'mock',
        'efevoopay.requires_3ds' => true,
        'efevoopay.recovery_context_ttl_minutes' => 30,
        'efevoopay.recovery.max_attempts_per_context' => 3,
        'efevoopay.recovery.attempt_window_minutes' => 30,
        'efevoopay.recovery.technical_error_cooldown_seconds' => 60,
        'efevoopay.local_real_tests.enabled' => false,
        'app.url' => 'http://localhost',
    ]);
});

function experienceUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function experienceContext(Customer $customer, PaymentAuthenticationRecoveryContextType $type, array $overrides = []): PaymentAuthenticationRecoveryContext
{
    return PaymentAuthenticationRecoveryContext::create(array_merge([
        'context_uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'context_type' => $type->value,
        'status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value,
        'return_route_name' => $type->returnRouteName(),
        'context_data' => $type === PaymentAuthenticationRecoveryContextType::LaboratoryCheckout
            ? ['laboratory_brand' => 'olab', 'step' => 'payment']
            : ($type === PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout
                ? ['purpose' => 'subscription']
                : []),
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ], $overrides));
}

function experienceAttempt(
    Customer $customer,
    PaymentAuthenticationRecoveryContext $context,
    PaymentAuthenticationAttemptStatus $status,
    array $overrides = []
): PaymentAuthenticationAttempt {
    return PaymentAuthenticationAttempt::create(array_merge([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-'.Str::upper(Str::random(8)),
        'customer_id' => $customer->id,
        'recovery_context_id' => $context->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => $status->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(8)),
        'attempt_number' => 1,
        'started_at' => now()->subMinute(),
        'finished_at' => in_array($status, [
            PaymentAuthenticationAttemptStatus::Declined,
            PaymentAuthenticationAttemptStatus::Cancelled,
            PaymentAuthenticationAttemptStatus::Expired,
            PaymentAuthenticationAttemptStatus::TechnicalError,
            PaymentAuthenticationAttemptStatus::Completed,
        ], true) ? now() : null,
        'expires_at' => now()->addMinutes(5),
    ], $overrides));
}

function experienceSession(Customer $customer, PaymentAuthenticationAttempt $attempt, string $status = 'declined'): Efevoo3dsSession
{
    $session = Efevoo3dsSession::create([
        'customer_id' => $customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORDER-'.Str::upper(Str::random(6)),
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => $status,
        'error_message' => $status === 'declined' ? 'Verificación rechazada' : null,
    ]);

    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    return $session->fresh();
}

function experienceResult(Efevoo3dsSession $session, User $user): array
{
    return app(PaymentAuthentication3dsResultResource::class)->make(
        $session->fresh(),
        $user->customer,
        $session->paymentAuthenticationAttempt?->fresh(),
        $session->paymentAuthenticationAttempt?->recoveryContext?->fresh()
    );
}

test('configuracion muestra fallo de autenticacion bancaria sin exigir compra pendiente', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined, [
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_AUTHENTICATION_FAILED,
    ]);
    $session = experienceSession($user->customer, $attempt);
    $result = experienceResult($session, $user);

    expect($result['copy']['message'])->toBe('El banco no aprobó o no pudo completar la verificación. Puedes usar otra tarjeta o intentar después, según el tiempo de espera.')
        ->and($result['copy']['message'])->not->toContain('compra pendiente')
        ->and($result['copy']['message'])->not->toContain('carrito');
});

test('declined con contexto aun in progress habilita recuperacion sin maximo falso', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings, [
        'status' => PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress->value,
    ]);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt, 'declined');
    $result = experienceResult($session, $user);

    expect($result['presentation'])->toBe('declined')
        ->and($result['recovery']['actions']['retry'])->toBeTrue()
        ->and($result['recovery']['actions']['different_card'])->toBeTrue()
        ->and($result['recovery']['block_reason'])->toBeNull()
        ->and($result['recovery']['attempts_remaining'])->toBe(2);
});

test('laboratorio menciona carrito guardado solo si existe', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::LaboratoryCheckout);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);

    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $withCart = experienceResult($session, $user);
    expect($withCart['copy']['message'])->toContain('carrito sigue guardado');

    LaboratoryCartItem::query()->delete();
    $withoutCart = experienceResult($session->fresh(), $user);
    expect($withoutCart['copy']['message'])->not->toContain('carrito sigue guardado');
});

test('membresia no afirma suscripcion creada', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);
    $result = experienceResult($session, $user);

    expect($result['copy']['message'])->toBe('No se completó la verificación de tu método de pago.')
        ->and(strtolower($result['copy']['message']))->not->toContain('suscripción creada');
});

test('farmacia no expone paypal en resultado', function () {
    $user = experienceUser();
    Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Pharmacy,
        'status' => MonitoringCartStatus::Active,
        'total' => 100,
    ]);
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);
    $result = experienceResult($session, $user);

    expect($result['recovery']['supports_paypal'])->toBeFalse()
        ->and($result['recovery']['supports_paypal_future'])->toBeFalse();
});

test('estados terminales recuperables habilitan acciones', function (PaymentAuthenticationAttemptStatus $status) {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, $status);
    $session = experienceSession($user->customer, $attempt, match ($status) {
        PaymentAuthenticationAttemptStatus::Expired => 'cancelled',
        PaymentAuthenticationAttemptStatus::TechnicalError => 'tokenization_failed',
        default => 'declined',
    });
    $result = experienceResult($session, $user);

    expect($result['recovery']['actions']['retry'])->toBeTrue()
        ->and($result['recovery']['actions']['different_card'])->toBeTrue();
})->with([
    PaymentAuthenticationAttemptStatus::Declined,
    PaymentAuthenticationAttemptStatus::Cancelled,
    PaymentAuthenticationAttemptStatus::Expired,
]);

test('technical error expone cooldown', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::TechnicalError, [
        'finished_at' => now(),
    ]);
    $session = experienceSession($user->customer, $attempt, 'tokenization_failed');
    $result = experienceResult($session, $user);

    expect($result['cooldown_remaining_seconds'])->toBeGreaterThan(0)
        ->and($result['recovery']['actions']['retry'])->toBeFalse()
        ->and($result['recovery']['block_reason'])->toBe('cooldown_active')
        ->and($result['recovery']['attempts_remaining'])->toBeGreaterThan(0);
});

test('limite maximo solo aparece cuando no quedan intentos', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);

    foreach ([1, 2] as $number) {
        experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined, [
            'attempt_number' => $number,
            'started_at' => now()->subMinutes(10 - $number),
            'finished_at' => now()->subMinutes(10 - $number),
        ]);
    }

    $latest = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined, [
        'attempt_number' => 3,
    ]);
    $session = experienceSession($user->customer, $latest);
    $result = experienceResult($session, $user);

    expect($result['recovery']['actions']['retry'])->toBeFalse()
        ->and($result['recovery']['block_reason'])->toBe('recovery_limit_reached')
        ->and($result['recovery']['attempts_remaining'])->toBe(0)
        ->and($result['recovery']['maximum_attempts'])->toBe(3);
});

test('confirmacion pendiente no promete notificacion movil', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings, [
        'status' => PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress->value,
    ]);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::ProviderConfirmationPending, ['finished_at' => null]);
    $session = experienceSession($user->customer, $attempt, 'pending');
    $result = experienceResult($session, $user);

    expect($result['copy']['hint'])->toBe('No pudimos confirmar automáticamente el resultado. No se realizará otro intento sin tu autorización.')
        ->and(strtolower($result['copy']['hint']))->not->toContain('avisaremos')
        ->and(strtolower($result['copy']['hint']))->not->toContain('notific');
});

test('unknown y provider confirmation pending bloquean acciones', function (PaymentAuthenticationAttemptStatus $status, bool $refreshStatus) {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings, [
        'status' => PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress->value,
    ]);
    $attempt = experienceAttempt($user->customer, $context, $status, ['finished_at' => null]);
    $session = experienceSession($user->customer, $attempt, 'pending');
    $result = experienceResult($session, $user);

    expect($result['recovery']['actions']['retry'])->toBeFalse()
        ->and($result['recovery']['actions']['different_card'])->toBeFalse()
        ->and($result['recovery']['actions']['refresh_status'])->toBe($refreshStatus);
})->with([
    [PaymentAuthenticationAttemptStatus::Unknown, true],
    [PaymentAuthenticationAttemptStatus::ProviderConfirmationPending, false],
]);

test('authenticated y tokenizing bloquean acciones', function (PaymentAuthenticationAttemptStatus $status) {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings, [
        'status' => PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress->value,
    ]);
    $attempt = experienceAttempt($user->customer, $context, $status, ['finished_at' => null]);
    $session = experienceSession($user->customer, $attempt, 'authenticated');
    $result = experienceResult($session, $user);

    expect($result['recovery']['actions']['retry'])->toBeFalse()
        ->and($result['recovery']['actions']['different_card'])->toBeFalse();
})->with([
    PaymentAuthenticationAttemptStatus::Authenticated,
    PaymentAuthenticationAttemptStatus::Tokenizing,
]);

test('completed usa retorno seguro', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Completed);
    $session = experienceSession($user->customer, $attempt, 'completed');
    $result = experienceResult($session, $user);

    expect($result['success'])->toBeTrue()
        ->and($result['recovery']['actions']['retry'] ?? null)->toBeFalse()
        ->and($result['recovery']['return_action']['route_name'])->toBe('payment-methods.index');
});

test('completed sincroniza contexto card verified y resultado success', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings, [
        'status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value,
    ]);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Completed, [
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_UNKNOWN,
        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN,
        'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN,
    ]);
    $session = experienceSession($user->customer, $attempt, 'completed');

    $this->actingAs($user)
        ->get(route('payment-methods.3ds-result', ['sessionId' => $session->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('result.result_category', EfevooPay3dsResultClassifier::CATEGORY_SUCCESS)
            ->where('result.recovery.status', PaymentAuthenticationRecoveryContextStatus::CardVerified->value)
            ->where('result.recovery.return_action.route_name', 'payment-methods.index'));

    expect($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::CardVerified)
        ->and($context->fresh()->card_verified_at)->not->toBeNull()
        ->and(PaymentAuthenticationAttemptEvent::query()
            ->where('event_type', PaymentAuthenticationAttemptEventType::CardVerified->value)
            ->count())->toBe(1)
        ->and(PaymentAuthenticationAttemptEvent::query()
            ->where('event_type', PaymentAuthenticationAttemptEventType::SafeReturnGenerated->value)
            ->count())->toBe(1);
});

test('reparador card verified drift es dry run e idempotente', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings, [
        'status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value,
        'card_verified_at' => now()->subMinute(),
    ]);

    $dryRun = app(PaymentAuthenticationRecoveryContextReconciler::class)->repairCardVerifiedDrift();
    expect($dryRun['matched'])->toBe(1)
        ->and($dryRun['repaired'])->toBe(0)
        ->and($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable);

    $applied = app(PaymentAuthenticationRecoveryContextReconciler::class)->repairCardVerifiedDrift(false);
    $again = app(PaymentAuthenticationRecoveryContextReconciler::class)->repairCardVerifiedDrift(false);

    expect($applied['repaired'])->toBe(1)
        ->and($again['matched'])->toBe(0)
        ->and($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::CardVerified);
});

test('recovery start registra eventos y prepara formulario', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);

    $this->actingAs($user)
        ->post(route('payment-methods.recovery.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
            'recovery_action' => PaymentAuthenticationRecoveryPolicy::ACTION_RETRY,
        ])
        ->assertRedirect(route('payment-methods.create', ['recovery_context_uuid' => $context->context_uuid]));

    expect(PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::RecoveryStarted->value)
        ->exists())->toBeTrue();

    $this->actingAs($user)
        ->get(route('payment-methods.create', ['recovery_context_uuid' => $context->context_uuid]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isRecoveryForm', true)
            ->where('recoveryForm.recovery_action', PaymentAuthenticationRecoveryPolicy::ACTION_RETRY)
        );
});

test('different card registra intencion changed_card', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);

    $this->actingAs($user)
        ->post(route('payment-methods.recovery.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
            'recovery_action' => PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD,
        ])
        ->assertRedirect();

    $event = PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::ChangedCard->value)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->allowlistedMetadata()['recovery_action'] ?? null)->toBe(PaymentAuthenticationRecoveryPolicy::RECOVERY_INTENT_DIFFERENT_CARD);
});

test('maximo tres intentos y cuarto bloqueado', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);

    foreach ([1, 2, 3] as $number) {
        experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined, [
            'attempt_number' => $number,
            'started_at' => now()->subMinutes(10 - $number),
            'finished_at' => now()->subMinutes(10 - $number),
        ]);
    }

    $latest = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined, [
        'attempt_number' => 3,
    ]);
    $session = experienceSession($user->customer, $latest);

    $this->actingAs($user)
        ->post(route('payment-methods.recovery.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
            'recovery_action' => PaymentAuthenticationRecoveryPolicy::ACTION_RETRY,
        ])
        ->assertStatus(429);

    expect(PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::RecoveryLimitReached->value)
        ->exists())->toBeTrue();
});

test('contexto expirado bloquea recovery start', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings, [
        'expires_at' => now()->subMinute(),
        'status' => PaymentAuthenticationRecoveryContextStatus::Expired->value,
    ]);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);

    $this->actingAs($user)
        ->post(route('payment-methods.recovery.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
            'recovery_action' => PaymentAuthenticationRecoveryPolicy::ACTION_RETRY,
        ])
        ->assertStatus(409);
});

test('recovery start con attempt ajeno responde 404', function () {
    $owner = experienceUser();
    $other = experienceUser();
    $context = experienceContext($owner->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($owner->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($owner->customer, $attempt);

    $this->actingAs($other)
        ->post(route('payment-methods.recovery.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
            'recovery_action' => PaymentAuthenticationRecoveryPolicy::ACTION_RETRY,
        ])
        ->assertNotFound();
});

test('recovery start con contexto ajeno responde 404', function () {
    $owner = experienceUser();
    $other = experienceUser();
    $context = experienceContext($owner->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($owner->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($owner->customer, $attempt);
    $foreignContext = experienceContext($other->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);

    $this->actingAs($owner)
        ->post(route('payment-methods.recovery.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $foreignContext->context_uuid,
            'recovery_action' => PaymentAuthenticationRecoveryPolicy::ACTION_RETRY,
        ])
        ->assertNotFound();
});

test('cargo temporal no promete reembolso en 24-48 horas', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);
    $result = experienceResult($session, $user);

    expect($result['verification_charge']['message'])
        ->not->toContain('24')
        ->not->toContain('48')
        ->not->toContain('reembols');
});

test('resultado no expone pan cvv token ni challenge', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt, 'declined');
    $session->update([
        'url_3dsecure' => 'https://acs.example/challenge',
        'token_3dsecure' => 'secret-creq-value',
    ]);

    $response = $this->actingAs($user)
        ->get(route('payment-methods.3ds-result', ['sessionId' => $session->id]))
        ->assertOk();

    $json = json_encode($response->original->getData()['page']['props'] ?? []);

    expect($json)->not->toContain('secret-creq-value')
        ->and($json)->not->toContain('url_3dsecure');
});

test('support reference visible en resultado', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);

    $this->actingAs($user)
        ->get(route('payment-methods.3ds-result', ['sessionId' => $session->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('result.support.reference', $attempt->support_reference)
        );
});

test('recovery start no acepta datos de tarjeta', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);

    $this->actingAs($user)
        ->post(route('payment-methods.recovery.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
            'recovery_action' => PaymentAuthenticationRecoveryPolicy::ACTION_RETRY,
            'card_number' => '4242424242424242',
            'cvv' => '123',
        ])
        ->assertRedirect();

    expect(PaymentAuthenticationAttempt::count())->toBe(1);
});

test('refresh status registra evento sanitizado', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings, [
        'status' => PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress->value,
    ]);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Unknown, ['finished_at' => null]);
    $session = experienceSession($user->customer, $attempt, 'pending');

    $this->actingAs($user)
        ->postJson(route('payment-methods.3ds-result-sync', ['sessionId' => $session->id]))
        ->assertOk()
        ->assertJsonStructure(['result' => ['presentation', 'recovery', 'support']]);

    $event = PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::RecoveryStatusRefreshed->value)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->allowlistedMetadata())->not->toHaveKey('card_number');
});

test('admin resource muestra intencion sin afirmar cambio comprobado', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::LaboratoryCheckout);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    PaymentAuthenticationAttemptEvent::create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::ChangedCard->value,
        'source' => 'frontend',
        'metadata' => [
            'context_type' => $context->context_type->value,
            'recovery_action' => PaymentAuthenticationRecoveryPolicy::RECOVERY_INTENT_DIFFERENT_CARD,
            'detected_by' => 'recovery_navigation',
        ],
        'occurred_at' => now(),
    ]);

    $detail = PaymentAuthenticationAttemptAdminResource::detail(
        $attempt->fresh(['recoveryContext']),
        $attempt->events()->get(),
        collect([$attempt])
    );

    expect($detail['recovery_context_type'])->toBe(PaymentAuthenticationRecoveryContextType::LaboratoryCheckout->value)
        ->and($detail['recovery_intention'])->toBe('El usuario seleccionó usar otra tarjeta')
        ->and($detail['chain_recovered'])->toBeFalse();
});

test('eventos recovery sanitizan metadata allowlisted', function () {
    $user = experienceUser();
    $context = experienceContext($user->customer, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $attempt = experienceAttempt($user->customer, $context, PaymentAuthenticationAttemptStatus::Declined);
    $session = experienceSession($user->customer, $attempt);

    $this->actingAs($user)
        ->post(route('payment-methods.recovery.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
            'recovery_action' => PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD,
            'pan' => '4111111111111111',
        ])
        ->assertRedirect();

    $metadata = PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::ChangedCard->value)
        ->first()
        ?->allowlistedMetadata();

    expect($metadata)->toHaveKeys(['context_type', 'recovery_action', 'detected_by'])
        ->and($metadata)->not->toHaveKey('pan');
});
