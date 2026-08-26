<?php

use App\Http\Controllers\LocalEfevooPayTestChargeController;
use App\Http\Controllers\LocalThreeDSHarnessController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'efevoo.mock-gateway'])->group(function () {
    Route::get('/__local/3ds-challenge-harness', [LocalThreeDSHarnessController::class, 'harness'])
        ->name('local.3ds.harness');

    Route::get('/__local/3ds-react-harness', [LocalThreeDSHarnessController::class, 'reactComponentHarness'])
        ->name('local.3ds.react-harness');

    Route::get('/__local/3ds-fake-acs/observation/{harnessId}', [LocalThreeDSHarnessController::class, 'observation'])
        ->name('local.3ds.observation');
});

Route::post('/__local/3ds-fake-acs', [LocalThreeDSHarnessController::class, 'fakeAcs'])
    ->middleware('efevoo.mock-gateway')
    ->name('local.3ds.fake-acs');

Route::middleware(['auth'])->group(function () {
    Route::post('/__local/efevoo/isolated-token-charge', [LocalEfevooPayTestChargeController::class, 'charge'])
        ->name('local.efevoo.isolated-token-charge');
});
