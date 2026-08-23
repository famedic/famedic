<?php

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Support\EfevooPay3dsResultClassifier;
use App\Support\PaymentAuthenticationAttemptRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('blocks guests from the 3ds admin console', function () {
    $this->get(route('admin.payment-authentication-attempts.index'))
        ->assertRedirect();
});

it('blocks regular users from the 3ds admin console', function () {
    $user = threeDsAdminCustomer();

    $this->actingAs($user)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertNotFound();
});

it('returns 403 for an admin without payment-attempts.manage', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate([
        'name' => 'payment-attempts.manage',
        'guard_name' => 'web',
    ]);
    $admin = threeDsAdmin(['efevoo-tokens.manage']);

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertForbidden();
});

it('allows an admin with payment-attempts.manage to open the 3ds console', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();
    threeDsAdminAttempt($customer);

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/PaymentAuthenticationAttempts')
            ->has('attempts.data')
            ->has('metrics')
            ->where('filters.period', '7d')
            ->where('filters.timezone', 'America/Monterrey'));
});

it('does not expose sensitive fields in the listing resource', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();
    $attempt = threeDsAdminAttempt($customer, [
        'provider_order_id' => 'ORD-SAFE',
        'provider_message' => 'Autenticacion incompleta',
    ]);
    $session = Efevoo3dsSession::create([
        'customer_id' => $customer->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORD-SAFE',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'completed',
        'url_3dsecure' => 'https://issuer.example/challenge',
        'token_3dsecure' => 'secret-creq-token',
        'request_data' => ['pan' => '4111111111111111', 'cvv' => '123'],
        'response_data' => ['token' => 'secret-card-token'],
    ]);
    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    $content = $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertOk()
        ->getContent();

    expect($content)
        ->not->toContain('secret-creq-token')
        ->not->toContain('4111111111111111')
        ->not->toContain('secret-card-token')
        ->not->toContain('https://issuer.example/challenge')
        ->not->toContain('request_data')
        ->not->toContain('response_data');
});

it('does not expose sensitive fields in the detail or event resources', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();
    $attempt = threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Expired->value,
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_CHALLENGE_EXPIRED,
        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN,
        'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_PROBABLE,
    ]);

    app(PaymentAuthenticationAttemptRecorder::class)->record($attempt, PaymentAuthenticationAttemptEventType::AttemptExpired, [
        'source' => 'system',
        'result_category' => EfevooPay3dsResultClassifier::CATEGORY_CHALLENGE_EXPIRED,
        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN,
        'metadata' => [
            'detected_by' => 'famedic',
            'session_id' => 88,
            'card_number' => '4111111111111111',
            'token' => 'secret-token',
        ],
    ]);

    PaymentAuthenticationAttemptEvent::query()->create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::ProviderStatusReceived->value,
        'source' => 'efevoopay',
        'occurred_at' => now(),
        'created_at' => now(),
        'metadata' => [
            'session_id' => 88,
            'detected_by' => 'famedic',
            'token' => 'hidden-token',
            'pan' => '4111111111111111',
            'raw_response' => ['cav' => '999'],
        ],
    ]);

    $content = $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.show', $attempt))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('attempt.events', 2)
            ->where('attempt.events.0.metadata.detected_by', 'famedic')
            ->where('attempt.events.0.metadata.session_id', 88)
            ->missing('attempt.events.0.metadata.token')
            ->missing('attempt.events.0.metadata.card_number')
            ->where('attempt.events.1.metadata.detected_by', 'famedic')
            ->missing('attempt.events.1.metadata.token')
            ->missing('attempt.events.1.metadata.pan')
            ->missing('attempt.events.1.metadata.raw_response'))
        ->getContent();

    expect($content)
        ->not->toContain('4111111111111111')
        ->not->toContain('secret-token')
        ->not->toContain('hidden-token')
        ->not->toContain('raw_response');
});

it('returns 404 for an unknown authentication attempt', function () {
    $admin = threeDsAdmin();

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.show', 999999))
        ->assertNotFound();
});

it('rejects invalid filter values instead of interpolating them', function () {
    $admin = threeDsAdmin();

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index', [
            'status' => "completed' OR 1=1 --",
        ]))
        ->assertSessionHasErrors('status');
});

it('avoids n-plus-one queries on the paginated listing', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();

    foreach (range(1, 8) as $index) {
        threeDsAdminAttempt($customer, [
            'support_reference' => 'AUTH-LIST-'.$index,
            'merchant_reference' => 'EFV3DS-LIST-'.$index,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertOk();

    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->count())->toBeLessThan(20)
        ->and($queries->filter(fn (string $sql) => str_contains($sql, 'payment_authentication_attempt_events'))->count())->toBe(0);
});

it('shows the retry chain only through retry_of_attempt_id', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();
    $other = threeDsAdminCustomer();

    $failed = threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'attempt_number' => 1,
    ]);
    $retry = threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'attempt_number' => 2,
        'retry_of_attempt_id' => $failed->id,
    ]);
    threeDsAdminAttempt($other, [
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'started_at' => $retry->started_at,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.show', $failed))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attempt.chain_recovered', true)
            ->where('attempt.chain_final_status', PaymentAuthenticationAttemptStatus::Completed->value)
            ->has('attempt.retry_chain', 2));
});
