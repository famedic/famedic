<?php

it('confirms CartJourney configuration uses isolated testing SQLite', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(strtolower((string) config('database.connections.sqlite.database')))->toContain('test');
});
