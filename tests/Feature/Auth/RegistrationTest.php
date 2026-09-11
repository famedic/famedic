<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Actions\Marketing\AttachMarketingCampaignAttributionToCustomerAction;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignVisit;

function registrationAttributionCookieValue(\Illuminate\Testing\TestResponse $response): string
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === (string) config('marketing-attribution.cookie_name')) {
            return $cookie->getValue();
        }
    }

    test()->fail('Cookie de atribución no encontrada en la respuesta.');
}

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'paternal_lastname' => 'Test paternal',
        'maternal_lastname' => 'Test maternal',
        'birth_date' => '1990-01-01',
        'gender' => 1,
        'email' => 'test@example.com',
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});

test('new users can register and receive anonymous marketing attribution', function () {
    config([
        'marketing-attribution.enabled' => true,
        'marketing-attribution.cookie_name' => 'famedic_campaign_attribution',
    ]);

    $campaign = MarketingCampaign::factory()->create([
        'status' => MarketingCampaignStatus::Active,
        'starts_at' => null,
        'ends_at' => null,
    ]);
    $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
        'status' => MarketingCampaignLinkStatus::Active,
        'slug' => 'registro-auth',
        'target_type' => MarketingCampaignTargetType::Brand,
        'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
        'starts_at' => null,
        'ends_at' => null,
    ]);
    $landing = $this->get(route('campaign-links.show', ['slug' => $link->slug]));

    $response = $this->withUnencryptedCookies([
        (string) config('marketing-attribution.cookie_name') => registrationAttributionCookieValue($landing),
    ])->post('/register', [
        'name' => 'Test User',
        'paternal_lastname' => 'Test paternal',
        'maternal_lastname' => 'Test maternal',
        'birth_date' => '1990-01-01',
        'gender' => 1,
        'email' => 'attributed-register@example.com',
        'phone' => '5512345679',
        'phone_country' => 'MX',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));

    $attribution = MarketingCampaignAttribution::query()->firstOrFail();
    $visit = MarketingCampaignVisit::query()->firstOrFail();

    $this->assertSame(auth()->id(), $attribution->user_id);
    $this->assertSame(auth()->user()->customer->id, $attribution->customer_id);
    $this->assertSame(auth()->id(), $visit->user_id);
    $this->assertSame(auth()->user()->customer->id, $visit->customer_id);
});

test('marketing attribution failure does not block registration', function () {
    $attach = Mockery::mock(AttachMarketingCampaignAttributionToCustomerAction::class);
    $attach->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('attach failed'));
    $this->app->instance(AttachMarketingCampaignAttributionToCustomerAction::class, $attach);

    $response = $this->post('/register', [
        'name' => 'Safe Register',
        'paternal_lastname' => 'Test paternal',
        'maternal_lastname' => 'Test maternal',
        'birth_date' => '1990-01-01',
        'gender' => 1,
        'email' => 'safe-register@example.com',
        'phone' => '5512345680',
        'phone_country' => 'MX',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});
