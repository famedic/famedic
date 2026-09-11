<?php

use App\Actions\Admin\MurguiaMonitorSingleCustomerAction;
use App\Models\Customer;
use App\Models\OdessaAfiliateAccount;
use App\Models\OdessaAfiliatedCompany;
use App\Models\OdessaReconciliationItem;
use App\Models\OdessaReconciliationItemAction;
use App\Models\OdessaReconciliationRun;
use App\Models\RegularAccount;
use App\Models\User;
use App\Services\Odessa\Reconciliation\OdessaReconciliationActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! Schema::hasTable('odessa_reconciliation_runs') || ! Schema::hasTable('odessa_reconciliation_item_actions')) {
        $this->markTestSkipped('Odessa reconciliation action tables are not available on this test connection.');
    }

    config(['famedic.odessa_reconciliation_actions.enabled' => true]);
});

it('updates an email with audit and keeps the snapshot immutable', function () {
    $beforeEmail = testEmail('before');
    $afterEmail = testEmail('after');

    [$run, $item, $user] = reconciliationItem([
        'email' => $beforeEmail,
        'email_verified_at' => now(),
    ], [
        'email_excel' => ' '.mb_strtoupper($afterEmail).' ',
        'email_db' => $beforeEmail,
        'data_quality_flags_json' => ['EMAIL_DIFFERENT'],
    ]);

    $service = actionService();
    $preview = $service->preview($run, $item, 'update-email');
    $result = $service->execute($run, $item, $user, 'update-email', 'Correo confirmado contra padrón ODESSA agosto 2026.', 'CONFIRMAR', $preview['token']);

    expect($result['ok'])->toBeTrue()
        ->and($user->fresh()->email)->toBe($afterEmail)
        ->and($user->fresh()->email_verified_at)->toBeNull()
        ->and($item->fresh()->email_db)->toBe($beforeEmail)
        ->and($item->fresh()->resolved_flags_json)->toBe(['EMAIL_DIFFERENT']);

    $action = OdessaReconciliationItemAction::sole();
    expect($action->action_type)->toBe(OdessaReconciliationItemAction::TYPE_UPDATE_EMAIL)
        ->and($action->status)->toBe(OdessaReconciliationItemAction::STATUS_COMPLETED)
        ->and($action->before_json['email'])->toBe($beforeEmail)
        ->and($action->after_json['email'])->toBe($afterEmail)
        ->and($action->performed_by)->toBe($user->id)
        ->and($action->reason)->toBe('Correo confirmado contra padrón ODESSA agosto 2026.');
});

it('blocks email update when the proposed email is occupied', function () {
    $occupied = testEmail('occupied');

    [$run, $item] = reconciliationItem(['email' => testEmail('actual')], [
        'email_excel' => $occupied,
    ]);
    User::factory()->create(['email' => $occupied]);

    $preview = actionService()->preview($run, $item, 'update-email');

    expect($preview['allowed'])->toBeFalse()
        ->and($preview['blocked_reason_code'])->toBe('EMAIL_ALREADY_IN_USE');
});

it('blocks corrective actions when identity is probable', function () {
    [$run, $item] = reconciliationItem(['email' => testEmail('actual')], [
        'email_excel' => testEmail('new'),
        'identity_status' => 'PROBABLE',
    ]);

    $preview = actionService()->preview($run, $item, 'update-email');

    expect($preview['allowed'])->toBeFalse()
        ->and($preview['blocked_reason_code'])->toBe('IDENTITY_NOT_CONFIRMED');
});

it('blocks stale email previews when the current email changed', function () {
    $changed = testEmail('changed');

    [$run, $item, $user] = reconciliationItem(['email' => testEmail('actual')], [
        'email_excel' => testEmail('new'),
    ]);
    $service = actionService();
    $preview = $service->preview($run, $item, 'update-email');

    $user->update(['email' => $changed]);
    $result = $service->execute($run, $item, $user, 'update-email', 'Motivo suficiente', 'CONFIRMAR', $preview['token']);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('RESOURCE_CHANGED')
        ->and($user->fresh()->email)->toBe($changed);
});

