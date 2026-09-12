<?php

use App\Actions\Marketing\AttachMarketingCampaignAttributionToCustomerAction;
use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignVisit;
use App\Models\User;

function authenticationAttributionCookieValue(\Illuminate\Testing\TestResponse $response): string
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === (string) config('marketing-attribution.cookie_name')) {
            return $cookie->getValue();
        }
    }

    test()->fail('Cookie de atribución no encontrada en la respuesta.');
}

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});

test('users can authenticate and receive anonymous marketing attribution', function () {
    config([
        'marketing-attribution.enabled' => true,
        'marketing-attribution.cookie_name' => 'famedic_campaign_attribution',
    ]);

    $user = User::factory()->withRegularCustomer()->create();
    $campaign = MarketingCampaign::factory()->create([
        'status' => MarketingCampaignStatus::Active,
        'starts_at' => null,
        'ends_at' => null,
    ]);
    $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
        'status' => MarketingCampaignLinkStatus::Active,
        'slug' => 'login-auth',
        'target_type' => MarketingCampaignTargetType::Brand,
        'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
        'starts_at' => null,
        'ends_at' => null,
    ]);
    $landing = $this->get(route('campaign-links.show', ['slug' => $link->slug]));

    $response = $this->withUnencryptedCookies([
        (string) config('marketing-attribution.cookie_name') => authenticationAttributionCookieValue($landing),
    ])->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));

    $attribution = MarketingCampaignAttribution::query()->firstOrFail();
    $visit = MarketingCampaignVisit::query()->firstOrFail();

    $this->assertSame($user->id, $attribution->user_id);
    $this->assertSame($user->customer->id, $attribution->customer_id);
    $this->assertSame($user->id, $visit->user_id);
    $this->assertSame($user->customer->id, $visit->customer_id);
});

test('marketing attribution failure does not block login', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $attach = Mockery::mock(AttachMarketingCampaignAttributionToCustomerAction::class);
    $attach->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('attach failed'));
    $this->app->instance(AttachMarketingCampaignAttributionToCustomerAction::class, $attach);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
