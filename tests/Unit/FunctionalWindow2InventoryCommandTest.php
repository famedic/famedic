<?php

use Symfony\Component\Process\Process;

function runInventoryIdentity(string $metadataPath): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('artisan'), 'functional-window2:inventory', '--no-ansi'],
        base_path(),
        ['AKUBICA_UAT_RELEASE_METADATA_PATH' => $metadataPath],
    );
    $process->setInput(json_encode(['action' => 'identity'], JSON_THROW_ON_ERROR));
    $process->run();

    return $process;
}

test('inventory helper exposes only sanitized release identity from release metadata', function () {
    $path = tempnam(sys_get_temp_dir(), 'fw2-release-');
    file_put_contents($path, json_encode([
        'release' => 'synthetic-release-1',
        'active_sha' => 'f248e3cc86d64464d188900859b8019fcf6dbf20',
    ], JSON_THROW_ON_ERROR));
    $process = runInventoryIdentity($path);
    $output = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    unlink($path);
    expect($process->getExitCode())->toBe(0)
        ->and($output)->toBe([
            'status' => 'verified',
            'release' => 'synthetic-release-1',
            'active_sha' => 'f248e3cc86d64464d188900859b8019fcf6dbf20',
        ]);
});

test('inventory helper fails closed when release metadata is unavailable', function () {
    $process = runInventoryIdentity(sys_get_temp_dir().'/fw2-missing-release-metadata');
    $output = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->not->toBe(0)
        ->and($output)->toBe(['error' => 'RELEASE_METADATA_UNAVAILABLE']);
});
