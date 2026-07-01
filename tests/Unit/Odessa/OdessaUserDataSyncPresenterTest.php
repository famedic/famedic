<?php

use App\Actions\Odessa\SyncOdessaUserDataAction;
use App\DTOs\OdessaUserData;
use App\DTOs\SyncOdessaUserDataResult;
use App\Http\Controllers\Admin\OdessaCustomerSyncController;
use App\Models\OdessaAfiliateAccount;
use App\Support\Odessa\OdessaUserDataSyncPresenter;

test('presenter builds diff rows with update status', function () {
    $account = new OdessaAfiliateAccount([
        'odessa_identifier' => '26719',
        'client_id' => null,
        'empresa' => null,
        'nombre' => 'Anterior',
        'planta_id' => '100',
        'partner_identifier' => '50',
    ]);
    $account->id = 10;
    $account->exists = true;

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

    $result = new SyncOdessaUserDataResult(
        account: $account,
        userData: $userData,
        previousAttributes: [
            'client_id' => null,
            'empresa' => null,
            'nombre' => 'Anterior',
            'planta_id' => '100',
            'partner_identifier' => '50',
        ],
        newAttributes: [
            'client_id' => '319',
            'empresa' => 'EMP-5001',
            'nombre' => 'Maricela',
            'planta_id' => '8823',
            'partner_identifier' => '156',
        ],
        persisted: false,
    );

    $payload = OdessaUserDataSyncPresenter::fromResult($result);

    expect($payload['hasChanges'])->toBeTrue()
        ->and($payload['diff'])->toHaveCount(5)
        ->and(collect($payload['diff'])->firstWhere('attribute', 'nombre')['status'])->toBe('update')
        ->and(collect($payload['diff'])->firstWhere('attribute', 'planta_id')['status'])->toBe('update')
        ->and($payload['userData']['idOdessa'])->toBe(26719)
        ->and($payload['userData']['clienteId'])->toBe(319);
});

test('previewForCustomer returns null for non odessa customers', function () {
    $customer = new \App\Models\Customer();
    $customer->id = 1;
    $customer->customerable_type = \App\Models\RegularAccount::class;

    $preview = OdessaCustomerSyncController::previewForCustomer(request(), $customer);

    expect($preview)->toBeNull();
});
