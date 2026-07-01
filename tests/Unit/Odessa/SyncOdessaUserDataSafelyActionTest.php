<?php

use App\Actions\Odessa\SyncOdessaUserDataAction;
use App\Actions\Odessa\SyncOdessaUserDataSafelyAction;
use App\Exceptions\OdessaGetUserDataFailedException;
use App\Exceptions\OdessaUserDataSyncMismatchException;
use App\Models\OdessaAfiliateAccount;
use Illuminate\Support\Facades\Log;

function makeOdessaAccountForSafeSync(): OdessaAfiliateAccount
{
    $account = new OdessaAfiliateAccount([
        'odessa_identifier' => '26719',
    ]);
    $account->id = 10;
    $account->exists = true;

    return $account;
}

test('safe sync completes silently on success', function () {
    $account = makeOdessaAccountForSafeSync();

    $this->mock(SyncOdessaUserDataAction::class, function ($mock) use ($account) {
        $mock->shouldReceive('__invoke')->once()->with($account);
    });

    Log::spy();

    app(SyncOdessaUserDataSafelyAction::class)($account);

    Log::shouldNotHaveReceived('warning');
});

test('safe sync logs warning for socio inactivo without throwing', function () {
    $account = makeOdessaAccountForSafeSync();

    $this->mock(SyncOdessaUserDataAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andThrow(new OdessaGetUserDataFailedException(json_encode([
            'response' => [
                'errorCode' => 1,
                'message' => 'Socio inactivo.',
            ],
        ])));
    });

    Log::spy();

    app(SyncOdessaUserDataSafelyAction::class)($account);

    Log::shouldHaveReceived('warning')->once()->with('ODESSA_USER_DATA_SYNC_FAILED', [
        'odessa_afiliate_account_id' => 10,
        'odessa_identifier' => '26719',
        'error_type' => 'api_error',
        'reason' => 'Socio inactivo.',
    ]);
});

test('safe sync logs mismatch without throwing', function () {
    $account = makeOdessaAccountForSafeSync();

    $this->mock(SyncOdessaUserDataAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andThrow(new OdessaUserDataSyncMismatchException('IdExterno no coincide.'));
    });

    Log::spy();

    app(SyncOdessaUserDataSafelyAction::class)($account);

    Log::shouldHaveReceived('warning')->once()->with('ODESSA_USER_DATA_SYNC_FAILED', Mockery::on(
        fn (array $context) => $context['error_type'] === 'mismatch'
            && $context['reason'] === 'IdExterno no coincide.'
    ));
});

test('safe sync logs generic errors without throwing', function () {
    $account = makeOdessaAccountForSafeSync();

    $this->mock(SyncOdessaUserDataAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('Connection timed out'));
    });

    Log::spy();

    app(SyncOdessaUserDataSafelyAction::class)($account);

    Log::shouldHaveReceived('warning')->once()->with('ODESSA_USER_DATA_SYNC_FAILED', Mockery::on(
        fn (array $context) => $context['error_type'] === 'error'
            && $context['reason'] === 'Connection timed out'
    ));
});
