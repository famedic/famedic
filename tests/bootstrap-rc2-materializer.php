<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// The disposable MySQL schema is prepared by the runner before PHPUnit starts.
// Keep RefreshDatabase's per-test transaction, but avoid an expensive migrate:fresh
// in every separate PHPUnit process. The runner refuses to continue if migrations
// are not complete.
\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
