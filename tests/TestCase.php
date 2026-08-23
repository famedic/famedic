<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        self::ensureTestingSqliteDatabaseFileExists();

        parent::setUp();

        $this->withoutMiddleware([
            'password.confirm',
            \App\Http\Middleware\ExcludePasswordConfirm::class,
            \Illuminate\Auth\Middleware\RequirePassword::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    private static function ensureTestingSqliteDatabaseFileExists(): void
    {
        $connection = getenv('DB_CONNECTION') ?: 'sqlite';

        if ($connection !== 'sqlite') {
            return;
        }

        $database = getenv('DB_DATABASE') ?: 'test_db.sqlite';

        if ($database === '' || $database === ':memory:') {
            return;
        }

        $path = str_starts_with($database, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $database) === 1
            ? $database
            : dirname(__DIR__).'/database/'.$database;

        if (file_exists($path)) {
            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        touch($path);
    }
}
