<?php

use App\Enums\Otp\VonageSmsDeliveryStatus;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpMovementEvent;
use App\Models\OtpSmsDeliveryReceipt;
use App\Models\OtpSmsDeliveryReceiptApplication;
use App\Models\User;
use App\Services\Otp\Delivery\VonageSmsDeliveryReceiptReconciler;
use Illuminate\Support\Str;
use Vonage\Client\Signature;

const VONAGE_DLR_TEST_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789ab';

function enableVonageSmsDlrWebhook(
    string $token = VONAGE_DLR_TEST_TOKEN,
    ?string $signatureSecret = 'sig-secret',
    string $signatureMethod = 'md5hash',
): void {
    config([
        'vonage.sms_dlr.enabled' => true,
        'vonage.sms_dlr.webhook_token' => $token,
        'vonage.sms_dlr.signature_secret' => $signatureSecret,
        'vonage.sms_dlr.signature_method' => $signatureMethod,
        'vonage.sms_dlr.callback_mode' => 'per_message',
    ]);
}

/**
 * Signs DLR params using Vonage\Client\Signature (official SDK algorithm).
 *
 * @param  array<string, scalar|null>  $params
 * @return array<string, scalar|null>
 */
function signVonageSmsDlrParams(
    array $params,
    string $secret,
    string $method = 'md5hash',
): array {
    unset($params['sig']);
    $signature = new Signature($params, $secret, $method);

    return $signature->getSignedParams();
}

/**
 * @param  array<string, scalar|null>  $params
 */
function postVonageSmsDlr(array $params, string $token = VONAGE_DLR_TEST_TOKEN): \Illuminate\Testing\TestResponse
{
    return test()->post(route('webhooks.vonage.sms.delivery', $token), $params);
}

function createDeliveryOperationWithMessageId(string $messageId = 'msg-0001'): OtpDeliveryOperation
{
    $challenge = OtpChallenge::factory()->create([
        'purpose' => 'akubica_login',
        'destination_masked' => '***5678',
    ]);

    return OtpDeliveryOperation::query()->create([
        'operation_key' => hash('sha256', 'test|'.$messageId),
        'otp_challenge_id' => $challenge->id,
        'purpose' => 'akubica_login',
        'status' => 'sms_accepted',
        'primary_channel' => 'sms',
        'provider_alias' => 'vonage',
        'result_class' => 'accepted',
        'attempt_count' => 1,
        'correlation_id' => (string) Str::uuid(),
        'provider_message_id' => $messageId,
        'sms_delivery_status' => VonageSmsDeliveryStatus::Accepted->value,
        'sms_delivery_status_at' => now(),
    ]);
}

beforeEach(function () {
    enableVonageSmsDlrWebhook();
});

test('callback entregado actualiza operacion y registra movimiento', function () {
    $operation = createDeliveryOperationWithMessageId('delivered-msg-1');
    $params = signVonageSmsDlrParams([
        'messageId' => 'delivered-msg-1',
        'status' => 'delivered',
        'err-code' => '0',
        'scts' => '2609081317',
    ], 'sig-secret');

    postVonageSmsDlr($params)->assertOk();

    $operation->refresh();
    expect($operation->sms_delivery_status)->toBe(VonageSmsDeliveryStatus::Delivered->value);
    expect(OtpSmsDeliveryReceipt::query()->count())->toBe(1);
    expect(OtpMovementEvent::query()->where('stage', 'sms_operator_delivered')->exists())->toBeTrue();
});

test('callback rechazado registra estado rejected', function () {
    $operation = createDeliveryOperationWithMessageId('rejected-msg-1');
    $params = signVonageSmsDlrParams([
        'messageId' => 'rejected-msg-1',
        'status' => 'rejected',
        'err-code' => '9',
    ], 'sig-secret');

    postVonageSmsDlr($params)->assertOk();

    expect($operation->fresh()->sms_delivery_status)->toBe(VonageSmsDeliveryStatus::Rejected->value);
});

test('callback expirado registra sms expired sin confundir con challenge otp', function () {
    $operation = createDeliveryOperationWithMessageId('expired-msg-1');
    $params = signVonageSmsDlrParams([
        'messageId' => 'expired-msg-1',
        'status' => 'expired',
        'err-code' => '2',
    ], 'sig-secret');

    postVonageSmsDlr($params)->assertOk();

    expect($operation->fresh()->sms_delivery_status)->toBe(VonageSmsDeliveryStatus::Expired->value);
    expect(OtpMovementEvent::query()->where('stage', 'sms_operator_expired')->exists())->toBeTrue();
    expect(OtpMovementEvent::query()->where('stage', 'verify_expired')->exists())->toBeFalse();
});

test('callback fallido registra estado failed', function () {
    $operation = createDeliveryOperationWithMessageId('failed-msg-1');
    $params = signVonageSmsDlrParams([
        'messageId' => 'failed-msg-1',
        'status' => 'failed',
        'err-code' => '8',
    ], 'sig-secret');

    postVonageSmsDlr($params)->assertOk();

    expect($operation->fresh()->sms_delivery_status)->toBe(VonageSmsDeliveryStatus::Failed->value);
});

test('callback duplicado es idempotente', function () {
    createDeliveryOperationWithMessageId('dup-msg-1');
    $params = signVonageSmsDlrParams([
        'messageId' => 'dup-msg-1',
        'status' => 'delivered',
        'err-code' => '0',
        'scts' => '2609081317',
    ], 'sig-secret');

    postVonageSmsDlr($params)->assertOk();
    postVonageSmsDlr($params)->assertOk();

    expect(OtpSmsDeliveryReceipt::query()->count())->toBe(1);
});

