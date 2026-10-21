<?php

use App\Enums\MedicalSubscriptionType;
use App\Models\MedicalAttentionSubscription;
use App\Models\User;

function createMembershipSubscription($customer, MedicalSubscriptionType $type, array $dates = []): MedicalAttentionSubscription
{
    return MedicalAttentionSubscription::query()->create(array_merge([
        'customer_id' => $customer->id,
        'type' => $type,
        'price_cents' => 30000,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
    ], $dates));
}

test('GET /memberships/plans returns only the available basic plan', function () {
    $response = $this->getJson('/api/v1/memberships/plans');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.plans')
        ->assertJsonPath('data.plans.0.code', 'basic_annual')
        ->assertJsonPath('data.plans.0.name', 'Plan Básico')
        ->assertJsonPath('data.plans.0.audience', 'individual')
        ->assertJsonPath('data.plans.0.price_cents', 30000)
        ->assertJsonPath('data.plans.0.currency', 'MXN')
        ->assertJsonPath('data.plans.0.tax_included', true)
        ->assertJsonPath('data.plans.0.billing_type', 'one_time')
        ->assertJsonPath('data.plans.0.validity_months', 12)
        ->assertJsonPath('data.plans.0.trial_days', 0)
        ->assertJsonPath('data.plans.0.is_available', true);
});

test('GET /memberships/status returns eligible when the customer has no membership', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/memberships/status', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'none')
        ->assertJsonPath('data.purchase_eligible', true)
        ->assertJsonPath('data.ineligibility_reason', null);
});

test('GET /memberships/status blocks an active individual membership', function () {
    [$user, $token] = akubicaCustomerToken();
    createMembershipSubscription($user->customer, MedicalSubscriptionType::REGULAR);

    $this->getJson('/api/v1/memberships/status', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.plan_code', 'basic_annual')
        ->assertJsonPath('data.source', 'individual')
        ->assertJsonPath('data.purchase_eligible', false)
        ->assertJsonPath('data.ineligibility_reason', 'membership_already_active');
});

test('GET /memberships/status blocks an active sponsored membership', function () {
    [$user, $token] = akubicaCustomerToken();
    createMembershipSubscription($user->customer, MedicalSubscriptionType::INSTITUTIONAL);

    $this->getJson('/api/v1/memberships/status', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.plan_code', null)
        ->assertJsonPath('data.source', 'sponsored')
        ->assertJsonPath('data.purchase_eligible', false)
        ->assertJsonPath('data.ineligibility_reason', 'sponsored_membership_active');
});

test('GET /memberships/status classifies an active family membership as sponsored', function () {
    [$user, $token] = akubicaCustomerToken();
    createMembershipSubscription($user->customer, MedicalSubscriptionType::FAMILY_MEMBER);

    $this->getJson('/api/v1/memberships/status', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.source', 'sponsored')
        ->assertJsonPath('data.ineligibility_reason', 'sponsored_membership_active')
        ->assertJsonPath('data.purchase_eligible', false);
});

test('GET /memberships/status allows purchase after an expired membership', function () {
    [$user, $token] = akubicaCustomerToken();
    createMembershipSubscription($user->customer, MedicalSubscriptionType::REGULAR, [
        'start_date' => now()->subYear()->subDay()->toDateString(),
        'end_date' => now()->subDay()->toDateString(),
    ]);

    $this->getJson('/api/v1/memberships/status', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'expired')
        ->assertJsonPath('data.purchase_eligible', true)
        ->assertJsonPath('data.ineligibility_reason', null);
});

test('GET /memberships/status requires authentication', function () {
    $this->getJson('/api/v1/memberships/status')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /memberships/status requires a customer owned by the authenticated user', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->getJson('/api/v1/memberships/status', authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});
