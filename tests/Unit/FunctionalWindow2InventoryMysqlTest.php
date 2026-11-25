<?php

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function runInventoryVerifyMysql(string $metadataPath, array $request): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('artisan'), 'functional-window2:inventory', '--no-ansi'],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '13307',
            'DB_DATABASE' => 'famedic_rc2_it0061_window2_testing',
            'DB_USERNAME' => 'famedic_rc2_test',
            'DB_PASSWORD' => 'famedic_rc2_test_pw',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'AKUBICA_UAT_RELEASE_METADATA_PATH' => $metadataPath,
        ],
    );
    $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
    $process->run();

    return $process;
}

test('inventory helper verifies only the dedicated namespace resource in mysql', function () {
    $sha = 'f248e3cc86d64464d188900859b8019fcf6dbf20';
    $metadataPath = tempnam(sys_get_temp_dir(), 'fw2-release-');
    file_put_contents($metadataPath, json_encode(['release' => 'synthetic-release-1', 'active_sha' => $sha], JSON_THROW_ON_ERROR));

    $user = $customer = $test = $item = $manifest = null;
    try {
        $user = User::factory()->create();
        $customer = Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);
        $test = LaboratoryTest::factory()->create(['brand' => 'olab']);
        $item = LaboratoryCartItem::factory()->create(['customer_id' => $customer->id, 'laboratory_test_id' => $test->id]);
        $manifest = AkubicaUatFixtureManifest::query()->create([
            'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
            'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
            'expires_at' => now()->addHour(),
            'metadata' => ['ids' => ['customers' => ['primary' => $customer->id], 'tests' => [$test->id]]],
        ]);

        $process = runInventoryVerifyMysql($metadataPath, [
            'action' => 'verify',
            'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            'resource_id' => $item->id,
            'purpose' => 'B06b',
            'owner' => 'customer-dedicated',
            'expected_sha' => $sha,
        ]);

        expect($process->getExitCode(), trim($process->getOutput().' '.$process->getErrorOutput()))->toBe(0)
            ->and(json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR))->toBe([
                'status' => 'verified',
                'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
                'resource_id' => $item->id,
                'purpose' => 'B06b',
                'owner' => 'customer-dedicated',
            ]);
    } finally {
        if ($manifest) DB::table('akubica_uat_fixture_manifests')->where('id', $manifest->id)->delete();
        if ($item) DB::table('laboratory_cart_items')->where('id', $item->id)->delete();
        if ($test) DB::table('laboratory_tests')->where('id', $test->id)->delete();
        if ($customer) DB::table('customers')->where('id', $customer->id)->delete();
        if ($user) DB::table('users')->where('id', $user->id)->delete();
        if (is_file($metadataPath)) unlink($metadataPath);
    }
});

