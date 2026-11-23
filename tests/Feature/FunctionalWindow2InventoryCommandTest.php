<?php

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use Symfony\Component\Process\Process;

function runInventoryVerify(string $metadataPath, array $request): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('artisan'), 'functional-window2:inventory', '--no-ansi'],
        base_path(),
        ['AKUBICA_UAT_RELEASE_METADATA_PATH' => $metadataPath],
    );
    $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
    $process->run();

    return $process;
}

test('inventory helper verifies only the dedicated namespace resource', function () {
    $sha = 'f248e3cc86d64464d188900859b8019fcf6dbf20';
    $metadataPath = tempnam(sys_get_temp_dir(), 'fw2-release-');
    file_put_contents($metadataPath, json_encode(['release' => 'synthetic-release-1', 'active_sha' => $sha], JSON_THROW_ON_ERROR));

    $customer = Customer::factory()->create();
    $test = LaboratoryTest::factory()->create(['brand' => 'olab']);
    $item = LaboratoryCartItem::factory()->create(['customer_id' => $customer->id, 'laboratory_test_id' => $test->id]);
    AkubicaUatFixtureManifest::query()->create([
        'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
        'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
        'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
        'expires_at' => now()->addHour(),
        'metadata' => ['ids' => ['customers' => ['primary' => $customer->id], 'tests' => [$test->id]]],
    ]);

    $request = [
        'action' => 'verify',
        'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
        'resource_id' => $item->id,
        'purpose' => 'B06b',
        'owner' => 'customer-dedicated',
        'expected_sha' => $sha,
    ];
    $process = runInventoryVerify($metadataPath, $request);
    unlink($metadataPath);

    expect($process->getExitCode())->toBe(0)
        ->and(json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR))->toBe([
            'status' => 'verified',
            'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            'resource_id' => $item->id,
            'purpose' => 'B06b',
            'owner' => 'customer-dedicated',
        ]);
});

test('inventory helper rejects an owner outside the dedicated contract before lookup', function () {
    $sha = 'f248e3cc86d64464d188900859b8019fcf6dbf20';
    $metadataPath = tempnam(sys_get_temp_dir(), 'fw2-release-');
    file_put_contents($metadataPath, $sha);
    $process = runInventoryVerify($metadataPath, [
        'action' => 'verify',
        'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
        'resource_id' => 910006,
        'purpose' => 'B06b',
        'owner' => 'legacy-owner',
        'expected_sha' => $sha,
    ]);
    unlink($metadataPath);

    expect($process->getExitCode())->not->toBe(0)
        ->and(json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR))->toBe(['error' => 'OWNER_NOT_ALLOWED']);
});
