<?php

use App\Actions\Laboratories\GetGDAResultsAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'services.gda.url' => 'https://fake-gda.test/',
        'services.gda.brands' => [
            'olab' => [
                'brand_id' => 1,
                'brand_agreement_id' => 17682,
                'token' => 'fake-token',
            ],
            'swisslab' => [
                'brand_id' => 5,
                'brand_agreement_id' => 17479,
                'token' => 'fake-token-swisslab',
            ],
        ],
    ]);
});

function gabinetePayload(string $orderId = 'GZ0L000515'): array
{
    return [
        'id' => $orderId,
        'header' => ['marca' => 1, 'lineanegocio' => '3'],
        'requisition' => [
            'value' => '2066',
            'convenio' => 17682,
        ],
        'code' => [
            'coding' => [[
                'infogda_orden' => '515',
                'infogda_muestras' => [[
                    'infogda_etiqueta' => $orderId,
                    'infogda_contenedoracronim' => 'GAB',
                ]],
            ]],
        ],
    ];
}

function laboratorioPayload(string $orderId = '24642071'): array
{
    return [
        'id' => $orderId,
        'header' => ['marca' => 5, 'lineanegocio' => '3'],
        'requisition' => [
            'value' => 'HD0L001392',
            'convenio' => 17479,
        ],
        'code' => [
            'coding' => [[
                'infogda_orden' => '1392',
            ]],
        ],
    ];
}

function fakeGdaSuccessResponse(): void
{
    Http::fake([
        'fake-gda.test/*' => Http::response([
            'infogda_resultado_b64' => base64_encode('%PDF-1.4 test'),
            'GDA_menssage' => ['codeHttp' => 200],
        ]),
    ]);
}

it('gabinete: usa orderId en vez de requisition.value como payload id', function () {
    fakeGdaSuccessResponse();
    Log::spy();

    $action = app(GetGDAResultsAction::class);
    $result = $action('GZ0L000515', gabinetePayload());

    expect($result)->toHaveKey('infogda_resultado_b64');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['id'] === 'GZ0L000515'
            && $body['requisition']['value'] === '2066';
    });
});

it('gabinete: no reemplaza id con requisition.value', function () {
    fakeGdaSuccessResponse();

    $action = app(GetGDAResultsAction::class);
    $action('GZ0L000515', gabinetePayload());

    Http::assertSent(function ($request) {
        return $request->data()['id'] !== '2066';
    });
});

it('laboratorio normal: conserva orderId numérico', function () {
    fakeGdaSuccessResponse();

    $action = app(GetGDAResultsAction::class);
    $action('24642071', laboratorioPayload());

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['id'] === '24642071'
            && $body['requisition']['value'] === 'HD0L001392';
    });
});

it('loguea payload_id y requested_order_id antes de llamar a GDA', function () {
    fakeGdaSuccessResponse();
    Log::spy();

    $action = app(GetGDAResultsAction::class);
    $action('GZ0L000515', gabinetePayload());

    Log::shouldHaveReceived('info')
        ->once()
        ->with(
            'GDA results consult payload prepared',
            \Mockery::on(fn (array $ctx) => $ctx['requested_order_id'] === 'GZ0L000515'
                && $ctx['payload_id'] === 'GZ0L000515'
                && $ctx['requisition_value'] === '2066'
                && $ctx['is_payload_id_same_as_requested_order_id'] === true)
        );
});

it('fallback: usa payload.id si orderId está vacío', function () {
    fakeGdaSuccessResponse();

    $payload = gabinetePayload();
    $payload['id'] = 'FALLBACK-ID';

    $action = app(GetGDAResultsAction::class);

    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('resolvePayloadId');
    $method->setAccessible(true);

    $resolved = $method->invoke($action, '', $payload);

    expect($resolved)->toBe('FALLBACK-ID');
});

it('fallback: usa infogda_etiqueta si orderId y payload.id están vacíos', function () {
    fakeGdaSuccessResponse();

    $payload = gabinetePayload();
    unset($payload['id']);

    $action = app(GetGDAResultsAction::class);

    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('resolvePayloadId');
    $method->setAccessible(true);

    $resolved = $method->invoke($action, '', $payload);

    expect($resolved)->toBe('GZ0L000515');
});

it('gabinete: el request a GDA no contiene GDA_menssage', function () {
    fakeGdaSuccessResponse();

    $payload = gabinetePayload();
    $payload['GDA_menssage'] = ['acuse' => 'test', 'codeHttp' => 200];

    $action = app(GetGDAResultsAction::class);
    $action('GZ0L000515', $payload);

    Http::assertSent(function ($request) {
        return ! array_key_exists('GDA_menssage', $request->data());
    });
});
