<?php

use App\Models\User;
use App\Support\Database\PersonNameSearch;

test('admin user search matches full name with multiple words', function () {
    $user = User::factory()->create([
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => 'Externo',
    ]);

    $ids = User::query()
        ->tap(fn ($query) => PersonNameSearch::apply($query, 'eulalio medina', 'users', ['email', 'phone']))
        ->pluck('id');

    expect($ids)->toContain($user->id);
});

test('admin user search matches reversed full name terms', function () {
    $user = User::factory()->create([
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => 'Externo',
    ]);

    $ids = User::query()
        ->tap(fn ($query) => PersonNameSearch::apply($query, 'medina eulalio', 'users', ['email', 'phone']))
        ->pluck('id');

    expect($ids)->toContain($user->id);
});
