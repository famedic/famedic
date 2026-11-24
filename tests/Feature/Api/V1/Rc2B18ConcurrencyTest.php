<?php

use App\Models\MedicalServiceCartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

test('B18 concurrent membership adds against Laravel/MySQL have one winner and one rejection', function () {
    expect(DB::getDriverName())->toBe('mysql');
    $user = User::factory()->withRegularCustomer()->create();
    $issued = SyntheticConsumerRegistryFixture::issue($user, 'native');
    // Child Laravel processes use independent MySQL connections and must see
    // the synthetic actor before the race begins; the parent test transaction
    // is therefore committed before spawning them and cleaned explicitly.
    if (DB::transactionLevel() > 0) {
        DB::commit();
    }
    $base = base_path();
    $prefix = storage_path('framework/it0061-b18-'.bin2hex(random_bytes(5)));
    $go = $prefix.'.go';
    $jobs = [];
    $environment = getenv();
    $environment['APP_BASE_PATH'] = $base;
    $environment['FAMEDIC_V2_PREPARE_SCHEMA'] = '0';
    $environment['APP_ENV'] = 'testing';
    $environment['FAMEDIC_RC2_LOCAL_MYSQL'] = '1';
    $environment['FAMEDIC_REGISTERED_NATIVE_FIXTURES'] = '1';
    $environment['DB_CONNECTION'] = 'mysql';
    $environment['DB_HOST'] = '127.0.0.1';
    $environment['DB_PORT'] = '13307';
    $environment['DB_DATABASE'] = 'famedic_rc2_it0061_window2_testing';
    $environment['DB_USERNAME'] = 'famedic_rc2_test';
    $environment['DB_PASSWORD'] = 'famedic_rc2_test_pw';

    try {
        foreach ([0, 1] as $worker) {
            $ready = $prefix.'.'.$worker.'.ready';
            $process = proc_open(
                [PHP_BINARY, '-d', 'xdebug.mode=off', $base.'/scripts/tests/it0061-b18-worker.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $base,
                $environment,
            );
            expect($process)->toBeResource();
            fwrite($pipes[0], json_encode([
                'ready' => $ready,
                'go' => $go,
                'worker' => $worker,
                'user_id' => $user->id,
                'bearer' => $issued['token']->plainTextToken,
                'machine' => $issued['machine']->plainTextToken,
            ], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $jobs[] = [$process, $pipes, $ready];
        }

        $deadline = microtime(true) + 15;
        while (! is_file($jobs[0][2]) || ! is_file($jobs[1][2])) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('B18 workers did not reach the barrier');
            }
            usleep(20000);
        }
        touch($go);

        $results = [];
        foreach ($jobs as [$process, $pipes]) {
            $stdout = trim(stream_get_contents($pipes[1]));
            $stderr = trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect($stderr)->toBe('', $stderr);
            expect(proc_close($process))->toBe(0);
            $results[] = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($results, 'status');
        sort($statuses);
        expect($statuses)->toBe([201, 409], json_encode($results, JSON_THROW_ON_ERROR))
            ->and(MedicalServiceCartItem::query()->whereHas('cart', fn ($q) => $q->where('customer_id', $user->customer->id))->count())->toBe(1);
    } finally {
        if (! is_file($go)) {
            touch($go);
        }
        foreach (glob($prefix.'*') ?: [] as $file) {
            @unlink($file);
        }
        $cartIds = DB::table('medical_service_carts')->where('customer_id', $user->customer->id)->pluck('id')->all();
        if ($cartIds !== []) {
            DB::table('medical_service_cart_items')->whereIn('cart_id', $cartIds)->delete();
            DB::table('medical_service_carts')->whereIn('id', $cartIds)->delete();
        }
    }
});
