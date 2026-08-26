<?php

use App\Models\User;
use App\Services\EfevooPayService;
use App\Support\EfevooPayTokenizeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'efevoopay.api_url' => 'https://example.invalid/efevoopay/apiservice',
        'efevoopay.api_user' => 'example-api-user',
        'efevoopay.api_key' => 'example-api-key',
        'efevoopay.clave' => 'example-encryption-key-32chars!!',
        'efevoopay.vector' => 'example-init-vec',
        'efevoopay.cliente' => 'example-merchant',
        'efevoopay.totp_secret' => 'EXAMPLETOTPSECRET234567',
        'efevoopay.fiid_comercio' => '0000000',
        'efevoopay.tokenization_verification_amount_cents' => 150,
    ]);
});

function track2TestService(): EfevooPayService
{
    return new EfevooPayService;
}

function tokenizeInterpreter(): object
{
    return new class extends EfevooPayService
    {
        public function interpret(array $response, int $durationMs = 0): array
        {
            return $this->interpretTokenizeResponse($response, $durationMs);
        }
    };
}

function noHttpTokenizeService(): object
{
    return new class extends EfevooPayService
    {
        public int $httpCalls = 0;

        public function getClientToken(string $operation = 'default'): array
        {
            return ['success' => true, 'token' => 'client-token-test'];
        }

        protected function request(array $payload, bool $logRawBody = true): array
        {
            $this->httpCalls++;

            return ['success' => true, 'status' => 200, 'data' => []];
        }
    };
}

it('builds documented track2 PAN=YYMM for 15 16 and 19 digit pans', function (string $pan, int $expectedPanLength) {
    $service = track2TestService();
    $normalized = $service->normalizeCardDataInput([
        'card_number' => $pan,
        'expiration' => '0129',
    ]);

    expect(strlen($normalized['card_number']))->toBe($expectedPanLength);

    $track2 = EfevooPayTokenizeContract::buildTrack2($normalized['card_number'], $normalized['expiration']);

    expect($track2)->toMatch(EfevooPayTokenizeContract::DOCUMENTED_TRACK2_PATTERN)
        ->and(substr($track2, -4))->toBe('2901');
})->with([
    ['378282246310005', 15],
    ['4111-1111-1111-1111', 16],
    ['6011000990139424123', 19],
]);

it('preserves leading zero month and december expiration in track2', function () {
    $service = track2TestService();
    $jan = $service->normalizeCardDataInput(['card_number' => '4111111111111111', 'expiration' => '01/29']);
    $dec = $service->normalizeCardDataInput(['card_number' => '4111111111111111', 'expiration' => '12/30']);

    expect(EfevooPayTokenizeContract::buildTrack2($jan['card_number'], $jan['expiration']))->toEndWith('=2901')
        ->and(EfevooPayTokenizeContract::buildTrack2($dec['card_number'], $dec['expiration']))->toEndWith('=3012');
});

it('strips spaces and dashes from pan before track2', function () {
    $normalized = track2TestService()->normalizeCardDataInput([
        'card_number' => '4111 1111-1111-1111',
        'expiration' => '1229',
    ]);

    expect($normalized['card_number'])->toBe('4111111111111111')
        ->and(EfevooPayTokenizeContract::buildTrack2($normalized['card_number'], $normalized['expiration']))
        ->toBe('4111111111111111=2912');
});

it('locks documented track2 contract example from silent drift', function () {
    expect(EfevooPayTokenizeContract::buildTrack2('1234567891234567', '1229'))
        ->toBe('1234567891234567=2912')
        ->and(EfevooPayTokenizeContract::PAYLOAD_SCHEMA_VERSION)->toBe('efevoo-documented-v1');
});

it('describeRequest exposes allowlisted descriptor without sensitive fields', function () {
    $descriptor = EfevooPayTokenizeContract::describeRequest([
        'card_number' => '4111111111111111',
        'expiration' => '1229',
        'amount' => 1.5,
    ]);

    expect($descriptor)->toMatchArray([
        'payload_schema_version' => 'efevoo-documented-v1',
        'track2_present' => true,
        'track2_type' => 'string',
        'track2_length' => 21,
        'pan_length' => 16,
        'expiration_format' => 'MMYY',
        'separator_kind' => 'equals',
        'amount' => '1.50',
    ])->and(array_keys($descriptor))->not->toContain('track2', 'card_number', 'cvv', 'pan', 'bin', 'last4');
});

