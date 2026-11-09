<?php

use Tests\Support\Database\TestingDatabaseGuard;

test('guard permite famedic_testing en entorno testing', function () {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'famedic_testing',
        'database.connections.mysql.driver' => 'mysql',
    ]);

    // This case tests the name guard; its live-name dependency is a local stand-in.
    \Illuminate\Support\Facades\DB::shouldReceive('connection')->once()->andReturn(new class {public function getDatabaseName():string{return 'famedic_testing';}});
    TestingDatabaseGuard::assertSafeTestingDatabase();

    expect(true)->toBeTrue();
});

test('guard bloquea famedic_qa', function () {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'famedic_qa',
    ]);

    expect(fn () => TestingDatabaseGuard::assertSafeTestingDatabase())
        ->toThrow(\RuntimeException::class, 'famedic_qa');
});

test('guard bloquea famedic sin sufijo testing', function () {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'famedic',
    ]);

    expect(fn () => TestingDatabaseGuard::assertSafeTestingDatabase())
        ->toThrow(\RuntimeException::class, 'famedic');
});