it('returns already applied for a repeated email correction without changing the snapshot', function () {
    $beforeEmail = testEmail('actual');
    $afterEmail = testEmail('new');

    [$run, $item, $user] = reconciliationItem(['email' => $beforeEmail], [
        'email_excel' => $afterEmail,
        'email_db' => $beforeEmail,
    ]);
    $service = actionService();
    $preview = $service->preview($run, $item, 'update-email');
    $service->execute($run, $item, $user, 'update-email', 'Primer cambio', 'CONFIRMAR', $preview['token']);

    $secondPreview = $service->preview($run, $item->fresh(), 'update-email');
    $result = $service->execute($run, $item->fresh(), $user, 'update-email', 'Segundo intento auditado', 'CONFIRMAR', $secondPreview['token']);

    expect($result['ok'])->toBeTrue()
        ->and($result['code'])->toBe('ALREADY_APPLIED')
        ->and($user->fresh()->email)->toBe($afterEmail)
        ->and($item->fresh()->email_db)->toBe($beforeEmail)
        ->and(OdessaReconciliationItemAction::count())->toBe(2);
});

it('links an existing free Odessa account to a customer', function () {
    $odessaId = (string) random_int(100000, 999999);
    $companyId = (string) random_int(10000, 99999);
    $partnerId = (string) random_int(1000, 9999);

    [$run, $item, $user, $customer] = reconciliationItem(['email' => testEmail('actual')], [
        'odessa_id_excel' => $odessaId,
        'company_excel' => $companyId,
        'employee_excel' => $partnerId,
        'odessa_account_id' => null,
    ]);
    $company = OdessaAfiliatedCompany::factory()->create(['odessa_identifier' => $companyId]);
    $account = OdessaAfiliateAccount::factory()->create([
        'odessa_identifier' => $odessaId,
        'partner_identifier' => $partnerId,
        'odessa_afiliated_company_id' => $company->id,
    ]);
    $oldAccountId = $customer->customerable_id;

    $service = actionService();
    $preview = $service->preview($run, $item, 'link-odessa-account');
    $result = $service->execute($run, $item, $user, 'link-odessa-account', 'Relación confirmada contra padrón ODESSA.', 'CONFIRMAR', $preview['token']);

    expect($result['ok'])->toBeTrue()
        ->and($customer->fresh()->customerable_type)->toBe(OdessaAfiliateAccount::class)
        ->and($customer->fresh()->customerable_id)->toBe($account->id)
        ->and(RegularAccount::withTrashed()->find($oldAccountId)->trashed())->toBeTrue()
        ->and($item->fresh()->odessa_account_id)->toBeNull();
});

it('blocks Odessa linking when the account belongs to another customer', function () {
    $odessaId = (string) random_int(100000, 999999);
    $companyId = (string) random_int(10000, 99999);
    $partnerId = (string) random_int(1000, 9999);

    [$run, $item] = reconciliationItem(['email' => testEmail('actual')], [
        'odessa_id_excel' => $odessaId,
        'company_excel' => $companyId,
        'employee_excel' => $partnerId,
    ]);
    $company = OdessaAfiliatedCompany::factory()->create(['odessa_identifier' => $companyId]);
    $account = OdessaAfiliateAccount::factory()->create([
        'odessa_identifier' => $odessaId,
        'partner_identifier' => $partnerId,
        'odessa_afiliated_company_id' => $company->id,
    ]);
    Customer::factory()->for(User::factory(), 'user')->for($account, 'customerable')->create();

    $preview = actionService()->preview($run, $item, 'link-odessa-account');

    expect($preview['allowed'])->toBeFalse()
        ->and($preview['blocked_reason_code'])->toBe('ODESSA_ACCOUNT_CONFLICT');
});

it('retries Murguia sync through the existing domain action', function () {
    [$run, $item, $user, $customer] = reconciliationItem(['email' => testEmail('actual')]);

    $murguia = Mockery::mock(MurguiaMonitorSingleCustomerAction::class, function (MockInterface $mock) use ($customer, $user) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->with($customer->id, 'activate', $user->id)
            ->andReturn(['ok' => true, 'message' => 'Licencia institucional creada y sincronizada con Murguía.']);
    });

    $service = new OdessaReconciliationActionService($murguia);
    $preview = $service->preview($run, $item, 'retry-murguia-sync');
    $result = $service->execute($run, $item, $user, 'retry-murguia-sync', 'Reintento solicitado por conciliación.', 'CONFIRMAR', $preview['token']);

    expect($result['ok'])->toBeTrue()
        ->and(OdessaReconciliationItemAction::sole()->status)->toBe(OdessaReconciliationItemAction::STATUS_COMPLETED);
});

