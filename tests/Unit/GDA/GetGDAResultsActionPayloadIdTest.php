<?php

use App\Actions\Laboratories\GetGDAResultsAction;
use App\Actions\Laboratories\ResolveConsultableGdaId;
use App\Exceptions\GdaConsultIdNotResolvableException;
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

function gdaResultsGabinetePayload(string $orderId = 'GZ0L000515'): array
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

function gdaResultsLaboratorioPayload(string $orderId = '24642071', string $requisitionValue = 'HD0L001392'): array
{
    return [
        'id' => $orderId,
        'header' => ['marca' => 5, 'lineanegocio' => '3'],
        'requisition' => [
            'value' => $requisitionValue,
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

function resolveConsultableGdaId(?string $orderId, array $payload): array
{
    return app(ResolveConsultableGdaId::class)($orderId, $payload);
}

it('gabinete: usa gda_order_id cuando cumple patrón consultable', function () {
    fakeGdaSuccessResponse();

    $action = app(GetGDAResultsAction::class);
    $action('GZ0L000423', gdaResultsGabinetePayload('GZ0L000423'));

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['id'] === 'GZ0L000423'
            && $body['requisition']['value'] === '2066';
    });
});

it('gabinete: no reemplaza id con requisition.value numérico', function () {
    fakeGdaSuccessResponse();

    $action = app(GetGDAResultsAction::class);
    $action('GZ0L000515', gdaResultsGabinetePayload());

    Http::assertSent(function ($request) {
        return $request->data()['id'] !== '2066';
    });
});

it('laboratorio normal Swisslab: no usa orderId numérico si no cumple patrón', function () {
    fakeGdaSuccessResponse();

    $action = app(GetGDAResultsAction::class);
    $action('24678619', gdaResultsLaboratorioPayload('24678619', 'HD0L001402'));

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['id'] === 'HD0L001402'
            && $body['id'] !== '24678619'
            && $body['requisition']['value'] === 'HD0L001402';
    });
});

it('laboratorio normal: usa requisition.value cuando payload.id es numérico', function () {
    fakeGdaSuccessResponse();

    $action = app(GetGDAResultsAction::class);
    $action('24642071', gdaResultsLaboratorioPayload());

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['id'] === 'HD0L001392'
            && $body['id'] !== '24642071';
    });
});

it('laboratorio normal: usa infogda_etiqueta si cumple patrón y requisition.value no', function () {
    fakeGdaSuccessResponse();

    $payload = gdaResultsLaboratorioPayload('24642071', '1888');
    $payload['code']['coding'][0]['infogda_muestras'] = [[
        'infogda_etiqueta' => 'HD0L001392',
    ]];

    $action = app(GetGDAResultsAction::class);
    $action('24642071', $payload);

    Http::assertSent(function ($request) {
        return $request->data()['id'] === 'HD0L001392';
    });

    expect(resolveConsultableGdaId('24642071', $payload))
        ->toMatchArray(['id' => 'HD0L001392', 'source' => 'infogda_etiqueta']);
});

it('laboratorio normal: ignora infogda_etiqueta con sufijo y usa requisition.value', function () {
    fakeGdaSuccessResponse();

    $payload = gdaResultsLaboratorioPayload('24642071', 'HD0L001392');
    $payload['code']['coding'][0]['infogda_muestras'] = [[
        'infogda_etiqueta' => 'HD0L001392OQ',
    ]];

    $action = app(GetGDAResultsAction::class);
    $action('24642071', $payload);

    Http::assertSent(function ($request) {
        return $request->data()['id'] === 'HD0L001392';
    });

    expect(resolveConsultableGdaId('24642071', $payload))
        ->toMatchArray(['id' => 'HD0L001392', 'source' => 'requisition.value']);
});

it('lanza excepción controlada si ningún candidato cumple patrón consultable', function () {
    $payload = [
        'id' => '24678619',
        'requisition' => ['value' => '1888'],
    ];

    expect(fn () => resolveConsultableGdaId('24678619', $payload))
        ->toThrow(GdaConsultIdNotResolvableException::class);
});

it('loguea payload_id, requested_order_id y resolved_consult_id_source antes de llamar a GDA', function () {
    fakeGdaSuccessResponse();
    Log::spy();

    $action = app(GetGDAResultsAction::class);
    $action('24678619', gdaResultsLaboratorioPayload('24678619', 'HD0L001402'));

    Log::shouldHaveReceived('info')
        ->with(
            'GDA results consult id resolved',
            \Mockery::on(fn (array $ctx) => $ctx['requested_order_id'] === '24678619'
                && $ctx['payload_id_original'] === '24678619'
                && $ctx['requisition_value'] === 'HD0L001402'
                && $ctx['resolved_consult_id'] === 'HD0L001402'
                && $ctx['resolved_consult_id_source'] === 'requisition.value')
        );

    Log::shouldHaveReceived('info')
        ->with(
            'GDA results consult payload prepared',
            \Mockery::on(fn (array $ctx) => $ctx['requested_order_id'] === '24678619'
                && $ctx['payload_id'] === 'HD0L001402'
                && $ctx['requisition_value'] === 'HD0L001402'
                && $ctx['is_payload_id_same_as_requested_order_id'] === false
                && $ctx['resolved_consult_id_source'] === 'requisition.value')
        );
});

it('gabinete: loguea resolved_consult_id_source como gda_order_id', function () {
    fakeGdaSuccessResponse();
    Log::spy();

    $action = app(GetGDAResultsAction::class);
    $action('GZ0L000423', gdaResultsGabinetePayload('GZ0L000423'));

    Log::shouldHaveReceived('info')
        ->with(
            'GDA results consult payload prepared',
            \Mockery::on(fn (array $ctx) => $ctx['requested_order_id'] === 'GZ0L000423'
                && $ctx['payload_id'] === 'GZ0L000423'
                && $ctx['requisition_value'] === '2066'
                && $ctx['is_payload_id_same_as_requested_order_id'] === true
                && $ctx['resolved_consult_id_source'] === 'gda_order_id')
        );
});

it('fallback: usa payload.id consultable si orderId está vacío', function () {
    expect(resolveConsultableGdaId('', gdaResultsGabinetePayload('GZ0L000515')))
        ->toMatchArray(['id' => 'GZ0L000515', 'source' => 'payload.id']);
});

it('fallback: usa infogda_etiqueta consultable si orderId y payload.id están vacíos', function () {
    $payload = gdaResultsGabinetePayload();
    unset($payload['id']);

    expect(resolveConsultableGdaId('', $payload))
        ->toMatchArray(['id' => 'GZ0L000515', 'source' => 'infogda_etiqueta']);
});

it('gabinete: el request a GDA no contiene GDA_menssage', function () {
    fakeGdaSuccessResponse();

    $payload = gdaResultsGabinetePayload();
    $payload['GDA_menssage'] = ['acuse' => 'test', 'codeHttp' => 200];

    $action = app(GetGDAResultsAction::class);
    $action('GZ0L000515', $payload);

    Http::assertSent(function ($request) {
        return ! array_key_exists('GDA_menssage', $request->data());
    });
});

it('Swisslab: el payload final enviado a GDA no usa el id numérico 24678619', function () {
    fakeGdaSuccessResponse();

    $action = app(GetGDAResultsAction::class);
    $action('24678619', gdaResultsLaboratorioPayload('24678619', 'HD0L001402'));

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['id'] === 'HD0L001402'
            && $body['id'] !== '24678619';
    });
});