test('inventory helper rejects an owner outside the dedicated contract before lookup', function () {
    $sha = 'f248e3cc86d64464d188900859b8019fcf6dbf20';
    $metadataPath = tempnam(sys_get_temp_dir(), 'fw2-release-');
    file_put_contents($metadataPath, $sha);
    $process = runInventoryVerifyMysql($metadataPath, [
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

test('inventory helper registers and verifies a namespace-scoped medical membership item', function () {
    $sha = 'f248e3cc86d64464d188900859b8019fcf6dbf20';
    $metadataPath = tempnam(sys_get_temp_dir(), 'fw2-release-');
    file_put_contents($metadataPath, $sha);
    $user = $foreignUser = $customer = $foreignCustomer = $cart = $windowCart = $foreignCart = $baseItem = $windowItem = $manifest = null;
    $spec = [
        'purpose' => 'cart-item-created-by-B12',
        'owner' => 'customer:membership_none',
        'resource_type' => 'medical_service_cart_item',
        'service_line' => 'medical_services',
        'customer_role' => 'membership_none',
        'item_type' => 'membership',
        'membership_plan_code' => 'basic_annual',
    ];
    try {
        $user = User::factory()->create();
        $customer = Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);
        $cart = MedicalServiceCart::factory()->create(['customer_id' => $customer->id]);
        $foreignUser = User::factory()->create();
        $foreignCustomer = Customer::factory()->withRegularAccount()->create(['user_id' => $foreignUser->id]);
        $foreignCart = MedicalServiceCart::factory()->create(['customer_id' => $foreignCustomer->id]);
        $baseItem = MedicalServiceCartItem::factory()->create(['cart_id' => $foreignCart->id]);
        $manifest = AkubicaUatFixtureManifest::query()->create([
            'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
            'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
            'metadata' => ['ids' => ['customers' => ['membership_none' => $customer->id]]],
        ]);
        $windowItem = MedicalServiceCartItem::factory()->create(['cart_id' => $cart->id]);

        $register = runInventoryVerifyMysql($metadataPath, array_merge(['action' => 'register', 'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE, 'resource_id' => $windowItem->id, 'source_case' => 'B12', 'expected_sha' => $sha], $spec));
        expect($register->getExitCode(), $register->getOutput().' '.$register->getErrorOutput())->toBe(0)
            ->and(json_decode(trim($register->getOutput()), true, 512, JSON_THROW_ON_ERROR)['status'] ?? null)->toBe('registered');

        $verify = runInventoryVerifyMysql($metadataPath, array_merge(['action' => 'verify', 'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE, 'resource_id' => $windowItem->id, 'expected_sha' => $sha], $spec));
        expect($verify->getExitCode())->toBe(0);

        $base = runInventoryVerifyMysql($metadataPath, array_merge(['action' => 'register', 'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE, 'resource_id' => $baseItem->id, 'source_case' => 'B12', 'expected_sha' => $sha], $spec));
        expect($base->getExitCode())->not->toBe(0)
            ->and(json_decode(trim($base->getOutput()), true, 512, JSON_THROW_ON_ERROR)['error'] ?? null)->toBe('RESOURCE_OWNER_MISMATCH');

        $foreign = $spec;
        $foreign['owner'] = 'customer:primary';
        $foreignCheck = runInventoryVerifyMysql($metadataPath, array_merge(['action' => 'verify', 'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE, 'resource_id' => $windowItem->id, 'expected_sha' => $sha], $foreign));
        expect($foreignCheck->getExitCode())->not->toBe(0);

        $windowItem->delete();
        $unregister = runInventoryVerifyMysql($metadataPath, array_merge(['action' => 'unregister', 'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE, 'resource_id' => $windowItem->id, 'expected_sha' => $sha], $spec));
        expect($unregister->getExitCode(), $unregister->getOutput().' '.$unregister->getErrorOutput())->toBe(0)
            ->and(json_decode(trim($unregister->getOutput()), true, 512, JSON_THROW_ON_ERROR)['status'] ?? null)->toBe('unregistered');
    } finally {
        if ($manifest) DB::table('akubica_uat_fixture_manifests')->where('id', $manifest->id)->delete();
        if ($baseItem) DB::table('medical_service_cart_items')->where('id', $baseItem->id)->delete();
        if ($windowItem) DB::table('medical_service_cart_items')->where('id', $windowItem->id)->delete();
        if ($windowCart) DB::table('medical_service_carts')->where('id', $windowCart->id)->delete();
        if ($foreignCart) DB::table('medical_service_carts')->where('id', $foreignCart->id)->delete();
        if ($foreignCustomer) DB::table('customers')->where('id', $foreignCustomer->id)->delete();
        if ($foreignUser) DB::table('users')->where('id', $foreignUser->id)->delete();
        if ($cart) DB::table('medical_service_carts')->where('id', $cart->id)->delete();
        if ($customer) DB::table('customers')->where('id', $customer->id)->delete();
        if ($user) DB::table('users')->where('id', $user->id)->delete();
        if (is_file($metadataPath)) unlink($metadataPath);
    }
});

test('inventory helper registers and verifies the dedicated A4 laboratory item', function () {
    $sha = 'f248e3cc86d64464d188900859b8019fcf6dbf20';
    $metadataPath = tempnam(sys_get_temp_dir(), 'fw2-release-');
    file_put_contents($metadataPath, $sha);
    $user = $customer = $test = $item = $manifest = null;
    try {
        $user = User::factory()->create();
        $customer = Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);
        $test = LaboratoryTest::factory()->create([
            'brand' => 'olab',
            'gda_id' => 'RC2-A4-CONTINUITY-'.AkubicaUatFixtureContract::DEDICATED_NAMESPACE.'-OLAB-'.bin2hex(random_bytes(3)),
        ]);
        $item = LaboratoryCartItem::factory()->create(['customer_id' => $customer->id, 'laboratory_test_id' => $test->id]);
        $manifest = AkubicaUatFixtureManifest::query()->create([
            'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
            'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
            'metadata' => ['ids' => ['customers' => ['membership_none' => $customer->id]]],
        ]);

        $spec = [
            'purpose' => 'cart-item-created-by-A4-continuity',
            'owner' => 'customer:membership_none',
            'source_case' => 'A4',
            'expected_sha' => $sha,
        ];
        $register = runInventoryVerifyMysql($metadataPath, array_merge(['action' => 'register', 'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE, 'resource_id' => $item->id], $spec));
        expect($register->getExitCode(), $register->getOutput().' '.$register->getErrorOutput())->toBe(0);
        $verify = runInventoryVerifyMysql($metadataPath, array_merge(['action' => 'verify', 'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE, 'resource_id' => $item->id], $spec));
        expect($verify->getExitCode())->toBe(0);

        $item->delete();
        $unregister = runInventoryVerifyMysql($metadataPath, array_merge(['action' => 'unregister', 'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE, 'resource_id' => $item->id], $spec));
        expect($unregister->getExitCode())->toBe(0);
    } finally {
        if ($manifest) {
            DB::table('akubica_uat_fixture_manifests')->where('id', $manifest->id)->delete();
        }
        if ($item) {
            DB::table('laboratory_cart_items')->where('id', $item->id)->delete();
        }
        if ($test) {
            DB::table('laboratory_tests')->where('id', $test->id)->delete();
        }
        if ($customer) {
            DB::table('customers')->where('id', $customer->id)->delete();
        }
        if ($user) {
            DB::table('users')->where('id', $user->id)->delete();
        }
        if (is_file($metadataPath)) {
            unlink($metadataPath);
        }
    }
});
