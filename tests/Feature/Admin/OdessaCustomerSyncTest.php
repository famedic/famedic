<?php

use App\Actions\Odessa\SyncOdessaUserDataAction;
use App\DTOs\OdessaUserData;
use App\DTOs\SyncOdessaUserDataResult;
use App\Http\Controllers\Admin\OdessaCustomerSyncController;
use App\Models\Administrator;
use App\Models\Customer;
use App\Models\OdessaAfiliateAccount;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

function makeCustomerAdminUser(): User
{
    $user = User::factory()->create();
    $administrator = Administrator::factory()->for($user)->create();
    $permission = Permission::firstOrCreate([
        'name' => 'customers.manage',
        'guard_name' => 'web',
    ]);
    $administrator->givePermissionTo($permission);

    return $user->fresh()->load('administrator');
}

function makeOdessaCustomer(): Customer
{
    return Customer::factory()
        ->withOdessaAfiliateAccount()
        ->create();
}

test('admin puede previsualizar sync odessa sin persistir', function () {
    $admin = makeCustomerAdminUser();
    $customer = makeOdessaCustomer();
    $account = $customer->customerable;

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
        idOdessa: (int) $account->odessa_identifier,
        idExterno: $account->id,
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
            'nombre' => null,
            'planta_id' => null,
            'partner_identifier' => (string) $account->partner_identifier,
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

    $this->mock(SyncOdessaUserDataAction::class, function ($mock) use ($result) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->with(Mockery::type(OdessaAfiliateAccount::class), true, false)
            ->andReturn($result);
    });

    $response = $this->actingAs($admin)
        ->post(route('admin.customers.odessa-sync-preview', $customer));

    $response->assertRedirect(route('admin.customers.show', $customer));

    $this->actingAs($admin)
        ->get(route('admin.customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Customer')
            ->where('odessaSyncPreview.customer_id', $customer->id)
            ->where('odessaSyncPreview.hasChanges', true)
            ->where('odessaSyncPreview.newAttributes.nombre', 'Maricela'));

    $account->refresh();
    expect($account->nombre)->toBeNull();
});

test('admin puede aplicar sync odessa tras previsualizar', function () {
    $admin = makeCustomerAdminUser();
    $customer = makeOdessaCustomer();
    $account = $customer->customerable;

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
        idOdessa: (int) $account->odessa_identifier,
        idExterno: $account->id,
        formaPago: '1',
        clienteId: 319,
        empresa: 'EMP-5001',
        autenticacionSso: false,
    );

    $previewResult = new SyncOdessaUserDataResult(
        account: $account,
        userData: $userData,
        previousAttributes: [
            'client_id' => null,
            'empresa' => null,
            'nombre' => null,
            'planta_id' => null,
            'partner_identifier' => (string) $account->partner_identifier,
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

    $applyResult = new SyncOdessaUserDataResult(
        account: $account->fresh(),
        userData: $userData,
        previousAttributes: $previewResult->previousAttributes,
        newAttributes: $previewResult->newAttributes,
        persisted: true,
    );

    $this->mock(SyncOdessaUserDataAction::class, function ($mock) use ($account, $previewResult, $applyResult) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->with(Mockery::type(OdessaAfiliateAccount::class), true, false)
            ->andReturn($previewResult);

        $mock->shouldReceive('__invoke')
            ->once()
            ->with(Mockery::type(OdessaAfiliateAccount::class), false, false)
            ->andReturnUsing(function () use ($account, $applyResult) {
                $account->update($applyResult->newAttributes);

                return new SyncOdessaUserDataResult(
                    account: $account->fresh(),
                    userData: $applyResult->userData,
                    previousAttributes: $applyResult->previousAttributes,
                    newAttributes: $applyResult->newAttributes,
                    persisted: true,
                );
            });
    });

    $this->actingAs($admin)
        ->post(route('admin.customers.odessa-sync-preview', $customer))
        ->assertRedirect();

    $this->actingAs($admin)
        ->post(route('admin.customers.odessa-sync', $customer))
        ->assertRedirect(route('admin.customers.show', $customer))
        ->assertSessionHas('success');

    $account->refresh();
    expect($account->nombre)->toBe('Maricela')
        ->and($account->client_id)->toBe('319');
});

test('apply sin preview previa muestra error', function () {
    $admin = makeCustomerAdminUser();
    $customer = makeOdessaCustomer();

    $this->actingAs($admin)
        ->post(route('admin.customers.odessa-sync', $customer))
        ->assertRedirect(route('admin.customers.show', $customer))
        ->assertSessionHas('error');
});

test('cliente no odessa no puede usar preview', function () {
    $admin = makeCustomerAdminUser();
    $customer = Customer::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.customers.odessa-sync-preview', $customer))
        ->assertNotFound();
});
