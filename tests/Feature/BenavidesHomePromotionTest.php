<?php

use App\Models\BenavidesCode;
use App\Models\BenavidesBenefitPreference;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'famedic.benavides_benefit.enabled' => true,
        'famedic.benavides_benefit.promotion_enabled' => true,
    ]);
});

it('returns disabled promotion state when the promotion flag is off', function () {
    config(['famedic.benavides_benefit.promotion_enabled' => false]);
    $user = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000002001']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.promotionEnabled', false)
            ->where('benavidesBenefit.activationEnabled', true)
            ->where('benavidesBenefit.hasAssignment', false)
            ->where('benavidesBenefit.hasAvailableCodes', true));
});

it('shows the promotion modal for an initially eligible user', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000002601']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.showPromotionModal', true));
});

it('returns promotable state for a user without code when inventory is available', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000002101']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.promotionEnabled', true)
            ->where('benavidesBenefit.activationEnabled', true)
            ->where('benavidesBenefit.hasAssignment', false)
            ->where('benavidesBenefit.hasAvailableCodes', true)
            ->where('benavidesBenefit.showPromotionModal', true));
});

it('returns assignment state for a user with an assigned code', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->assignedTo($user)->create(['code' => '000000002201']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.hasAssignment', true)
            ->where('benavidesBenefit.hasAvailableCodes', false)
            ->where('benavidesBenefit.showPromotionModal', false));
});

it('does not promote activation when inventory is exhausted and the user has no code', function () {
    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.promotionEnabled', true)
            ->where('benavidesBenefit.activationEnabled', true)
            ->where('benavidesBenefit.hasAssignment', false)
            ->where('benavidesBenefit.hasAvailableCodes', false)
            ->where('benavidesBenefit.showPromotionModal', false));
});

it('keeps credential access state when inventory is exhausted and the user has a code', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->assignedTo($user)->create(['code' => '000000002301']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.promotionEnabled', true)
            ->where('benavidesBenefit.hasAssignment', true)
            ->where('benavidesBenefit.hasAvailableCodes', false)
            ->where('benavidesBenefit.showPromotionModal', false));
});

it('keeps credential access state when activations are disabled and the user has a code', function () {
    config(['famedic.benavides_benefit.enabled' => false]);
    $user = medicalAttentionUser();
    BenavidesCode::factory()->assignedTo($user)->create(['code' => '000000002401']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.activationEnabled', false)
            ->where('benavidesBenefit.hasAssignment', true)
            ->where('benavidesBenefit.showPromotionModal', false));
});

it('does not invite activation when promotion is on but activations are disabled for a user without code', function () {
    config(['famedic.benavides_benefit.enabled' => false]);
    $user = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000002501']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.promotionEnabled', true)
            ->where('benavidesBenefit.activationEnabled', false)
            ->where('benavidesBenefit.hasAssignment', false)
            ->where('benavidesBenefit.hasAvailableCodes', true)
            ->where('benavidesBenefit.showPromotionModal', false));
});

it('dismisses the promotion modal idempotently without hiding the banner', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000002701']);

    $this->actingAs($user)
        ->postJson(route('user.benefits.benavides.promotion.dismiss'))
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    $this->actingAs($user)
        ->postJson(route('user.benefits.benavides.promotion.dismiss'))
        ->assertOk();

    expect(BenavidesBenefitPreference::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->benavidesBenefitPreference()->first()->promotion_modal_dismissed_at)->not->toBeNull();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.promotionEnabled', true)
            ->where('benavidesBenefit.activationEnabled', true)
            ->where('benavidesBenefit.hasAssignment', false)
            ->where('benavidesBenefit.hasAvailableCodes', true)
            ->where('benavidesBenefit.showPromotionModal', false));
});

it('records modal click without assigning a Benavides code', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000002801']);

    $this->actingAs($user)
        ->postJson(route('user.benefits.benavides.promotion.click'))
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    $this->actingAs($user)
        ->postJson(route('user.benefits.benavides.promotion.click'))
        ->assertOk();

    expect(BenavidesBenefitPreference::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->benavidesBenefitPreference()->first()->promotion_modal_clicked_at)->not->toBeNull()
        ->and(BenavidesCode::query()->whereNotNull('user_id')->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('benavidesBenefit.showPromotionModal', false)
            ->where('benavidesBenefit.hasAvailableCodes', true));
});

it('requires authentication to persist Benavides promotion decisions', function () {
    $this->postJson(route('user.benefits.benavides.promotion.dismiss'))
        ->assertUnauthorized();
});

it('does not allow one user to modify another users Benavides promotion preference', function () {
    $firstUser = medicalAttentionUser();
    $secondUser = medicalAttentionUser();

    $this->actingAs($firstUser)
        ->postJson(route('user.benefits.benavides.promotion.dismiss'))
        ->assertOk();

    expect(BenavidesBenefitPreference::query()->where('user_id', $firstUser->id)->exists())->toBeTrue()
        ->and(BenavidesBenefitPreference::query()->where('user_id', $secondUser->id)->exists())->toBeFalse();
});

it('prevents multiple Benavides promotion preference rows for one user', function () {
    $user = medicalAttentionUser();

    BenavidesBenefitPreference::query()->create(['user_id' => $user->id]);

    expect(fn () => BenavidesBenefitPreference::query()->create(['user_id' => $user->id]))
        ->toThrow(QueryException::class);
});
