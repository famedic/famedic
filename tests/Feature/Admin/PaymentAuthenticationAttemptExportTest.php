<?php

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Support\EfevooPay3dsResultClassifier;

it('exports only the safe columns and keeps tokens out of the file', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();
    $attempt = threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'provider_order_id' => 'ORD-EXPORT',
        'provider_code' => '0',
        'provider_message' => 'Autenticacion completada',
        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_EFEVOOPAY,
        'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED,
        'attempt_number' => 2,
        'retry_of_attempt_id' => null,
    ]);

    Efevoo3dsSession::create([
        'customer_id' => $customer->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORD-EXPORT',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'completed',
        'token_3dsecure' => 'secret-export-token',
        'url_3dsecure' => 'https://issuer.example/creq',
        'request_data' => ['pan' => '4111111111111111', 'cvv' => '123', 'raw' => 'payload'],
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.export', ['period' => '7d']))
        ->assertOk();

    $path = $response->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);
    $exported = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $exported .= (string) $zip->getFromIndex($i);
    }
    $zip->close();

    expect($exported)
        ->toContain('Support reference')
        ->toContain($attempt->support_reference)
        ->toContain($attempt->attempt_uuid)
        ->toContain((string) $customer->customer->id)
        ->not->toContain('secret-export-token')
        ->not->toContain('4111111111111111')
        ->not->toContain('creq')
        ->not->toContain('token_3dsecure')
        ->not->toContain('request_data')
        ->not->toContain('raw_response');
});

it('respects active filters in the export', function () {
    $admin = threeDsAdmin();
    $customer = threeDsAdminCustomer();
    $completed = threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'support_reference' => 'AUTH-EXPORT-OK',
        'merchant_reference' => 'EFV3DS-EXPORT-OK',
    ]);
    threeDsAdminAttempt($customer, [
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'support_reference' => 'AUTH-EXPORT-NO',
        'merchant_reference' => 'EFV3DS-EXPORT-NO',
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.payment-authentication-attempts.export', [
            'period' => '7d',
            'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        ]))
        ->assertOk();

    $path = $response->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);
    $exported = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $exported .= (string) $zip->getFromIndex($i);
    }
    $zip->close();

    expect($exported)
        ->toContain($completed->support_reference)
        ->not->toContain('AUTH-EXPORT-NO');
});
