<?php

namespace App\Support\Testing;

use RuntimeException;

class UnsafeDatabaseCommandGuard
{
    /**
     * @var list<string>
     */
    private const DESTRUCTIVE_COMMANDS = [
        'db:seed',
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:rollback',
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_DATABASES = [
        'famedic',
        'famedic_qa',
        'local',
        'production',
        'prod',
        'staging',
    ];

    /**
     * @param  list<string>  $argv
     * @param  array<string, mixed>  $connection
     */
    public static function assertSafe(array $argv, string $environment, string $connectionName, array $connection): void
    {
        $command = self::destructiveCommandFrom($argv);

        if ($command === null) {
            return;
        }

        $driver = (string) ($connection['driver'] ?? '');
        $database = (string) ($connection['database'] ?? '');

        if (
            $environment === 'testing'
            && self::isSafeTestingDatabase($driver, $database)
        ) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Comando destructivo bloqueado: %s. Entorno efectivo: %s. Conexión: %s (%s). DB: %s. Usa una base SQLite/DB dedicada de testing y limpia la config cacheada antes de repetir.',
            $command,
            $environment,
            $connectionName,
            $driver ?: 'sin-driver',
            $database ?: 'sin-db',
        ));
    }

    /**
     * @param  list<string>  $argv
     */
    private static function destructiveCommandFrom(array $argv): ?string
    {
        foreach ($argv as $argument) {
            if (in_array($argument, self::DESTRUCTIVE_COMMANDS, true)) {
                return $argument;
            }
        }

        return null;
    }

    private static function isSafeTestingDatabase(string $driver, string $database): bool
    {
        $normalizedDatabase = strtolower(str_replace('\\', '/', $database));
        $basename = basename($normalizedDatabase);

        if ($database === ':memory:') {
            return true;
        }

        if (in_array($basename, self::BLOCKED_DATABASES, true)) {
            return false;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return str_contains($basename, 'test')
                || str_contains($basename, 'testing');
        }

        if ($driver === 'sqlite') {
            return str_ends_with($basename, '.sqlite')
                && (
                    str_contains($basename, 'test')
                    || str_contains($basename, 'testing')
                );
        }

        return false;
    }
}
