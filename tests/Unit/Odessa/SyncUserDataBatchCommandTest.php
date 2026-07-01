<?php

use App\Console\Commands\Odessa\SyncUserDataBatchCommand;

test('resolveExitCode returns success when allow-partial and some accounts succeeded', function () {
    $command = new SyncUserDataBatchCommand;

    $exitCode = (new ReflectionMethod($command, 'resolveExitCode'))
        ->invoke($command, [
            'updated' => 2,
            'unchanged' => 0,
            'dry_run_changes' => 0,
        ], [['id' => 38]], true);

    expect($exitCode)->toBe(0);
});

test('resolveExitCode returns failure when allow-partial but all accounts failed', function () {
    $command = new SyncUserDataBatchCommand;

    $exitCode = (new ReflectionMethod($command, 'resolveExitCode'))
        ->invoke($command, [
            'updated' => 0,
            'unchanged' => 0,
            'dry_run_changes' => 0,
        ], [['id' => 38], ['id' => 51]], true);

    expect($exitCode)->toBe(1);
});

test('resolveExitCode returns failure on any error without allow-partial', function () {
    $command = new SyncUserDataBatchCommand;

    $exitCode = (new ReflectionMethod($command, 'resolveExitCode'))
        ->invoke($command, [
            'updated' => 2,
            'unchanged' => 0,
            'dry_run_changes' => 0,
        ], [['id' => 38]], false);

    expect($exitCode)->toBe(1);
});
