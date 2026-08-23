<?php

/**
 * Restores shared SQLite state after isolated schema tests drop core tables.
 *
 * @see tests/Pest.php (Pest re-exports this helper for Feature tests)
 */
function restoreMonolithicTestDatabase(): void
{
    \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
    \Illuminate\Foundation\Testing\RefreshDatabaseState::$lazilyRefreshed = false;

    $connection = (string) config('database.default', 'sqlite');

    \Illuminate\Support\Facades\DB::disconnect($connection);
    \Illuminate\Support\Facades\DB::purge($connection);

    $driver = config("database.connections.{$connection}.driver");

    if ($driver !== 'sqlite') {
        return;
    }

    $database = config("database.connections.{$connection}.database");

    if (! is_string($database) || $database === '' || $database === ':memory:') {
        return;
    }

    $path = str_starts_with($database, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $database) === 1
        ? $database
        : database_path($database);

    if (file_exists($path)) {
        unlink($path);
    }

    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    touch($path);
}
