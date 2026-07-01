<?php

use App\Actions\Odessa\GetOdessaPrivateTokenAction;
use App\Actions\Odessa\GetUserDataAction;
use App\DTOs\OdessaUserData;
use App\Exceptions\OdessaGetUserDataFailedException;
use Illuminate\Support\Facades\Http;

test('fetchWithToken maps UserData response to DTOs', function () {
    config(['services.odessa.url' => 'https://odessa.test/']);

    Http::fake([
        'https://odessa.test/getUserData' => Http::response([
            'response' => [
                'intError' => 0,
                'chrMessage' => '',
                'UserData' => [
                    [
                        'AsociacionId' => 1,
                        'EmpresaId' => 11,
                        'SocioId' => 8016,
                        'PlantaId' => 14,
                        'Nombre' => 'Alvaro',
                        'Paterno' => 'Aguillon',
                        'Materno' => 'Escamilla',
                        'TipoTrab' => 'E',
                        'TipoPago' => 'QUI',
                        'IdOdessa' => 12345678,
                        'IdExterno' => 12345,
                        'FormaPago' => '1',
                    ],
                ],
            ],
        ]),
    ]);

    $action = new GetUserDataAction(app(GetOdessaPrivateTokenAction::class));
    $result = $action->fetchWithToken('session-token');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(OdessaUserData::class)
        ->and($result[0]->nombre)->toBe('Alvaro')
        ->and($result[0]->idOdessa)->toBe(12345678);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://odessa.test/getUserData'
            && $request->hasHeader('Authorization', 'Bearer session-token');
    });
});

test('fetchWithToken maps QA response when response is a list envelope', function () {
    config(['services.odessa.url' => 'https://odessa.test/']);

    Http::fake([
        'https://odessa.test/getUserData' => Http::response([
            'response' => [
                [
                    'intError' => 0,
                    'chrMessage' => '',
                    'UserData' => [
                        [
                            'AsociacionId' => 172,
                            'EmpresaId' => 5001,
                            'SocioId' => 156,
                            'PlantaId' => 8823,
                            'ClienteId' => 319,
                            'Empresa' => 'EMP-5001',
                            'Nombre' => 'Maricela',
                            'Paterno' => 'Alcantara',
                            'Materno' => 'Zapata',
                            'TipoTrab' => '',
                            'TipoPago' => 'QUI',
                            'IdOdessa' => 26719,
                            'IdExterno' => 10,
                            'FormaPago' => '1',
                            'AutenticacionSSO' => false,
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $action = new GetUserDataAction(app(GetOdessaPrivateTokenAction::class));
    $result = $action->fetchWithToken('session-token');

    expect($result)->toHaveCount(1)
        ->and($result[0]->nombre)->toBe('Maricela')
        ->and($result[0]->idOdessa)->toBe(26719)
        ->and($result[0]->clienteId)->toBe(319)
        ->and($result[0]->empresa)->toBe('EMP-5001')
        ->and($result[0]->autenticacionSso)->toBeFalse();
});

test('fetchWithToken throws when intError is not zero', function () {
    config(['services.odessa.url' => 'https://odessa.test/']);

    Http::fake([
        'https://odessa.test/getUserData' => Http::response([
            'response' => [
                'intError' => 1,
                'chrMessage' => 'Error',
                'UserData' => [],
            ],
        ]),
    ]);

    $action = new GetUserDataAction(app(GetOdessaPrivateTokenAction::class));

    $action->fetchWithToken('session-token');
})->throws(OdessaGetUserDataFailedException::class);