it('rejects invalid track2 locally before provider http call', function () {
    $service = noHttpTokenizeService();

    $result = $service->tokenizeCard([
        'card_number' => '123',
        'expiration' => '1229',
        'amount' => 1.5,
        'card_holder' => 'Test User',
    ], 1);

    expect($result['success'])->toBeFalse()
        ->and($result['external_tokenization_attempted'] ?? null)->toBeFalse()
        ->and($result['normalized_reason'] ?? null)->toBe('invalid_track_data')
        ->and($service->httpCalls)->toBe(0);
});

it('interprets http 200 with success code but missing token_usuario as failure', function () {
    $result = tokenizeInterpreter()->interpret([
        'success' => true,
        'status' => 200,
        'data' => ['codigo' => '00', 'descripcion' => 'Aprobada'],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['token_usuario_present'] ?? null)->toBeFalse()
        ->and($result['message'])->toContain('no pudimos guardar la tarjeta');
});

it('interprets http 200 with string 00 and token_usuario as success', function () {
    $result = tokenizeInterpreter()->interpret([
        'success' => true,
        'status' => 200,
        'data' => ['codigo' => '00', 'token_usuario' => 'tok-live-example'],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['token_usuario_present'] ?? null)->toBeTrue()
        ->and($result['provider_code_string'] ?? null)->toBe('00');
});

it('interprets http 200 with zero code and error message as failure not success', function (mixed $code, string $expectedType) {
    $result = tokenizeInterpreter()->interpret([
        'success' => true,
        'status' => 200,
        'data' => [
            'codigo' => $code,
            'descripcion' => 'Reservado para uso privado o Bad Track Data',
        ],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['provider_code_type'] ?? null)->toBe($expectedType)
        ->and($result['provider_code_string'] ?? null)->toBe((string) $code)
        ->and($result['normalized_reason'] ?? null)->toBe('invalid_track_data')
        ->and($result['admin_message'] ?? '')->toContain('datos de tokenización no aceptados')
        ->and($result['message'])->not->toContain('Bad Track');
})->with([
    [0, 'integer'],
    ['0', 'string'],
]);

it('classifies bad track data message before sanitization', function () {
    expect(EfevooPayTokenizeContract::normalizedReasonFromProviderMessage('Bad Track Data'))
        ->toBe('invalid_track_data');
});

it('does not treat http 200 alone as tokenization success', function () {
    $result = tokenizeInterpreter()->interpret([
        'success' => true,
        'status' => 200,
        'data' => ['codigo' => '100'],
    ]);

    expect($result['success'])->toBeFalse();
});

it('locks postman PAN=3005 example as YYMM from MMYY 0530', function () {
    expect(EfevooPayTokenizeContract::buildTrack2('4111111111111111', '0530'))
        ->toEndWith('=3005');
});

it('describeProviderCode preserves integer string and double zero types', function (mixed $code, ?string $expectedType, ?string $expectedString) {
    expect(EfevooPayTokenizeContract::describeProviderCode($code))
        ->toBe(['provider_code_type' => $expectedType, 'provider_code_string' => $expectedString]);
})->with([
    [0, 'integer', '0'],
    ['0', 'string', '0'],
    ['00', 'string', '00'],
]);

it('describeProviderCode failures without token_usuario remain failures', function (mixed $code) {
    $result = tokenizeInterpreter()->interpret([
        'success' => true,
        'status' => 200,
        'data' => ['codigo' => $code, 'descripcion' => 'Rechazada'],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['token_usuario_present'] ?? null)->toBeFalse();
})->with([0, '0', '00']);

it('observed success requires non empty token_usuario with provider code present', function () {
    $result = tokenizeInterpreter()->interpret([
        'success' => true,
        'status' => 200,
        'data' => ['codigo' => '00', 'token_usuario' => 'tok-observed-success'],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['provider_code_type'] ?? null)->toBe('string')
        ->and($result['provider_code_string'] ?? null)->toBe('00');
});

it('tokenizeCard path records provider_code_type integer on bad track response', function () {
    $user = User::factory()->withCompleteProfile()->withRegularCustomer()->create();

    $service = new class extends EfevooPayService
    {
        public function getClientToken(string $operation = 'default'): array
        {
            return ['success' => true, 'token' => 'client-token-test'];
        }

        protected function request(array $payload, bool $logRawBody = true): array
        {
            return [
                'success' => true,
                'status' => 200,
                'response_received' => true,
                'data' => [
                    'codigo' => 0,
                    'descripcion' => 'Reservado para uso privado o Bad Track Data',
                ],
            ];
        }
    };

    $result = $service->tokenizeCard([
        'card_number' => '4111111111111111',
        'expiration' => '1229',
        'amount' => 1.5,
        'card_holder' => 'Test User',
    ], $user->customer->id);

    expect($result['success'])->toBeFalse()
        ->and($result['provider_code_type'] ?? null)->toBe('integer')
        ->and($result['provider_code_string'] ?? null)->toBe('0')
        ->and($result['normalized_reason'] ?? null)->toBe('invalid_track_data')
        ->and($result['track2_present'] ?? null)->toBeTrue()
        ->and($result['request_dispatched'] ?? null)->toBeTrue()
        ->and(array_key_exists('track2', $result))->toBeFalse();
});

it('describeRequest is computed before encryption without storing track2 content', function () {
    Log::spy();
    $user = User::factory()->withCompleteProfile()->withRegularCustomer()->create();

    $service = new class extends EfevooPayService
    {
        public ?array $encryptInput = null;

        public function getClientToken(string $operation = 'default'): array
        {
            return ['success' => true, 'token' => 'client-token-test'];
        }

        protected function encrypt(array $data): string
        {
            $this->encryptInput = $data;

            return 'encrypted-stub';
        }

        protected function request(array $payload, bool $logRawBody = true): array
        {
            return [
                'success' => true,
                'status' => 200,
                'response_received' => true,
                'data' => ['codigo' => '00', 'token_usuario' => 'tok-save'],
            ];
        }
    };

    $result = $service->tokenizeCard([
        'card_number' => '4111111111111111',
        'expiration' => '1229',
        'amount' => 1.5,
    ], $user->customer->id);

    expect($result['payload_schema_version'] ?? null)->toBe('efevoo-documented-v1')
        ->and($result['track2_length'] ?? null)->toBe(21)
        ->and($service->encryptInput)->toHaveKey('track2')
        ->and(array_keys($result))->not->toContain('track2');

    Log::shouldNotHaveReceived('info', function (string $message, array $context = []) {
        return isset($context['track2']);
    });
});

it('preserves provider code string types without numeric coercion', function () {
    expect(EfevooPayTokenizeContract::preserveProviderCodeString('00'))->toBe('00')
        ->and(EfevooPayTokenizeContract::preserveProviderCodeString(0))->toBe('0')
        ->and(EfevooPayTokenizeContract::preserveProviderCodeString('0'))->toBe('0');
});

it('tokenize descriptor keys exclude forbidden metadata fields', function () {
    $descriptor = EfevooPayTokenizeContract::describeRequest([
        'card_number' => '4111111111111111',
        'expiration' => '1229',
        'amount' => 1.5,
    ]);

    foreach (EfevooPayTokenizeContract::TOKENIZE_FORBIDDEN_METADATA_KEYS as $forbidden) {
        expect(array_keys($descriptor))->not->toContain($forbidden);
    }

    foreach (EfevooPayTokenizeContract::TOKENIZE_DESCRIPTOR_KEYS as $expected) {
        if (in_array($expected, ['response_received', 'http_status', 'provider_code_type', 'provider_code_string', 'token_usuario_present', 'duration_ms', 'normalized_reason'], true)) {
            continue;
        }
        expect(array_keys($descriptor))->toContain($expected);
    }
});
