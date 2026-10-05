<?php

use App\Models\BenavidesCode;
use Database\Seeders\BenavidesDemoSeeder;

it('seeds exactly 50 idempotent available Benavides demo codes without touching assigned codes', function () {
    $this->seed(BenavidesDemoSeeder::class);

    $codes = BenavidesCode::query()
        ->where('code', 'like', 'TESTBEN%')
        ->pluck('code');

    expect(BenavidesCode::query()->where('code', 'like', 'TESTBEN%')->count())->toBe(50)
        ->and($codes->every(fn (string $code) => str_starts_with($code, 'TESTBEN')))->toBeTrue()
        ->and(BenavidesCode::query()->where('code', 'like', 'TESTBEN%')->whereNull('user_id')->count())->toBe(50)
        ->and(BenavidesCode::query()->where('code', 'like', 'TESTBEN%')->whereNull('assigned_at')->count())->toBe(50)
        ->and(BenavidesCode::query()->where('code', 'like', 'TESTBEN%')->whereNull('import_batch_id')->count())->toBe(50)
        ->and(BenavidesCode::query()->where('code', 'like', 'TESTBEN%')->whereNotNull('import_batch_id')->count())->toBe(0);

    $user = medicalAttentionUser();
    $assigned = BenavidesCode::query()->where('code', 'TESTBEN000001')->firstOrFail();
    $assigned->forceFill([
        'user_id' => $user->id,
        'assigned_at' => now(),
    ])->save();

    $this->seed(BenavidesDemoSeeder::class);

    expect(BenavidesCode::query()->where('code', 'like', 'TESTBEN%')->count())->toBe(50)
        ->and($assigned->fresh()->user_id)->toBe($user->id)
        ->and($assigned->fresh()->assigned_at)->not->toBeNull();
});
