<?php

use App\Models\BenavidesCode;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['famedic.benavides_benefit.enabled' => true]);
});

it('requires authentication to view the Benavides benefit page', function () {
    $this->get(route('user.benefits.benavides.show'))
        ->assertRedirect(route('login'));
});

it('shows no assignment for an eligible user without a code', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000001001']);

    $this->actingAs($user)
        ->get(route('user.benefits.benavides.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('User/Benefits/Benavides')
            ->where('assignment', null)
            ->where('benefitEnabled', true)
            ->where('hasAvailableCodes', true));
});

it('shows only the authenticated users assigned code', function () {
    $user = medicalAttentionUser();
    $otherUser = medicalAttentionUser();
    BenavidesCode::factory()->assignedTo($user)->create(['code' => '000000001101']);
    BenavidesCode::factory()->assignedTo($otherUser)->create(['code' => '000000001102']);

    $this->actingAs($user)
        ->get(route('user.benefits.benavides.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('User/Benefits/Benavides')
            ->where('assignment.code', '000000001101')
            ->missing('assignment.user_id')
            ->missing('assignment.id')
            ->where('holderName', $user->full_name));
});

it('does not expose another users code when the authenticated user has no assignment', function () {
    $user = medicalAttentionUser();
    $otherUser = medicalAttentionUser();
    BenavidesCode::factory()->assignedTo($otherUser)->create(['code' => '000000001202']);

    $this->actingAs($user)
        ->get(route('user.benefits.benavides.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment', null)
            ->where('hasAvailableCodes', false));
});

it('shows unavailable state when the feature flag is off and the user has no code', function () {
    config(['famedic.benavides_benefit.enabled' => false]);
    $user = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000000001301']);

    $this->actingAs($user)
        ->get(route('user.benefits.benavides.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment', null)
            ->where('benefitEnabled', false)
            ->where('hasAvailableCodes', true));
});

it('still shows the credential when the feature flag is off and the user has a code', function () {
    config(['famedic.benavides_benefit.enabled' => false]);
    $user = medicalAttentionUser();
    BenavidesCode::factory()->assignedTo($user)->create(['code' => '000000001401']);

    $this->actingAs($user)
        ->get(route('user.benefits.benavides.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.code', '000000001401')
            ->where('benefitEnabled', false));
});

it('indicates no codes are available when inventory is exhausted and the user has no code', function () {
    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->get(route('user.benefits.benavides.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment', null)
            ->where('hasAvailableCodes', false));
});

it('still shows the credential when inventory is exhausted and the user has a code', function () {
    $user = medicalAttentionUser();
    BenavidesCode::factory()->assignedTo($user)->create(['code' => '000000001501']);

    $this->actingAs($user)
        ->get(route('user.benefits.benavides.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.code', '000000001501')
            ->where('hasAvailableCodes', false));
});
