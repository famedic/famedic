<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;

final class TestingDatabaseGuard
{
    /** @var list<string> */
    private const FORBIDDEN_DATABASES = [
        'famedic',
        'famedic_qa',
        'famedic_production',
        'production',
        'staging',
    ];

    /** @var list<string> */
    private const FORBIDDEN_SUFFIXES = [
        '_qa',
        '_prod',
        '_production',
        '_staging',
    ];

    public static function assertSafeTestingDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new \RuntimeException(
                'Refusing to run tests: APP_ENV must be "testing", got "'.app()->environment().'".',
            );
        }

        $connection = (string) config('database.default');
        $database = strtolower(trim((string) config("database.connections.{$connection}.database")));

        if ($database === '' || $database === ':memory:') {
            return;
        }

        if (in_array($database, self::FORBIDDEN_DATABASES, true)) {
            self::abort($database);
        }

        foreach (self::FORBIDDEN_SUFFIXES as $suffix) {
            if (str_ends_with($database, $suffix)) {
                self::abort($database);
            }
        }

        if (! str_ends_with($database, '_testing') && $database !== 'test_db.sqlite') {
            throw new \RuntimeException(
                'Refusing to run tests: database "'.$database.'" must end with "_testing" or be SQLite test_db.sqlite / :memory:.',
            );
        }

        $liveName = strtolower(trim((string) DB::connection()->getDatabaseName()));
        if ($liveName !== '' && $liveName !== $database) {
            throw new \RuntimeException(
                'Refusing to run tests: configured database "'.$database.'" differs from active "'.$liveName.'".',
            );
        }
    }

    private static function abort(string $database): never
    {
        throw new \RuntimeException(
            'Refusing to run tests against protected database "'.$database.'". Use famedic_testing or SQLite.',
        );
    }
}
