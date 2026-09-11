<?php

use App\Support\Testing\UnsafeDatabaseCommandGuard;

it('blocks destructive commands when config resolves to qa mysql', function () {
    UnsafeDatabaseCommandGuard::assertSafe(
        ['artisan', 'migrate:fresh', '--env=testing'],
        'production',
        'mysql',
        [
            'driver' => 'mysql',
            'database' => 'famedic_qa',
        ],
    );
})->throws(RuntimeException::class, 'Comando destructivo bloqueado: migrate:fresh');

it('allows destructive commands only against explicit testing sqlite databases', function () {
    UnsafeDatabaseCommandGuard::assertSafe(
        ['artisan', 'migrate:fresh', '--env=testing'],
        'testing',
        'sqlite',
        [
            'driver' => 'sqlite',
            'database' => database_path('test_db.sqlite'),
        ],
    );

    expect(true)->toBeTrue();
});

it('ignores non destructive artisan commands', function () {
    UnsafeDatabaseCommandGuard::assertSafe(
        ['artisan', 'about', '--only=environment'],
        'production',
        'mysql',
        [
            'driver' => 'mysql',
            'database' => 'famedic_qa',
        ],
    );

    expect(true)->toBeTrue();
});
