<?php

use App\Enums\LaboratoryGdaFailureOperation;
use App\Exceptions\GdaOrderResultUncertainException;
use App\Models\Administrator;
use App\Models\LaboratoryGdaFailureLog;
use App\Models\LaboratoryPurchase;
use App\Models\Permission;
use App\Models\User;
use App\Services\Laboratory\LaboratoryGdaFailureLogService;

test('gda failure log service persists uncertain exception', function () {
    $purchase = LaboratoryPurchase::factory()->create();

    $log = app(LaboratoryGdaFailureLogService::class)->recordUncertain(
        GdaOrderResultUncertainException::forResponse(
            reason: 'gda_missing_required_identifiers',
            laboratoryPurchaseId: $purchase->id,
            httpStatus: 201,
            responseSummary: [
                'gda_code_http' => 400,
                'gda_mensaje' => 'error',
                'gda_description' => 'Los estudios de la orden no estan en convenio',
            ],
        ),
        LaboratoryGdaFailureOperation::AdminReplace,
        purchase: $purchase->fresh(['customer.user', 'laboratoryPurchaseItems']),
        sourceLaboratoryPurchaseId: $purchase->id,
    );

    expect($log)->toBeInstanceOf(LaboratoryGdaFailureLog::class)
        ->and($log->operation)->toBe(LaboratoryGdaFailureOperation::AdminReplace)
        ->and($log->gda_description)->toBe('Los estudios de la orden no estan en convenio')
        ->and($log->laboratory_purchase_id)->toBe($purchase->id);
});

test('admin with laboratory purchases manage permission can view gda failure logs', function () {
    Permission::firstOrCreate([
        'name' => 'laboratory-purchases.manage',
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create();
    Administrator::factory()->create(['user_id' => $user->id])->givePermissionTo('laboratory-purchases.manage');

    LaboratoryGdaFailureLog::query()->create([
        'operation' => LaboratoryGdaFailureOperation::Checkout,
        'failure_reason' => 'gda_missing_required_identifiers',
        'message' => 'Prueba de error GDA',
    ]);

    $this->actingAs($user)
        ->get(route('admin.laboratory-gda-failure-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/LaboratoryGdaFailureLogs')
            ->has('logs.data', 1));
});