test('callback fuera de orden no retrocede de delivered a accepted', function () {
    $operation = createDeliveryOperationWithMessageId('ooo-msg-1');

    postVonageSmsDlr(signVonageSmsDlrParams([
        'messageId' => 'ooo-msg-1',
        'status' => 'delivered',
        'err-code' => '0',
        'scts' => '111',
    ], 'sig-secret'))->assertOk();

    postVonageSmsDlr(signVonageSmsDlrParams([
        'messageId' => 'ooo-msg-1',
        'status' => 'accepted',
        'err-code' => '0',
        'scts' => '222',
    ], 'sig-secret'))->assertOk();

    expect($operation->fresh()->sms_delivery_status)->toBe(VonageSmsDeliveryStatus::Delivered->value);
});

test('callback con message id desconocido se conserva pendiente de reconciliacion', function () {
    postVonageSmsDlr(signVonageSmsDlrParams([
        'messageId' => 'unknown-msg-999',
        'status' => 'delivered',
        'err-code' => '0',
    ], 'sig-secret'))->assertOk();

    $receipt = OtpSmsDeliveryReceipt::query()->where('provider_message_id', 'unknown-msg-999')->first();
    expect($receipt)->not->toBeNull();
    expect($receipt->otp_delivery_operation_id)->toBeNull();
    expect(OtpDeliveryOperation::query()->where('provider_message_id', 'unknown-msg-999')->exists())->toBeFalse();
    expect(OtpSmsDeliveryReceiptApplication::query()->count())->toBe(0);
});

test('reconciliacion aplica dlr pendiente cuando se persiste provider_message_id', function () {
    $messageId = 'race-msg-delivered-001';

    postVonageSmsDlr(signVonageSmsDlrParams([
        'messageId' => $messageId,
        'status' => 'delivered',
        'err-code' => '0',
        'scts' => '2609081400',
    ], 'sig-secret'))->assertOk();

    expect(OtpSmsDeliveryReceipt::query()->where('provider_message_id', $messageId)->count())->toBe(1);

    $operation = createDeliveryOperationWithMessageId($messageId);
    expect($operation->sms_delivery_status)->toBe(VonageSmsDeliveryStatus::Accepted->value);

    $applied = app(VonageSmsDeliveryReceiptReconciler::class)->reconcilePendingForMessageId($messageId, $operation);

    expect($applied)->toBe(1);
    expect($operation->fresh()->sms_delivery_status)->toBe(VonageSmsDeliveryStatus::Delivered->value);
    expect(OtpSmsDeliveryReceiptApplication::query()->count())->toBe(1);
    expect(OtpMovementEvent::query()->where('stage', 'sms_operator_delivered')->exists())->toBeTrue();

    // Idempotente: segunda reconciliación no duplica aplicación ni movimientos extra
    expect(app(VonageSmsDeliveryReceiptReconciler::class)->reconcilePendingForMessageId($messageId, $operation))->toBe(0);
    expect(OtpSmsDeliveryReceiptApplication::query()->count())->toBe(1);
});

test('firma invalida responde 403', function () {
    createDeliveryOperationWithMessageId('sig-msg-1');

    postVonageSmsDlr([
        'messageId' => 'sig-msg-1',
        'status' => 'delivered',
        'sig' => 'invalid',
    ])->assertForbidden();
});

test('firma ausente responde 403 cuando signature secret esta configurado', function () {
    createDeliveryOperationWithMessageId('nosig-msg-1');

    postVonageSmsDlr([
        'messageId' => 'nosig-msg-1',
        'status' => 'delivered',
        'err-code' => '0',
    ])->assertForbidden();
});

test('token de ruta invalido responde 403', function () {
    postVonageSmsDlr([
        'messageId' => 'token-msg-1',
        'status' => 'delivered',
    ], 'wrong-token-that-is-long-enough-for-validation-0123456789ab')->assertForbidden();
});

test('token de ruta demasiado corto responde 403', function () {
    enableVonageSmsDlrWebhook(token: 'short-token');

    postVonageSmsDlr([
        'messageId' => 'short-token-msg',
        'status' => 'delivered',
    ], 'short-token')->assertForbidden();
});

test('callbacks no almacenan otp ni payloads sensibles', function () {
    createDeliveryOperationWithMessageId('safe-msg-1');
    postVonageSmsDlr(signVonageSmsDlrParams([
        'messageId' => 'safe-msg-1',
        'status' => 'delivered',
        'text' => 'Tu codigo es 123456',
        'err-code' => '0',
    ], 'sig-secret'))->assertOk();

    $receipt = OtpSmsDeliveryReceipt::query()->first();
    expect(json_encode($receipt))->not->toContain('123456');
    expect(OtpMovementEvent::query()->where('stage', 'sms_operator_delivered')->first()?->technical_message)
        ->not->toContain('123456');
});

test('login otp api mantiene contrato tras persistir message id', function () {
    enableLoginOtpWithFakeDelivery();
    app()->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new \Tests\Support\Otp\FakeOtpCodeGenerator('123456'));

    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    test()->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ])->assertStatus(202)
        ->assertJsonStructure([
            'success',
            'data' => ['requires_otp', 'challenge_id', 'purpose', 'channel', 'destination_masked', 'expires_at'],
        ]);

    expect(OtpDeliveryOperation::query()->whereNotNull('provider_message_id')->exists())->toBeTrue();
});
