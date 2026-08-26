<?php

use App\Models\User;

beforeEach(function () {
    config(['efevoopay.gateway' => 'mock']);
});

it('requires authentication for local 3ds harness routes', function () {
    $this->get(route('local.3ds.harness'))->assertRedirect();
});

it('blocks harness routes when gateway is not mock', function () {
    config(['efevoopay.gateway' => 'live']);

    $user = User::factory()->withCompleteProfile()->withRegularCustomer()->create([
        'documentation_accepted_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('local.3ds.harness'))
        ->assertNotFound();
});

it('records sanitized acs observation for fake challenge post', function () {
    $user = User::factory()->withCompleteProfile()->withRegularCustomer()->create([
        'documentation_accepted_at' => now(),
    ]);

    $harnessId = 'harness-test-1';

    $this->actingAs($user)->post(route('local.3ds.fake-acs', ['harness' => $harnessId]), [
        'creq' => 'token-value',
    ])->assertOk();

    $this->actingAs($user)
        ->get(route('local.3ds.observation', ['harnessId' => $harnessId]))
        ->assertOk()
        ->assertJson([
            'post_count' => 1,
            'has_creq_field' => true,
        ]);
});

it('renders react harness with real three ds redirect page', function () {
    $user = User::factory()->withCompleteProfile()->withRegularCustomer()->create([
        'documentation_accepted_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('local.3ds.react-harness'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PaymentMethods/ThreeDSRedirect')
            ->has('sessionId')
            ->has('url3ds')
            ->has('token3ds'));
});