it('records Murguia external failures as failed actions', function () {
    [$run, $item, $user, $customer] = reconciliationItem(['email' => testEmail('actual')]);

    $murguia = Mockery::mock(MurguiaMonitorSingleCustomerAction::class, function (MockInterface $mock) use ($customer, $user) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->with($customer->id, 'activate', $user->id)
            ->andReturn(['ok' => false, 'message' => 'Murguía rechazó la solicitud.']);
    });

    $service = new OdessaReconciliationActionService($murguia);
    $preview = $service->preview($run, $item, 'retry-murguia-sync');
    $result = $service->execute($run, $item, $user, 'retry-murguia-sync', 'Reintento solicitado por conciliación.', 'CONFIRMAR', $preview['token']);

    expect($result['ok'])->toBeFalse()
        ->and(OdessaReconciliationItemAction::sole()->status)->toBe(OdessaReconciliationItemAction::STATUS_FAILED)
        ->and(OdessaReconciliationItemAction::sole()->error_message)->toBe('Murguía rechazó la solicitud.');
});

it('blocks Murguia retry without customer', function () {
    [$run, $item] = reconciliationItem(['email' => testEmail('actual')], [
        'customer_id' => null,
    ]);

    $preview = actionService()->preview($run, $item, 'retry-murguia-sync');

    expect($preview['allowed'])->toBeFalse()
        ->and($preview['blocked_reason_code'])->toBe('CUSTOMER_NOT_FOUND');
});

it('allows Murguia activation only for ODESSA alta rows', function () {
    [$run, $item, $user, $customer] = reconciliationItem(['email' => testEmail('actual')], [
        'source_action' => 'ALTA',
        'source_action_status' => OdessaReconciliationItem::ACTION_STATUS_PENDING_ACTIVATION,
        'murguia_status' => 'FAMEDIC_NO_MURGUIA',
    ]);

    $murguia = Mockery::mock(MurguiaMonitorSingleCustomerAction::class, function (MockInterface $mock) use ($customer, $user) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->with($customer->id, 'activate', $user->id)
            ->andReturn(['ok' => true, 'message' => 'Licencia institucional creada y sincronizada con Murguía.']);
    });

    $service = new OdessaReconciliationActionService($murguia);
    $preview = $service->preview($run, $item, 'activate-murguia-membership');
    $result = $service->execute($run, $item, $user, 'activate-murguia-membership', 'Alta solicitada por ODESSA.', 'CONFIRMAR', $preview['token']);

    expect($preview['allowed'])->toBeTrue()
        ->and($result['ok'])->toBeTrue()
        ->and($item->fresh()->source_action_status)->toBe(OdessaReconciliationItem::ACTION_STATUS_ACTIVATED)
        ->and(OdessaReconciliationItemAction::latest('id')->first()->action_type)->toBe(OdessaReconciliationItemAction::TYPE_ACTIVATE_MURGUIA_MEMBERSHIP);
});

it('blocks Murguia deactivation when ODESSA did not request baja', function () {
    [$run, $item] = reconciliationItem(['email' => testEmail('actual')], [
        'source_action' => 'ALTA',
        'source_action_status' => OdessaReconciliationItem::ACTION_STATUS_PENDING_ACTIVATION,
    ]);

    $preview = actionService()->preview($run, $item, 'deactivate-murguia-membership');

    expect($preview['allowed'])->toBeFalse()
        ->and($preview['blocked_reason_code'])->toBe('SOURCE_ACTION_MISMATCH');
});

it('blocks Murguia alta and baja previews when duplicate risk exists', function () {
    [$run, $item] = reconciliationItem(['email' => testEmail('actual')], [
        'source_action' => 'ALTA',
        'source_action_status' => OdessaReconciliationItem::ACTION_STATUS_PENDING_ACTIVATION,
        'data_quality_flags_json' => ['POSSIBLE_DUPLICATE_PERSON'],
    ]);

    $preview = actionService()->preview($run, $item, 'activate-murguia-membership');

    expect($preview['allowed'])->toBeFalse()
        ->and($preview['blocked_reason_code'])->toBe('POSSIBLE_DUPLICATE');
});

it('returns already applied for Murguia alta when the collaborator is already active', function () {
    [$run, $item] = reconciliationItem(['email' => testEmail('actual')], [
        'source_action' => 'ALTA',
        'source_action_status' => OdessaReconciliationItem::ACTION_STATUS_ALREADY_ACTIVE,
        'murguia_status' => 'FAMEDIC_Y_MURGUIA',
    ]);

    $preview = actionService()->preview($run, $item, 'activate-murguia-membership');

    expect($preview['allowed'])->toBeFalse()
        ->and($preview['blocked_reason_code'])->toBe('ALREADY_APPLIED');
});

it('returns already applied for Murguia baja when the collaborator is already inactive', function () {
    [$run, $item] = reconciliationItem(['email' => testEmail('actual')], [
        'source_action' => 'BAJA',
        'source_action_status' => OdessaReconciliationItem::ACTION_STATUS_ALREADY_INACTIVE,
        'murguia_status' => 'FAMEDIC_NO_MURGUIA',
    ]);

    $preview = actionService()->preview($run, $item, 'deactivate-murguia-membership');

    expect($preview['allowed'])->toBeFalse()
        ->and($preview['blocked_reason_code'])->toBe('ALREADY_APPLIED');
});

