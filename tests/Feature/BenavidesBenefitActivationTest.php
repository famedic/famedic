<?php

use App\Models\BenavidesCode;
use App\Models\User;
use App\Services\Benavides\BenavidesBenefitAssignmentService;
use Illuminate\Database\QueryException;

beforeEach(function () {
    config(['famedic.benavides_benefit.enabled' => true]);
});

it('allows an authenticated customer to activate the Benavides benefit', function () {
    $user = medicalAttentionUser();
    $available = BenavidesCode::factory()->create(['code' => '000012345678']);

    $response = $this->actingAs($user)->postJson(route('user.benefits.benavides.activate'));

    $response->assertOk()
        ->assertJsonPath('status', 'assigned')
        ->assertJsonPath('benavides_code.code', '000012345678');

    $available->refresh();

    expect($available->user_id)->toBe($user->id)
        ->and($available->assigned_at)->not->toBeNull();
});

it('is idempotent for the same user and does not consume another code', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->count(5)->sequence(
        ['code' => '000000000001'],
        ['code' => '000000000002'],
        ['code' => '000000000003'],
        ['code' => '000000000004'],
        ['code' => '000000000005'],
    )->create();

    $first = $this->actingAs($user)->postJson(route('user.benefits.benavides.activate'));
    $second = $this->actingAs($user)->postJson(route('user.benefits.benavides.activate'));

    $first->assertOk();
    $second->assertOk();

    expect($second->json('benavides_code.code'))->toBe($first->json('benavides_code.code'))
        ->and(BenavidesCode::query()->whereNotNull('user_id')->count())->toBe(1)
        ->and(BenavidesCode::query()->whereNull('user_id')->count())->toBe(4);
});

it('assigns different codes to different users', function () {
    $firstUser = medicalAttentionUser();
    $secondUser = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000000101']);
    BenavidesCode::factory()->create(['code' => '000000000102']);

    $first = $this->actingAs($firstUser)->postJson(route('user.benefits.benavides.activate'));
    $second = $this->actingAs($secondUser)->postJson(route('user.benefits.benavides.activate'));

    $first->assertOk();
    $second->assertOk();

    expect($first->json('benavides_code.code'))->not->toBe($second->json('benavides_code.code'));
});

it('returns a controlled response when inventory is exhausted', function () {
    $user = medicalAttentionUser();

    $response = $this->actingAs($user)->postJson(route('user.benefits.benavides.activate'));

    $response->assertStatus(409)
        ->assertJsonPath('code', 'BENAVIDES_CODES_EXHAUSTED');

    expect(BenavidesCode::query()->whereNotNull('user_id')->count())->toBe(0);
});

it('requires authentication', function () {
    $this->postJson(route('user.benefits.benavides.activate'))
        ->assertUnauthorized();
});

it('requires a verified user according to the route middleware', function () {
    $user = User::factory()
        ->withCompleteProfile()
        ->withUnverifiedEmail()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()]);
    BenavidesCode::factory()->create(['code' => '000000000201']);

    $this->actingAs($user)
        ->post(route('user.benefits.benavides.activate'))
        ->assertRedirect(route('verification.notice'));

    expect(BenavidesCode::query()->whereNotNull('user_id')->count())->toBe(0);
});

it('blocks new activations when the feature flag is disabled without modifying existing assignments', function () {
    config(['famedic.benavides_benefit.enabled' => false]);
    $existingUser = medicalAttentionUser();
    $newUser = medicalAttentionUser();
    $assigned = BenavidesCode::factory()->assignedTo($existingUser)->create(['code' => '000000000301']);
    BenavidesCode::factory()->create(['code' => '000000000302']);

    $this->actingAs($newUser)
        ->postJson(route('user.benefits.benavides.activate'))
        ->assertForbidden()
        ->assertJsonPath('code', 'BENAVIDES_BENEFIT_DISABLED');

    $assigned->refresh();

    expect($assigned->user_id)->toBe($existingUser->id)
        ->and($assigned->code)->toBe('000000000301')
        ->and(BenavidesCode::query()->whereNotNull('user_id')->count())->toBe(1);
});

it('enforces unique codes and unique assigned users at the database level', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000000401']);

    expect(fn () => BenavidesCode::factory()->create(['code' => '000000000401']))
        ->toThrow(QueryException::class);

    BenavidesCode::factory()->assignedTo($user)->create(['code' => '000000000402']);

    expect(fn () => BenavidesCode::factory()->assignedTo($user)->create(['code' => '000000000403']))
        ->toThrow(QueryException::class);
});

it('preserves codes as strings with leading zeroes', function () {
    $code = BenavidesCode::factory()->create(['code' => '000012345678']);

    expect($code->fresh()->code)->toBe('000012345678');
});

it('returns an existing assignment from the service without consuming inventory', function () {
    $user = medicalAttentionUser();
    $existing = BenavidesCode::factory()->assignedTo($user)->create(['code' => '000000000501']);
    BenavidesCode::factory()->create(['code' => '000000000502']);

    $result = app(BenavidesBenefitAssignmentService::class)->assignTo($user);

    expect($result->id)->toBe($existing->id)
        ->and($result->code)->toBe('000000000501')
        ->and(BenavidesCode::query()->whereNotNull('user_id')->count())->toBe(1)
        ->and(BenavidesCode::query()->whereNull('user_id')->count())->toBe(1);
});
