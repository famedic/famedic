<?php

use App\Actions\Odessa\GetUserDataAction;
use App\DTOs\OdessaUserData;
use App\Exceptions\OdessaGetUserDataFailedException;
use App\Models\OdessaAfiliateAccount;
use Illuminate\Support\Facades\Artisan;

test('odessa:get-user-data muestra datos mapeados de una cuenta existente', function () {
    $account = OdessaAfiliateAccount::factory()->create([
        'odessa_identifier' => '12345678',
    ]);

    $userData = new OdessaUserData(
        asociacionId: 1,
        empresaId: 11,
        socioId: 8016,
        plantaId: 14,
        nombre: 'Alvaro',
        paterno: 'Aguillon',
        materno: 'Escamilla',
        tipoTrab: 'E',
        tipoPago: 'QUI',
        idOdessa: 12345678,
        idExterno: $account->id,
        formaPago: '1',
    );

    $this->mock(GetUserDataAction::class, function ($mock) use ($account, $userData) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->withArgs(fn (OdessaAfiliateAccount $arg) => $arg->is($account))
            ->andReturn([$userData]);
    });

    $exitCode = Artisan::call('odessa:get-user-data', [
        'odessa_afiliate_account_id' => $account->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Alvaro')
        ->and($output)->toContain('12345678')
        ->and($output)->toContain('coincide con');
});

test('odessa:get-user-data falla si la cuenta no existe', function () {
    $exitCode = Artisan::call('odessa:get-user-data', [
        'odessa_afiliate_account_id' => 999999,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('No se encontró OdessaAfiliateAccount');
});

test('odessa:get-user-data muestra error de Odessa', function () {
    $account = OdessaAfiliateAccount::factory()->create();

    $this->mock(GetUserDataAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andThrow(new OdessaGetUserDataFailedException(json_encode([
                'response' => [
                    'intError' => 1,
                    'chrMessage' => 'Error de prueba',
                ],
            ])));
    });

    $exitCode = Artisan::call('odessa:get-user-data', [
        'odessa_afiliate_account_id' => $account->id,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Error de prueba');
});
