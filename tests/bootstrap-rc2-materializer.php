<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// The shared vendor symlink can make Pest discover the sibling worktree's
// Pest.php. Register only the bindings needed by this dedicated XML suite;
// do not include Pest.php here because Pest loads it later.
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in(__DIR__.'/Feature');

// The dedicated RC2 XML suites are invoked directly by Pest/PHPUnit, not via
// `artisan test`. Bootstrap the application here so config()/DB/facades used
// by Pest closures resolve before the first test lifecycle hook. This is test
// harness setup only; runtime bootstrap and production code are unchanged.
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// The disposable MySQL schema is prepared by the runner before PHPUnit starts.
// Keep RefreshDatabase's per-test transaction, but avoid an expensive migrate:fresh
// in every separate PHPUnit process. The runner refuses to continue if migrations
// are not complete.
\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