it('sends Murguia deactivation through the existing domain action for ODESSA baja rows', function () {
    [$run, $item, $user, $customer] = reconciliationItem(['email' => testEmail('actual')], [
        'source_action' => 'BAJA',
        'source_action_status' => OdessaReconciliationItem::ACTION_STATUS_PENDING_DEACTIVATION,
        'murguia_status' => 'FAMEDIC_Y_MURGUIA',
    ]);

    $murguia = Mockery::mock(MurguiaMonitorSingleCustomerAction::class, function (MockInterface $mock) use ($customer, $user) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->with($customer->id, 'deactivate', $user->id)
            ->andReturn(['ok' => true, 'message' => 'Desactivación enviada a Murguía (estatus inactivo).']);
    });

    $service = new OdessaReconciliationActionService($murguia);
    $preview = $service->preview($run, $item, 'deactivate-murguia-membership');
    $result = $service->execute($run, $item, $user, 'deactivate-murguia-membership', 'Baja solicitada por ODESSA.', 'CONFIRMAR', $preview['token']);

    expect($preview['allowed'])->toBeTrue()
        ->and($result['ok'])->toBeTrue()
        ->and($item->fresh()->source_action_status)->toBe(OdessaReconciliationItem::ACTION_STATUS_DEACTIVATED)
        ->and(OdessaReconciliationItemAction::latest('id')->first()->action_type)->toBe(OdessaReconciliationItemAction::TYPE_DEACTIVATE_MURGUIA_MEMBERSHIP);
});

function actionService(): OdessaReconciliationActionService
{
    return new OdessaReconciliationActionService(Mockery::mock(MurguiaMonitorSingleCustomerAction::class));
}

function testEmail(string $prefix): string
{
    return $prefix.'-'.str_replace('.', '-', uniqid('', true)).'@example.test';
}

function reconciliationItem(array $userAttributes = [], array $itemAttributes = [], array $customerAttributes = []): array
{
    $actor = User::factory()->create(array_merge([
        'email' => 'admin@famedic.test',
    ], $userAttributes));

    $customer = Customer::factory()
        ->for($actor, 'user')
        ->withRegularAccount()
        ->create(array_merge([
            'medical_attention_identifier' => (string) random_int(1000000000, 9999999999),
        ], $customerAttributes));

    $run = OdessaReconciliationRun::create([
        'status' => OdessaReconciliationRun::STATUS_COMPLETED,
        'uploaded_by' => $actor->id,
        'source_filename' => 'source.xlsx',
        'source_path' => 'source.xlsx',
    ]);

    $item = OdessaReconciliationItem::create(array_merge([
        'run_id' => $run->id,
        'canonical_id' => 'G0001',
        'source_sheet' => 'ODESSA',
        'source_row' => 2,
        'source_action' => 'NONE',
        'source_action_status' => OdessaReconciliationItem::ACTION_STATUS_NO_ACTION,
        'company_excel' => '5000',
        'employee_excel' => '1214',
        'odessa_id_excel' => '69285',
        'name_excel' => 'Oswaldo Isaac Santiago Ramirez',
        'email_excel' => 'osantiago@odessa.com.mx',
        'match_type' => 'MATCH_CONFIRMED_ODESSA_ID',
        'match_confidence' => 'alta',
        'identity_status' => 'CONFIRMED',
        'account_status' => 'ODESSA_ACTIVE',
        'membership_status' => 'ACTIVE',
        'murguia_status' => 'FAMEDIC_NO_MURGUIA',
        'primary_status' => 'REGISTRO_COMPLETO',
        'data_quality_flags_json' => [],
        'user_id' => $actor->id,
        'customer_id' => $customer->id,
        'odessa_account_id' => 1321,
        'odessa_id_db' => '69285',
        'company_external_db' => '5000',
        'partner_db' => '1214',
        'name_db' => 'Oswaldo Isaac Santiago Ramirez',
        'email_db' => $actor->email,
        'medical_attention_identifier' => $customer->medical_attention_identifier,
        'subscription_status' => 'ACTIVE',
        'evidence_json' => ['Excel ID ODESSA 69285 = DB ID ODESSA 69285'],
        'snapshot_json' => ['email_db' => $actor->email],
        'review_status' => OdessaReconciliationItem::REVIEW_NOT_APPLICABLE,
    ], $itemAttributes));

    return [$run, $item, $actor, $customer];
}
