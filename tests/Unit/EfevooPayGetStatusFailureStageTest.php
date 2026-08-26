<?php

use App\Services\EfevooPayService;
use App\Support\PaymentAuthentication3dsProviderCallException;

function bindGetStatusConfig(): void
{
    config([
        'efevoopay.api_url' => 'https://example.invalid/efevoopay/apiservice',
        'efevoopay.api_user' => 'example-api-user',
        'efevoopay.api_key' => 'example-api-key',
        'efevoopay.clave' => 'example-encryption-key',
        'efevoopay.vector' => '1234567890123456',
        'efevoopay.cliente' => 'example-merchant',
        'efevoopay.totp_secret' => 'EXAMPLETOTPSECRET234567',
        'efevoopay.fiid_comercio' => '0000000',
        'app.debug' => false,
    ]);
}

it('client token failure before GetStatus is technical and not a timeout', function () {
    bindGetStatusConfig();

    $service = new class extends EfevooPayService
    {
        public function getClientToken(string $operation = 'default'): array
        {
            return ['success' => false, 'error_type' => self::ERROR_GATEWAY, 'status' => 200];
        }
    };

    try {
        $service->payments3DSGetStatus([
            'card_number' => '4242424242424242',
            'expiration' => '1229',
            'cvv' => '123',
        ], '31160');
        expect(false)->toBeTrue();
    } catch (PaymentAuthentication3dsProviderCallException $e) {
        expect($e->failureStage())->toBe('get_client_token')
            ->and($e->exceptionCategory())->toBe('technical_error_before_dispatch')
            ->and($e->requestDispatched())->toBeFalse()
            ->and($e->responseReceived())->toBeFalse();
    }
});

it('http timeout after GetStatus dispatch is typed as timeout', function () {
    bindGetStatusConfig();

    $service = new class extends EfevooPayService
    {
        public function getClientToken(string $operation = 'default'): array
        {
            return ['success' => true, 'token' => 'client-token'];
        }

        protected function encrypt(array $data): string
        {
            return 'encrypted';
        }

        protected function request(array $payload, bool $logRawBody = true): array
        {
            return [
                'success' => false,
                'status' => 0,
                'data' => null,
                'request_dispatched' => true,
                'response_received' => false,
                'timed_out' => true,
                'curl_errno' => 28,
            ];
        }
    };

    try {
        $service->payments3DSGetStatus([
            'card_number' => '4242424242424242',
            'expiration' => '1229',
            'cvv' => '123',
        ], '31160');
        expect(false)->toBeTrue();
    } catch (PaymentAuthentication3dsProviderCallException $e) {
        expect($e->failureStage())->toBe('request_get_status')
            ->and($e->exceptionCategory())->toBe('network_timeout_after_dispatch')
            ->and($e->requestDispatched())->toBeTrue()
            ->and($e->responseReceived())->toBeFalse();
    }
});
