<?php

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Support\EfevooPay3dsResultClassifier;
use App\Support\PaymentAuthenticationAttemptRecorder;
use Carbon\Carbon;

it('counts attempts rather than events and keeps duplicates out of the total', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();

    $completed = threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'duplicate_request_count' => 4,
    ]);
    $declined = threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_AUTHENTICATION_FAILED,
        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN,
    ]);
    threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Pending->value,
        'finished_at' => null,
    ]);

    app(PaymentAuthenticationAttemptRecorder::class)->record($completed, PaymentAuthenticationAttemptEventType::AttemptCreated, [
        'source' => 'backend',
        'dedupe_key' => 'attempt_created',
    ]);
    app(PaymentAuthenticationAttemptRecorder::class)->record($declined, PaymentAuthenticationAttemptEventType::AuthenticationDeclined, [
        'source' => 'efevoopay',
        'dedupe_key' => 'authentication_declined',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('metrics.total', 3)
            ->where('metrics.completed', 1)
            ->where('metrics.declined', 1)
            ->where('metrics.active', 1)
            ->where('metrics.terminal', 2)
            ->where('metrics.success_rate', 50)
            ->where('metrics.duplicate_attempts', 1)
            ->where('metrics.duplicate_blocked_count', 4)
            ->where('metrics.customers_affected', 1));
});

it('counts a manual retry as a new attempt and as a recovered chain', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();

    $failed = threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'attempt_number' => 1,
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_AUTHENTICATION_FAILED,
    ]);
    threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'attempt_number' => 2,
        'retry_of_attempt_id' => $failed->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('metrics.total', 2)
            ->where('metrics.manual_retries', 1)
            ->where('metrics.recovered_retries', 1)
            ->where('metrics.completed', 1)
            ->where('metrics.declined', 1)
            ->where('metrics.success_rate', 50));
});

it('keeps active attempts out of the success-rate denominator', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();

    threeDsAdminAttempt($customer, ['status' => PaymentAuthenticationAttemptStatus::Completed->value]);
    threeDsAdminAttempt($customer, ['status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value, 'finished_at' => null]);
    threeDsAdminAttempt($customer, ['status' => PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value, 'finished_at' => null]);

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('metrics.total', 3)
            ->where('metrics.terminal', 1)
            ->where('metrics.active', 2)
            ->where('metrics.success_rate', 100)
            ->where('metrics.unknown_pending', 1));
});

it('uses America/Monterrey day boundaries for the default seven-day range', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-22 15:00:00', 'America/Monterrey'));

    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();

    $appTimezone = (string) config('app.timezone', 'UTC');

    threeDsAdminAttempt($customer, [
        'support_reference' => 'AUTH-INSIDE',
        'merchant_reference' => 'EFV3DS-INSIDE',
        'started_at' => Carbon::parse('2026-08-16 00:00:00', 'America/Monterrey')->timezone($appTimezone),
        'finished_at' => Carbon::parse('2026-08-16 00:01:00', 'America/Monterrey')->timezone($appTimezone),
    ]);
    threeDsAdminAttempt($customer, [
        'support_reference' => 'AUTH-OUTSIDE',
        'merchant_reference' => 'EFV3DS-OUTSIDE',
        'started_at' => Carbon::parse('2026-08-15 23:59:00', 'America/Monterrey')->timezone($appTimezone),
        'finished_at' => Carbon::parse('2026-08-15 23:59:30', 'America/Monterrey')->timezone($appTimezone),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index', ['period' => '7d']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('metrics.total', 1)
            ->where('filters.start_date', '2026-08-16')
            ->where('filters.end_date', '2026-08-22')
            ->where('filters.timezone', 'America/Monterrey')
            ->has('attempts.data', 1)
            ->where('attempts.data.0.support_reference', 'AUTH-INSIDE'));

    Carbon::setTestNow();
});

it('does not attribute cancelled or expired attempts to the bank', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();

    threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Cancelled->value,
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_CANCELLED,
        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN,
        'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN,
    ]);
    threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Expired->value,
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_CHALLENGE_EXPIRED,
        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN,
        'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_PROBABLE,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('metrics.expired_cancelled', 2)
            ->where('attempts.data.0.origin_label', 'Origen no determinado por el proveedor')
            ->where('attempts.data.1.origin_label', 'Origen no determinado por el proveedor'));
});
