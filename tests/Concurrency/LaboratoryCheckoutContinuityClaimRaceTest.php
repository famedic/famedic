<?php

use App\Models\AkubicaCheckoutLink;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;

uses(\PHPUnit\Framework\TestCase::class);

test('A4 claim allows exactly one winner across concurrent processes', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is required for the real concurrency check.');
    }

    $databaseFile = tempnam(sys_get_temp_dir(), 'a4-claim-db-');
    $capsule = new Capsule;
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => $databaseFile,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Model::setConnectionResolver($capsule->getDatabaseManager());

    $schema = $capsule->getConnection()->getSchemaBuilder();
    $schema->create('akubica_checkout_links', function ($table) {
        $table->id();
        $table->unsignedBigInteger('customer_id')->nullable();
        $table->string('token_hash')->nullable();
        $table->string('laboratory_brand')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('used_at')->nullable();
        $table->json('metadata')->nullable();
        $table->unsignedBigInteger('created_by_token_id')->nullable();
        $table->timestamps();
    });

    $link = AkubicaCheckoutLink::query()->create([
        'customer_id' => 1,
        'token_hash' => hash('sha256', 'a4-race-token'),
        'laboratory_brand' => 'olab',
        'expires_at' => now()->addMinutes(10),
        'metadata' => [
            'purpose' => 'akubica_laboratory_continuity',
            'service_line' => 'laboratory',
        ],
    ]);

    $resultFile = tempnam(sys_get_temp_dir(), 'a4-claim-race-');
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
            $won = (new AkubicaCheckoutLink)->newQuery()->findOrFail($link->id)->claim();
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
    expect($results)->toBe([0, 1]);

    @unlink($resultFile);
    @unlink($startFile);
    @unlink($databaseFile);
});
