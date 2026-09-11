<?php

namespace Tests\Feature\Marketing;

use App\Actions\Marketing\AttachMarketingCampaignAttributionToCustomerAction;
use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkAlias;
use App\Models\MarketingCampaignVisit;
use App\Models\MarketingCampaignVisitorIdentity;
use App\Models\User;
use App\Services\Marketing\MarketingCampaignAttributionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/Unit/Marketing/marketingCampaignIsolatedSchema.php';

class MarketingCampaignAttributionTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        bootstrapIsolatedMarketingCampaignSchema();
        config([
            'marketing-attribution.enabled' => true,
            'marketing-attribution.window_days' => 30,
            'marketing-attribution.cookie_name' => 'famedic_campaign_attribution',
        ]);

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            \App\Http\Middleware\EncryptCookies::class,
        ]);
    }

    protected function tearDown(): void
    {
        tearDownIsolatedMarketingCampaignSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    private function makeActiveCampaign(array $attrs = []): MarketingCampaign
    {
        return MarketingCampaign::factory()->create(array_merge([
            'status' => MarketingCampaignStatus::Active,
            'starts_at' => null,
            'ends_at' => null,
        ], $attrs));
    }

    private function makeActiveLink(MarketingCampaign $campaign, array $attrs = []): MarketingCampaignLink
    {
        return MarketingCampaignLink::factory()->for($campaign, 'campaign')->create(array_merge([
            'status' => MarketingCampaignLinkStatus::Active,
            'slug' => 'promo-atribucion',
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            'utm_source' => 'default-source',
            'utm_medium' => 'default-medium',
            'utm_campaign' => 'default-campaign',
            'starts_at' => null,
            'ends_at' => null,
        ], $attrs));
    }

    private function cookieName(): string
    {
        return (string) config('marketing-attribution.cookie_name');
    }

    private function cookieValueFromResponse(\Illuminate\Testing\TestResponse $response): string
    {
        return $this->attributionCookieFromResponse($response)->getValue();
    }

    private function makeMarketingCustomerUser(): User
    {
        $user = User::factory()->create();

        Customer::query()->create([
            'user_id' => $user->id,
        ]);

        return $user->fresh('customer');
    }

    private function attributionRequestWithCookie(string $token): Request
    {
        $request = Request::create('/');
        $request->cookies->set($this->cookieName(), $token);

        return $request;
    }

    /**
     * @return array{token: string, attribution: MarketingCampaignAttribution, identity: MarketingCampaignVisitorIdentity, visit: MarketingCampaignVisit, campaign: MarketingCampaign, link: MarketingCampaignLink}
     */
    private function makeAnonymousAttributionCycle(?\DateTimeInterface $expiresAt = null): array
    {
        $campaign = $this->makeActiveCampaign();
        $link = $this->makeActiveLink($campaign, ['slug' => 'attach-'.strtolower(fake()->bothify('????-####'))]);
        $tokenService = app(MarketingCampaignAttributionTokenService::class);
        $token = $tokenService->generate();
        $tokenHash = $tokenService->hash($token);
        $identity = MarketingCampaignVisitorIdentity::query()->create([
            'visitor_token_hash' => $tokenHash,
        ]);
        $attribution = MarketingCampaignAttribution::query()->create([
            'visitor_token_hash' => $tokenHash,
            'marketing_campaign_visitor_identity_id' => $identity->id,
            'first_campaign_id' => $campaign->id,
            'first_link_id' => $link->id,
            'last_campaign_id' => $campaign->id,
            'last_link_id' => $link->id,
            'first_touched_at' => now(),
            'last_touched_at' => now(),
            'expires_at' => $expiresAt ?? now()->addDays(30),
        ]);
        $visit = MarketingCampaignVisit::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_link_id' => $link->id,
            'marketing_campaign_attribution_id' => $attribution->id,
            'visitor_token_hash' => $tokenHash,
            'marketing_campaign_visitor_identity_id' => $identity->id,
            'landing_path' => '/c/'.$link->slug,
            'visited_at' => now(),
            'created_at' => now(),
        ]);
        $attribution->update([
            'first_visit_id' => $visit->id,
            'last_visit_id' => $visit->id,
        ]);

        return compact('token', 'attribution', 'identity', 'visit', 'campaign', 'link');
    }

    private function attributionCookieFromResponse(\Illuminate\Testing\TestResponse $response): \Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $this->cookieName()) {
                return $cookie;
            }
        }

        $this->fail('Cookie de atribución no encontrada en la respuesta.');
    }

    #[Test]
    public function primera_visita_canonica_crea_attribution_visit_y_cookie(): void
    {
        $campaign = $this->makeActiveCampaign();
        $link = $this->makeActiveLink($campaign, ['slug' => 'primera-visita']);

        $response = $this->get(route('campaign-links.show', [
            'slug' => 'primera-visita',
            'utm_source' => 'facebook',
        ]));

        $response->assertOk()->assertCookie($this->cookieName());

        $this->assertSame(1, MarketingCampaignVisit::query()->count());
        $this->assertSame(1, MarketingCampaignAttribution::query()->count());
        $this->assertSame(1, MarketingCampaignVisitorIdentity::query()->count());

        $visit = MarketingCampaignVisit::query()->first();
        $attribution = MarketingCampaignAttribution::query()->first();
        $identity = MarketingCampaignVisitorIdentity::query()->first();

        $this->assertSame('facebook', $visit->utm_source);
        $this->assertSame('default-medium', $visit->utm_medium);
        $this->assertSame('/c/primera-visita', $visit->landing_path);
        $this->assertSame($visit->id, $attribution->first_visit_id);
        $this->assertSame($visit->id, $attribution->last_visit_id);
        $this->assertSame($identity->id, $attribution->marketing_campaign_visitor_identity_id);
        $this->assertSame($identity->id, $visit->marketing_campaign_visitor_identity_id);
        $this->assertSame($visit->visitor_token_hash, $identity->visitor_token_hash);
        $this->assertTrue($attribution->expires_at->greaterThan(now()->addDays(29)));

        $rawCookie = $this->cookieValueFromResponse($response);
        $this->assertNotSame($rawCookie, $visit->visitor_token_hash);
        $this->assertSame(64, strlen($visit->visitor_token_hash));
    }

    #[Test]
    public function cookie_de_atribucion_es_httponly_lax_secure_y_alineada_a_la_ventana(): void
    {
        config([
            'marketing-attribution.secure' => true,
            'marketing-attribution.cookie_path' => '/',
            'marketing-attribution.cookie_same_site' => 'lax',
        ]);

        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'cookie-segura']);

        $response = $this->get(route('campaign-links.show', ['slug' => 'cookie-segura']));

        $cookie = $this->attributionCookieFromResponse($response);
        $attribution = MarketingCampaignAttribution::query()->firstOrFail();

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertSame($attribution->expires_at->getTimestamp(), $cookie->getExpiresTime());
    }

    #[Test]
    public function segunda_visita_conserva_first_y_actualiza_last(): void
    {
        $campaign = $this->makeActiveCampaign();
        $linkA = $this->makeActiveLink($campaign, ['slug' => 'link-a', 'utm_source' => 'first']);
        $linkB = $this->makeActiveLink($campaign, ['slug' => 'link-b', 'utm_source' => 'second']);

        $first = $this->get(route('campaign-links.show', ['slug' => 'link-a']));

        $this->withUnencryptedCookies([
            $this->cookieName() => $this->cookieValueFromResponse($first),
        ])->get(route('campaign-links.show', [
            'slug' => 'link-b',
            'utm_source' => 'override',
        ]))->assertOk();

        $this->assertSame(2, MarketingCampaignVisit::query()->count());
        $attribution = MarketingCampaignAttribution::query()->first();

        $this->assertSame($linkA->id, $attribution->first_link_id);
        $this->assertSame($linkB->id, $attribution->last_link_id);
        $this->assertNotSame($attribution->first_visit_id, $attribution->last_visit_id);
        $this->assertSame('first', $attribution->firstVisit->utm_source);
        $this->assertSame('override', $attribution->lastVisit->utm_source);
    }

    #[Test]
    public function cada_touch_valido_renueva_la_ventana_movil_del_ciclo_activo(): void
    {
        $campaign = $this->makeActiveCampaign();
        $linkA = $this->makeActiveLink($campaign, ['slug' => 'mobile-window-a']);
        $linkB = $this->makeActiveLink($campaign, ['slug' => 'mobile-window-b']);

        $this->travelTo(now()->startOfSecond());
        $first = $this->get(route('campaign-links.show', ['slug' => $linkA->slug]));
        $firstExpiresAt = MarketingCampaignAttribution::query()->firstOrFail()->expires_at;
        $token = $this->cookieValueFromResponse($first);

        $this->travel(5)->days();

        $this->withUnencryptedCookies([$this->cookieName() => $token])
            ->get(route('campaign-links.show', ['slug' => $linkB->slug]))
            ->assertOk();

        $attribution = MarketingCampaignAttribution::query()->firstOrFail();

        $this->assertSame($linkA->id, $attribution->first_link_id);
        $this->assertSame($linkB->id, $attribution->last_link_id);
        $this->assertTrue($attribution->expires_at->greaterThan($firstExpiresAt));

        $this->travelBack();
    }

    #[Test]
    public function attribution_expirada_inicia_ciclo_nuevo_sin_reutilizar_vigencia(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'expired-cycle']);

        $token = app(MarketingCampaignAttributionTokenService::class)->generate();
        $hash = app(MarketingCampaignAttributionTokenService::class)->hash($token);

        MarketingCampaignAttribution::factory()->create([
            'visitor_token_hash' => $hash,
            'first_campaign_id' => $campaign->id,
            'first_link_id' => $this->makeActiveLink($campaign, ['slug' => 'old-link'])->id,
            'last_campaign_id' => $campaign->id,
            'last_link_id' => $this->makeActiveLink($campaign, ['slug' => 'old-link-2'])->id,
            'first_touched_at' => now()->subDays(40),
            'last_touched_at' => now()->subDays(40),
            'expires_at' => now()->subDay(),
        ]);

        $this->withCookie($this->cookieName(), $token)
            ->get(route('campaign-links.show', ['slug' => 'expired-cycle']))
            ->assertOk();

        $this->assertSame(2, MarketingCampaignAttribution::query()->where('visitor_token_hash', $hash)->count());
        $this->assertSame(1, MarketingCampaignAttribution::query()->where('expires_at', '>', now())->count());
    }

    #[Test]
    public function cookie_manipulada_reemplaza_atribucion_sin_error(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'manipulated']);

        $this->withCookie($this->cookieName(), 'token-manipulado-invalido-format!!!!')
            ->get(route('campaign-links.show', ['slug' => 'manipulated']))
            ->assertOk()
            ->assertCookie($this->cookieName());

        $this->assertSame(1, MarketingCampaignAttribution::query()->count());
    }

    #[Test]
    public function cookie_con_caracteres_validos_pero_formato_corto_se_rechaza_seguro(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'short-cookie']);

        $response = $this->withCookie($this->cookieName(), 'short-but-valid-chars')
            ->get(route('campaign-links.show', ['slug' => 'short-cookie']));

        $response->assertOk()->assertCookie($this->cookieName());

        $this->assertNotSame('short-but-valid-chars', $this->cookieValueFromResponse($response));
        $this->assertSame(1, MarketingCampaignVisitorIdentity::query()->count());
        $this->assertSame(1, MarketingCampaignAttribution::query()->count());
    }

    #[Test]
    public function attach_de_atribucion_asocia_ciclo_activo_y_sus_visitas_al_customer(): void
    {
        $cycle = $this->makeAnonymousAttributionCycle();
        $user = $this->makeMarketingCustomerUser();

        $status = app(AttachMarketingCampaignAttributionToCustomerAction::class)(
            $this->attributionRequestWithCookie($cycle['token']),
            $user,
            'test_register',
        );

        $attribution = $cycle['attribution']->fresh();
        $visit = $cycle['visit']->fresh();

        $this->assertSame(AttachMarketingCampaignAttributionToCustomerAction::STATUS_ATTACHED, $status);
        $this->assertSame($user->id, $attribution->user_id);
        $this->assertSame($user->customer->id, $attribution->customer_id);
        $this->assertSame($cycle['campaign']->id, $attribution->identified_campaign_id);
        $this->assertSame($cycle['link']->id, $attribution->identified_link_id);
        $this->assertSame($cycle['visit']->id, $attribution->identified_visit_id);
        $this->assertNotNull($attribution->identified_at);
        $this->assertSame($user->id, $visit->user_id);
        $this->assertSame($user->customer->id, $visit->customer_id);
    }

    #[Test]
    public function attach_de_atribucion_es_idempotente_para_el_mismo_customer(): void
    {
        $cycle = $this->makeAnonymousAttributionCycle();
        $user = $this->makeMarketingCustomerUser();
        $request = $this->attributionRequestWithCookie($cycle['token']);
        $action = app(AttachMarketingCampaignAttributionToCustomerAction::class);

        $this->assertSame(AttachMarketingCampaignAttributionToCustomerAction::STATUS_ATTACHED, $action($request, $user, 'test_register'));
        $identifiedAt = $cycle['attribution']->fresh()->identified_at;
        $this->assertSame(AttachMarketingCampaignAttributionToCustomerAction::STATUS_ATTACHED, $action($request, $user, 'test_login'));

        $this->assertSame(1, MarketingCampaignAttribution::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, MarketingCampaignVisit::query()->where('customer_id', $user->customer->id)->count());
        $this->assertTrue($identifiedAt->equalTo($cycle['attribution']->fresh()->identified_at));
    }

    #[Test]
    public function attach_de_atribucion_no_reasigna_si_el_ciclo_pertenece_a_otro_customer(): void
    {
        $cycle = $this->makeAnonymousAttributionCycle();
        $existing = $this->makeMarketingCustomerUser();
        $target = $this->makeMarketingCustomerUser();

        $cycle['attribution']->update([
            'user_id' => $existing->id,
            'customer_id' => $existing->customer->id,
        ]);

        $status = app(AttachMarketingCampaignAttributionToCustomerAction::class)(
            $this->attributionRequestWithCookie($cycle['token']),
            $target,
            'test_login',
        );

        $attribution = $cycle['attribution']->fresh();

        $this->assertSame(AttachMarketingCampaignAttributionToCustomerAction::STATUS_CONFLICT, $status);
        $this->assertSame($existing->id, $attribution->user_id);
        $this->assertSame($existing->customer->id, $attribution->customer_id);
        $this->assertNull($attribution->identified_at);
    }

    #[Test]
    public function attach_de_atribucion_no_reasigna_si_una_visita_del_ciclo_pertenece_a_otro_customer(): void
    {
        $cycle = $this->makeAnonymousAttributionCycle();
        $existing = $this->makeMarketingCustomerUser();
        $target = $this->makeMarketingCustomerUser();

        MarketingCampaignVisit::query()
            ->whereKey($cycle['visit']->id)
            ->update([
                'user_id' => $existing->id,
                'customer_id' => $existing->customer->id,
            ]);

        $status = app(AttachMarketingCampaignAttributionToCustomerAction::class)(
            $this->attributionRequestWithCookie($cycle['token']),
            $target,
            'test_login',
        );

        $this->assertSame(AttachMarketingCampaignAttributionToCustomerAction::STATUS_CONFLICT, $status);
        $this->assertNull($cycle['attribution']->fresh()->user_id);
        $this->assertNull($cycle['attribution']->fresh()->identified_at);
        $this->assertSame($existing->id, $cycle['visit']->fresh()->user_id);
    }

    #[Test]
    public function attach_de_atribucion_ignora_cookie_invalida_identity_inexistente_y_ciclo_expirado(): void
    {
        $cycle = $this->makeAnonymousAttributionCycle();
        $user = $this->makeMarketingCustomerUser();
        $action = app(AttachMarketingCampaignAttributionToCustomerAction::class);

        $this->assertSame(
            AttachMarketingCampaignAttributionToCustomerAction::STATUS_NO_COOKIE,
            $action($this->attributionRequestWithCookie('short'), $user, 'test_login'),
        );

        $missingToken = app(MarketingCampaignAttributionTokenService::class)->generate();
        $this->assertSame(
            AttachMarketingCampaignAttributionToCustomerAction::STATUS_NO_IDENTITY,
            $action($this->attributionRequestWithCookie($missingToken), $user, 'test_login'),
        );

        $cycle['attribution']->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(
            AttachMarketingCampaignAttributionToCustomerAction::STATUS_NO_ACTIVE_ATTRIBUTION,
            $action($this->attributionRequestWithCookie($cycle['token']), $user, 'test_login'),
        );
        $this->assertNull($cycle['attribution']->fresh()->user_id);
        $this->assertNull($cycle['attribution']->fresh()->identified_at);
        $this->assertNull($cycle['visit']->fresh()->customer_id);
    }

    #[Test]
    public function attach_de_atribucion_solo_identifica_visitas_del_ciclo_activo_actual(): void
    {
        $cycle = $this->makeAnonymousAttributionCycle();
        $campaign = $cycle['campaign'];
        $linkB = $this->makeActiveLink($campaign, ['slug' => 'attach-historico-b']);
        $activeAttribution = $cycle['attribution'];
        $identity = $cycle['identity'];
        $user = $this->makeMarketingCustomerUser();

        $expiredAttribution = MarketingCampaignAttribution::query()->create([
            'visitor_token_hash' => $activeAttribution->visitor_token_hash,
            'marketing_campaign_visitor_identity_id' => $identity->id,
            'first_campaign_id' => $campaign->id,
            'first_link_id' => $linkB->id,
            'last_campaign_id' => $campaign->id,
            'last_link_id' => $linkB->id,
            'first_touched_at' => now()->subDays(40),
            'last_touched_at' => now()->subDays(40),
            'expires_at' => now()->subDays(10),
        ]);

        $expiredVisit = MarketingCampaignVisit::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_link_id' => $linkB->id,
            'marketing_campaign_attribution_id' => $expiredAttribution->id,
            'visitor_token_hash' => $activeAttribution->visitor_token_hash,
            'marketing_campaign_visitor_identity_id' => $identity->id,
            'landing_path' => '/c/'.$linkB->slug,
            'visited_at' => now()->subDays(40),
            'created_at' => now()->subDays(40),
        ]);

        app(AttachMarketingCampaignAttributionToCustomerAction::class)(
            $this->attributionRequestWithCookie($cycle['token']),
            $user,
            'test_login',
        );

        $this->assertSame($user->id, $activeAttribution->fresh()->user_id);
        $this->assertSame($user->id, $activeAttribution->visits()->firstOrFail()->user_id);
        $this->assertNull($expiredAttribution->fresh()->user_id);
        $this->assertNull($expiredVisit->fresh()->user_id);
    }

    #[Test]
    public function alias_redirige_sin_visita_y_canonico_crea_una(): void
    {
        $campaign = $this->makeActiveCampaign();
        $link = $this->makeActiveLink($campaign, ['slug' => 'canonico-alias']);
        MarketingCampaignLinkAlias::factory()->create([
            'marketing_campaign_link_id' => $link->id,
            'slug' => 'alias-viejo',
        ]);

        $this->get(route('campaign-links.show', [
            'slug' => 'alias-viejo',
            'utm_source' => 'alias-query',
        ]))
            ->assertRedirect(route('campaign-links.show', [
                'slug' => 'canonico-alias',
                'utm_source' => 'alias-query',
            ]));

        $this->assertSame(0, MarketingCampaignVisit::query()->count());

        $this->get(route('campaign-links.show', [
            'slug' => 'canonico-alias',
            'utm_source' => 'alias-query',
        ]))->assertOk();

        $this->assertSame(1, MarketingCampaignVisit::query()->count());
        $this->assertSame('alias-query', MarketingCampaignVisit::query()->value('utm_source'));
    }

    #[Test]
    public function estados_no_disponibles_no_crean_tracking(): void
    {
        $campaign = $this->makeActiveCampaign(['status' => MarketingCampaignStatus::Paused]);
        $this->makeActiveLink($campaign, ['slug' => 'paused-no-track']);

        $this->get(route('campaign-links.show', ['slug' => 'paused-no-track']))
            ->assertOk();

        $this->assertSame(0, MarketingCampaignVisit::query()->count());
        $this->assertSame(0, MarketingCampaignAttribution::query()->count());
    }

    #[Test]
    public function feature_flag_deshabilitado_no_escribe_ni_cookie(): void
    {
        config(['marketing-attribution.enabled' => false]);

        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'flag-off']);

        $this->get(route('campaign-links.show', ['slug' => 'flag-off']))
            ->assertOk()
            ->assertCookieMissing($this->cookieName());

        $this->assertSame(0, MarketingCampaignVisit::query()->count());
    }

    #[Test]
    public function visita_es_historica_y_no_guarda_pii_ni_token_crudo(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'privacy']);

        $this->get(
            route('campaign-links.show', ['slug' => 'privacy', 'utm_source' => 'fb']),
            ['Referer' => 'https://facebook.com/path?email=test@example.com'],
        )->assertOk();

        $visit = MarketingCampaignVisit::query()->first();
        $this->assertSame('facebook.com', $visit->referrer_host);
        $this->assertStringNotContainsString('?', (string) $visit->referrer_host);
        $this->assertNull($visit->user_id);
        $this->assertNull($visit->customer_id);

        $columns = array_map(
            static fn ($column) => $column->name,
            DB::select('PRAGMA table_info(marketing_campaign_visits)'),
        );

        $this->assertNotContains('ip_address', $columns);
        $this->assertNotContains('full_url', $columns);
        $this->assertNotContains('visitor_token', $columns);

        $updated = $visit->update(['utm_source' => 'hack']);
        $this->assertFalse($updated);
        $this->assertSame('fb', $visit->fresh()->utm_source);
    }

    #[Test]
    public function cambios_posteriores_en_enlace_no_modifican_visitas_anteriores(): void
    {
        $campaign = $this->makeActiveCampaign();
        $link = $this->makeActiveLink($campaign, [
            'slug' => 'snapshot',
            'utm_source' => 'original',
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'snapshot']))->assertOk();

        $link->update(['utm_source' => 'cambiado']);

        $this->get(route('campaign-links.show', ['slug' => 'snapshot']))->assertOk();

        $sources = MarketingCampaignVisit::query()->orderBy('id')->pluck('utm_source')->all();
        $this->assertSame(['original', 'cambiado'], $sources);
    }

    #[Test]
    public function solo_una_attribution_vigente_por_token_hash(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'unique-active']);

        $token = app(MarketingCampaignAttributionTokenService::class)->generate();
        $hash = app(MarketingCampaignAttributionTokenService::class)->hash($token);
        $oldLink = $this->makeActiveLink($campaign, ['slug' => 'unique-old-a']);

        MarketingCampaignAttribution::factory()->count(2)->create([
            'visitor_token_hash' => $hash,
            'first_campaign_id' => $campaign->id,
            'first_link_id' => $oldLink->id,
            'last_campaign_id' => $campaign->id,
            'last_link_id' => $oldLink->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->withCookie($this->cookieName(), $token)
            ->get(route('campaign-links.show', ['slug' => 'unique-active']))
            ->assertOk();

        $this->assertSame(1, MarketingCampaignAttribution::query()
            ->where('visitor_token_hash', $hash)
            ->where('expires_at', '>', now())
            ->count());
    }
}
