<?php

use App\Models\AkubicaCheckoutLink;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;

uses(\PHPUnit\Framework\TestCase::class);

test('A4 MySQL 8 claim allows exactly one winner and persists one consumption', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is required for the real concurrency check.');
    }

    $capsule = new Capsule;
    $capsule->addConnection([
        'driver' => 'mysql',
        'host' => getenv('A4_MYSQL_HOST') ?: 'mysql',
        'port' => (int) (getenv('A4_MYSQL_PORT') ?: 3306),
        'database' => getenv('A4_MYSQL_DATABASE') ?: 'famedic_rc2_local_20261110_testing',
        'username' => getenv('A4_MYSQL_USERNAME') ?: 'root',
        'password' => getenv('A4_MYSQL_PASSWORD') ?: 'root',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Model::setConnectionResolver($capsule->getDatabaseManager());

    $database = $capsule->getConnection();
    $customerId = $database->table('customers')->insertGetId([
        'medical_attention_identifier' => 'A4-MYSQL-RACE-'.bin2hex(random_bytes(6)),
        'customerable_type' => 'synthetic',
        'customerable_id' => 1,
        'user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tokenHash = hash('sha256', 'a4-mysql-race-'.bin2hex(random_bytes(16)));
    $linkId = $database->table('akubica_checkout_links')->insertGetId([
        'customer_id' => (int) $customerId,
        'token_hash' => $tokenHash,
        'laboratory_brand' => 'olab',
        'expires_at' => now()->addMinutes(10),
        'used_at' => null,
        'created_by_token_id' => null,
        'metadata' => json_encode([
            'purpose' => 'akubica_laboratory_continuity',
            'service_line' => 'laboratory',
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resultFile = tempnam(sys_get_temp_dir(), 'a4-mysql-claim-race-');
    $startFile = $resultFile.'.start';
    $children = [];

    for ($i = 0; $i < 2; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $capsule->getDatabaseManager()->purge('default');
            $capsule->getDatabaseManager()->reconnect('default');
            while (! is_file($startFile)) {
                usleep(1000);
            }

            $won = (new AkubicaCheckoutLink)->newQuery()->findOrFail($linkId)->claim();
            file_put_contents($resultFile, ($won ? '1' : '0').PHP_EOL, FILE_APPEND | LOCK_EX);
            exit(0);
        }
        $children[] = $pid;
    }

    touch($startFile);
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifexited($status))->toBeTrue();
    }

    $results = array_map('intval', file($resultFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    sort($results);
    $persisted = $database->table('akubica_checkout_links')->where('id', $linkId)->first();

    expect($results)->toBe([0, 1])
        ->and($persisted)->not->toBeNull()
        ->and($persisted->used_at)->not->toBeNull();

    $database->table('akubica_checkout_links')->where('id', $linkId)->delete();
    $database->table('customers')->where('id', $customerId)->delete();
    @unlink($resultFile);
    @unlink($startFile);
});
