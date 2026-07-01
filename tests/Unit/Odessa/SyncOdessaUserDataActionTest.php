<?php

use App\Actions\Odessa\GetUserDataAction;
use App\Actions\Odessa\SyncOdessaUserDataAction;
use App\DTOs\OdessaUserData;
use App\Exceptions\OdessaGetUserDataFailedException;
use App\Exceptions\OdessaUserDataSyncMismatchException;
use App\Models\OdessaAfiliateAccount;

function makeOdessaAfiliateAccountForSync(array $attributes = []): OdessaAfiliateAccount
{
    $account = new OdessaAfiliateAccount(array_merge([
        'odessa_identifier' => '26719',
        'client_id' => null,
        'empresa' => null,
        'nombre' => null,
        'planta_id' => null,
        'partner_identifier' => null,
    ], $attributes));

    $account->id = $attributes['id'] ?? 10;
    $account->exists = true;

    return $account;
}

test('sync action persists mapped attributes from getUserData', function () {
    $account = Mockery::mock(makeOdessaAfiliateAccountForSync())->makePartial();
    $account->shouldReceive('update')->once()->with([
        'client_id' => '319',
        'empresa' => 'EMP-5001',
        'nombre' => 'Maricela',
        'planta_id' => '8823',
        'partner_identifier' => '156',
    ])->andReturnTrue();
    $account->shouldReceive('refresh')->once()->andReturnUsing(function () use ($account) {
        $account->client_id = '319';
        $account->empresa = 'EMP-5001';
        $account->nombre = 'Maricela';
        $account->planta_id = '8823';
        $account->partner_identifier = '156';

        return $account;
    });

    $userData = new OdessaUserData(
        asociacionId: 172,
        empresaId: 5001,
        socioId: 156,
        plantaId: 8823,
        nombre: 'Maricela',
        paterno: 'Alcantara',
        materno: 'Zapata',
        tipoTrab: '',
        tipoPago: 'QUI',
        idOdessa: 26719,
        idExterno: 10,
        formaPago: '1',
        clienteId: 319,
        empresa: 'EMP-5001',
        autenticacionSso: false,
    );

    $this->mock(GetUserDataAction::class, function ($mock) use ($userData) {
        $mock->shouldReceive('__invoke')->once()->andReturn([$userData]);
    });

    $result = app(SyncOdessaUserDataAction::class)($account);

    expect($result->persisted)->toBeTrue()
        ->and($result->hasChanges())->toBeTrue()
        ->and($result->account->client_id)->toBe('319')
        ->and($result->account->empresa)->toBe('EMP-5001')
        ->and($result->account->nombre)->toBe('Maricela')
        ->and($result->account->planta_id)->toBe('8823')
        ->and($result->account->partner_identifier)->toBe('156');
});

test('sync action dry-run does not persist changes', function () {
    $account = makeOdessaAfiliateAccountForSync([
        'client_id' => '1',
        'empresa' => 'OLD',
        'nombre' => 'Old',
        'planta_id' => '1',
    ]);

    $userData = new OdessaUserData(
        asociacionId: 172,
        empresaId: 5001,
        socioId: 156,
        plantaId: 8823,
        nombre: 'Maricela',
        paterno: 'Alcantara',
        materno: 'Zapata',
        tipoTrab: '',
        tipoPago: 'QUI',
        idOdessa: 26719,
        idExterno: 10,
        formaPago: '1',
        clienteId: 319,
        empresa: 'EMP-5001',
    );

    $this->mock(GetUserDataAction::class, function ($mock) use ($userData) {
        $mock->shouldReceive('__invoke')->once()->andReturn([$userData]);
    });

    $result = app(SyncOdessaUserDataAction::class)($account, dryRun: true);

    expect($result->persisted)->toBeFalse()
        ->and($result->hasChanges())->toBeTrue()
        ->and($account->empresa)->toBe('OLD');
});

test('sync action rejects mismatched IdOdessa unless forced', function () {
    $account = makeOdessaAfiliateAccountForSync([
        'odessa_identifier' => '99999',
    ]);

    $userData = new OdessaUserData(
        asociacionId: 172,
        empresaId: 5001,
        socioId: 156,
        plantaId: 8823,
        nombre: 'Maricela',
        paterno: 'Alcantara',
        materno: 'Zapata',
        tipoTrab: '',
        tipoPago: 'QUI',
        idOdessa: 26719,
        idExterno: 10,
        formaPago: '1',
    );

    $this->mock(GetUserDataAction::class, function ($mock) use ($userData) {
        $mock->shouldReceive('__invoke')->once()->andReturn([$userData]);
    });

    app(SyncOdessaUserDataAction::class)($account);
})->throws(OdessaUserDataSyncMismatchException::class);

test('sync action wraps getToken failures as OdessaGetUserDataFailedException', function () {
    $account = makeOdessaAfiliateAccountForSync();

    $this->mock(GetUserDataAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andThrow(new \Exception(json_encode([
                'response' => [
                    'errorCode' => 1,
                    'message' => 'Socio inactivo.',
                    'token' => '',
                ],
            ])));
    });

    try {
        app(SyncOdessaUserDataAction::class)($account);
        expect(false)->toBeTrue('Expected exception');
    } catch (OdessaGetUserDataFailedException $e) {
        expect($e->getMessage())->toContain('Socio inactivo.');
    }
});

test('sync action allows mismatch when forced', function () {
    $account = Mockery::mock(makeOdessaAfiliateAccountForSync([
        'odessa_identifier' => '99999',
    ]))->makePartial();
    $account->shouldReceive('update')->once()->andReturnTrue();
    $account->shouldReceive('refresh')->once()->andReturnUsing(function () use ($account) {
        $account->nombre = 'Maricela';

        return $account;
    });

    $userData = new OdessaUserData(
        asociacionId: 172,
        empresaId: 5001,
        socioId: 156,
        plantaId: 8823,
        nombre: 'Maricela',
        paterno: 'Alcantara',
        materno: 'Zapata',
        tipoTrab: '',
        tipoPago: 'QUI',
        idOdessa: 26719,
        idExterno: 10,
        formaPago: '1',
        clienteId: 319,
        empresa: 'EMP-5001',
    );

    $this->mock(GetUserDataAction::class, function ($mock) use ($userData) {
        $mock->shouldReceive('__invoke')->once()->andReturn([$userData]);
    });

    $result = app(SyncOdessaUserDataAction::class)($account, force: true);

    expect($result->account->nombre)->toBe('Maricela');
});
