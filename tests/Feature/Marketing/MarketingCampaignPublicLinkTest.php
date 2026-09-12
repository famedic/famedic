<?php

namespace Tests\Feature\Marketing;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignCollectionItem;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkAlias;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/Unit/Marketing/marketingCampaignIsolatedSchema.php';

class MarketingCampaignPublicLinkTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        bootstrapIsolatedMarketingCampaignSchema();

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
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
            'slug' => 'promo-activa',
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            'starts_at' => null,
            'ends_at' => null,
        ], $attrs));
    }

    #[Test]
    public function slug_inexistente_devuelve_404(): void
    {
        $this->get(route('campaign-links.show', ['slug' => 'no-existe']))
            ->assertNotFound();
    }

    #[Test]
    public function draft_devuelve_404(): void
    {
        $campaign = $this->makeActiveCampaign(['status' => MarketingCampaignStatus::Draft]);
        $this->makeActiveLink($campaign, ['slug' => 'draft-link']);

        $this->get(route('campaign-links.show', ['slug' => 'draft-link']))
            ->assertNotFound();
    }

    #[Test]
    public function soft_deleted_devuelve_404(): void
    {
        $campaign = $this->makeActiveCampaign();
        $link = $this->makeActiveLink($campaign, ['slug' => 'deleted-link']);
        $link->delete();

        $this->get(route('campaign-links.show', ['slug' => 'deleted-link']))
            ->assertNotFound();
    }

    #[Test]
    public function programada_futura_muestra_upcoming(): void
    {
        $campaign = $this->makeActiveCampaign([
            'status' => MarketingCampaignStatus::Scheduled,
            'starts_at' => now()->addDay(),
        ]);
        $this->makeActiveLink($campaign, ['slug' => 'upcoming-link']);

        $this->get(route('campaign-links.show', ['slug' => 'upcoming-link']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Upcoming')
                ->has('catalog_url'));
    }

    #[Test]
    public function pausada_muestra_unavailable(): void
    {
        $campaign = $this->makeActiveCampaign(['status' => MarketingCampaignStatus::Paused]);
        $this->makeActiveLink($campaign, ['slug' => 'paused-link']);

        $this->get(route('campaign-links.show', ['slug' => 'paused-link']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Unavailable'));
    }

    #[Test]
    public function finalizada_y_archivada_muestran_expired(): void
    {
        $finished = $this->makeActiveCampaign(['status' => MarketingCampaignStatus::Finished]);
        $this->makeActiveLink($finished, ['slug' => 'finished-link']);

        $this->get(route('campaign-links.show', ['slug' => 'finished-link']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MarketingCampaigns/Expired'));

        $archived = $this->makeActiveCampaign(['status' => MarketingCampaignStatus::Archived]);
        $this->makeActiveLink($archived, ['slug' => 'archived-link']);

        $this->get(route('campaign-links.show', ['slug' => 'archived-link']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MarketingCampaigns/Expired'));
    }

    #[Test]
    public function alias_redirige_302_al_canonico_y_luego_renderiza_landing(): void
    {
        $campaign = $this->makeActiveCampaign();
        $link = $this->makeActiveLink($campaign, ['slug' => 'slug-actual']);
        MarketingCampaignLinkAlias::factory()->create([
            'marketing_campaign_link_id' => $link->id,
            'slug' => 'slug-anterior',
        ]);

        $this->get(route('campaign-links.show', [
            'slug' => 'slug-anterior',
            'utm_source' => 'facebook',
            'evil' => '1',
        ]))
            ->assertRedirect(route('campaign-links.show', [
                'slug' => 'slug-actual',
                'utm_source' => 'facebook',
            ]))
            ->assertStatus(302);

        $this->get(route('campaign-links.show', [
            'slug' => 'slug-actual',
            'utm_source' => 'facebook',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('link.slug', 'slug-actual'));
    }

    #[Test]
    public function brand_renderiza_landing_con_maximo_seis_productos_y_cta_interno(): void
    {
        $category = LaboratoryTestCategory::query()->create(['name' => 'General']);
        foreach (range(1, 8) as $index) {
            LaboratoryTest::factory()->create([
                'name' => sprintf('Jenner %02d', $index),
                'brand' => LaboratoryBrand::JENNER,
                'laboratory_test_category_id' => $category->id,
            ]);
        }

        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'brand-link',
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::JENNER->value],
            'show_prices' => true,
        ]);

        $expectedPrimaryUrl = route('laboratory-tests', [
            'laboratory_brand' => LaboratoryBrand::JENNER->value,
            'utm_medium' => 'cpc',
        ]);

        $this->get(route('campaign-links.show', [
            'slug' => 'brand-link',
            'utm_medium' => 'cpc',
            'hack' => 'x',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('brand.value', LaboratoryBrand::JENNER->value)
                ->where('content.show_prices', true)
                ->where('primary_action.url', $expectedPrimaryUrl)
                ->has('products', 6)
                ->missing('created_by'));

        $this->assertStringNotContainsString(
            'http://evil',
            $expectedPrimaryUrl
        );
    }

    #[Test]
    public function brand_invalida_devuelve_404(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'bad-brand',
            'target_payload' => ['brand' => 'no-brand'],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'bad-brand']))
            ->assertNotFound();
    }

    #[Test]
    public function category_renderiza_landing_con_cta_de_categoria(): void
    {
        $category = LaboratoryTestCategory::query()->create(['name' => 'Chequeos']);
        foreach (range(1, 14) as $index) {
            LaboratoryTest::factory()->create([
                'name' => sprintf('Cat %02d', $index),
                'brand' => LaboratoryBrand::OLAB,
                'laboratory_test_category_id' => $category->id,
            ]);
        }

        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'cat-link',
            'target_type' => MarketingCampaignTargetType::Category,
            'target_payload' => [
                'brand' => LaboratoryBrand::OLAB->value,
                'laboratory_test_category_id' => $category->id,
            ],
        ]);

        $expectedPrimaryUrl = route('laboratory-tests', [
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'category' => 'Chequeos',
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'cat-link']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('category.name', 'Chequeos')
                ->where('primary_action.url', $expectedPrimaryUrl)
                ->has('products', 12));
    }

    #[Test]
    public function category_inexistente_devuelve_404(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'cat-missing',
            'target_type' => MarketingCampaignTargetType::Category,
            'target_payload' => [
                'brand' => LaboratoryBrand::OLAB->value,
                'laboratory_test_category_id' => 999999,
            ],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'cat-missing']))
            ->assertNotFound();
    }

    #[Test]
    public function product_renderiza_landing_con_un_producto_y_cta_a_ficha(): void
    {
        $category = LaboratoryTestCategory::query()->create(['name' => 'General']);
        $test = LaboratoryTest::factory()->create([
            'brand' => LaboratoryBrand::AZTECA,
            'laboratory_test_category_id' => $category->id,
        ]);

        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'product-link',
            'target_type' => MarketingCampaignTargetType::Product,
            'target_payload' => [
                'laboratory_test_id' => $test->id,
                'brand' => LaboratoryBrand::OLAB->value,
            ],
        ]);

        $expectedPrimaryUrl = route('laboratory-tests.test', [
            'laboratory_test' => $test->id,
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'product-link']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->has('products', 1)
                ->where('products.0.id', $test->id)
                ->where('brand.value', LaboratoryBrand::AZTECA->value)
                ->where('primary_action.url', $expectedPrimaryUrl));
    }

    #[Test]
    public function producto_inexistente_o_soft_deleted_devuelve_404(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'missing-product',
            'target_type' => MarketingCampaignTargetType::Product,
            'target_payload' => ['laboratory_test_id' => 999999, 'brand' => LaboratoryBrand::OLAB->value],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'missing-product']))
            ->assertNotFound();

        $category = LaboratoryTestCategory::query()->create(['name' => 'X']);
        $test = LaboratoryTest::factory()->create([
            'brand' => LaboratoryBrand::OLAB,
            'laboratory_test_category_id' => $category->id,
        ]);
        $test->delete();

        $this->makeActiveLink($campaign, [
            'slug' => 'deleted-product',
            'target_type' => MarketingCampaignTargetType::Product,
            'target_payload' => ['laboratory_test_id' => $test->id, 'brand' => LaboratoryBrand::OLAB->value],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'deleted-product']))
            ->assertNotFound();
    }

    #[Test]
    public function collection_renderiza_landing_con_orden_y_estado_vacio(): void
    {
        $category = LaboratoryTestCategory::query()->create(['name' => 'Paquetes']);
        $first = LaboratoryTest::factory()->create([
            'name' => 'Primero',
            'brand' => LaboratoryBrand::OLAB,
            'laboratory_test_category_id' => $category->id,
        ]);
        $second = LaboratoryTest::factory()->create([
            'name' => 'Segundo',
            'brand' => LaboratoryBrand::OLAB,
            'laboratory_test_category_id' => $category->id,
        ]);

        $campaign = $this->makeActiveCampaign(['name' => 'Campaña colección']);
        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create([
            'public_title' => 'Pack verano',
            'public_description' => 'Desc',
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'is_active' => true,
        ]);

        MarketingCampaignCollectionItem::factory()->create([
            'marketing_campaign_collection_id' => $collection->id,
            'laboratory_test_id' => $second->id,
            'position' => 0,
        ]);
        MarketingCampaignCollectionItem::factory()->create([
            'marketing_campaign_collection_id' => $collection->id,
            'laboratory_test_id' => $first->id,
            'position' => 1,
        ]);

        $this->makeActiveLink($campaign, [
            'slug' => 'collection-link',
            'target_type' => MarketingCampaignTargetType::Collection,
            'target_payload' => ['marketing_campaign_collection_id' => $collection->id],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'collection-link']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('content.title', 'Pack verano')
                ->where('campaign.name', 'Campaña colección')
                ->where('brand.value', LaboratoryBrand::OLAB->value)
                ->has('products', 2)
                ->where('products.0.name', 'Segundo')
                ->where('products.1.name', 'Primero')
                ->has('primary_action.url'));

        $empty = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create([
            'public_title' => 'Vacía',
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'is_active' => true,
        ]);
        $this->makeActiveLink($campaign, [
            'slug' => 'empty-collection',
            'target_type' => MarketingCampaignTargetType::Collection,
            'target_payload' => ['marketing_campaign_collection_id' => $empty->id],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'empty-collection']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->has('products', 0));
    }

    #[Test]
    public function collection_inactiva_soft_deleted_u_otra_campana_devuelve_404(): void
    {
        $campaign = $this->makeActiveCampaign();
        $other = $this->makeActiveCampaign();

        $inactive = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create([
            'is_active' => false,
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $this->makeActiveLink($campaign, [
            'slug' => 'inactive-col',
            'target_type' => MarketingCampaignTargetType::Collection,
            'target_payload' => ['marketing_campaign_collection_id' => $inactive->id],
        ]);
        $this->get(route('campaign-links.show', ['slug' => 'inactive-col']))->assertNotFound();

        $deleted = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create([
            'is_active' => true,
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $deleted->delete();
        $this->makeActiveLink($campaign, [
            'slug' => 'deleted-col',
            'target_type' => MarketingCampaignTargetType::Collection,
            'target_payload' => ['marketing_campaign_collection_id' => $deleted->id],
        ]);
        $this->get(route('campaign-links.show', ['slug' => 'deleted-col']))->assertNotFound();

        $foreign = MarketingCampaignCollection::factory()->for($other, 'campaign')->create([
            'is_active' => true,
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $this->makeActiveLink($campaign, [
            'slug' => 'foreign-col',
            'target_type' => MarketingCampaignTargetType::Collection,
            'target_payload' => ['marketing_campaign_collection_id' => $foreign->id],
        ]);
        $this->get(route('campaign-links.show', ['slug' => 'foreign-col']))->assertNotFound();
    }

    #[Test]
    public function collection_filtra_productos_invalidos_sin_fallar(): void
    {
        $category = LaboratoryTestCategory::query()->create(['name' => 'Cat']);
        $valid = LaboratoryTest::factory()->create([
            'brand' => LaboratoryBrand::OLAB,
            'laboratory_test_category_id' => $category->id,
        ]);
        $wrongBrand = LaboratoryTest::factory()->create([
            'brand' => LaboratoryBrand::JENNER,
            'laboratory_test_category_id' => $category->id,
        ]);
        $deleted = LaboratoryTest::factory()->create([
            'brand' => LaboratoryBrand::OLAB,
            'laboratory_test_category_id' => $category->id,
        ]);

        $campaign = $this->makeActiveCampaign();
        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create([
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'is_active' => true,
            'public_title' => 'Mix',
        ]);

        MarketingCampaignCollectionItem::factory()->create([
            'marketing_campaign_collection_id' => $collection->id,
            'laboratory_test_id' => $wrongBrand->id,
            'position' => 0,
        ]);
        MarketingCampaignCollectionItem::factory()->create([
            'marketing_campaign_collection_id' => $collection->id,
            'laboratory_test_id' => $valid->id,
            'position' => 1,
        ]);
        MarketingCampaignCollectionItem::factory()->create([
            'marketing_campaign_collection_id' => $collection->id,
            'laboratory_test_id' => $deleted->id,
            'position' => 2,
        ]);
        $deleted->delete();

        $this->makeActiveLink($campaign, [
            'slug' => 'mixed-col',
            'target_type' => MarketingCampaignTargetType::Collection,
            'target_payload' => ['marketing_campaign_collection_id' => $collection->id],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'mixed-col']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->has('products', 1)
                ->where('products.0.id', $valid->id));
    }

    #[Test]
    public function hero_externo_no_aparece_en_landing(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'evil-hero',
            'hero_image_path' => 'https://evil.com/x.jpg',
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'evil-hero']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('content.hero_image', null));
    }

    #[Test]
    public function public_title_y_show_prices_false_se_reflejan_en_landing(): void
    {
        $campaign = $this->makeActiveCampaign(['name' => 'Nombre campaña']);
        $this->makeActiveLink($campaign, [
            'slug' => 'landing-overrides',
            'public_title' => 'Título público override',
            'show_prices' => false,
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'landing-overrides']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('content.title', 'Título público override')
                ->where('content.show_prices', false));
    }

    #[Test]
    public function landing_publica_captura_visita_con_atribucion_habilitada(): void
    {
        config(['marketing-attribution.enabled' => true]);

        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'public-ok']);

        $this->get(route('campaign-links.show', ['slug' => 'public-ok']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MarketingCampaigns/Landing'))
            ->assertCookie((string) config('marketing-attribution.cookie_name'));

        $this->assertSame(1, \App\Models\MarketingCampaignVisit::query()->count());
        $this->assertSame(1, \App\Models\MarketingCampaignAttribution::query()->count());
    }

    #[Test]
    public function landing_no_captura_cuando_atribucion_deshabilitada(): void
    {
        config(['marketing-attribution.enabled' => false]);

        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'public-flag-off']);

        $this->get(route('campaign-links.show', ['slug' => 'public-flag-off']))
            ->assertOk()
            ->assertCookieMissing((string) config('marketing-attribution.cookie_name'));

        $this->assertSame(0, \App\Models\MarketingCampaignVisit::query()->count());
    }

    #[Test]
    public function slug_con_mayusculas_no_coincide_por_restriccion_de_ruta(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'promo-ok']);

        $this->get('/c/Promo-OK')->assertNotFound();
    }

    #[Test]
    public function landing_expone_logo_url_de_marca(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'brand-logo',
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'brand-logo']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('brand.logo_url', '/images/gda/'.LaboratoryBrand::OLAB->imageSrc())
                ->where('brand.catalog_url', route('laboratory-tests', [
                    'laboratory_brand' => LaboratoryBrand::OLAB->value,
                ]))
                ->where('brand.stores_url', route('laboratory-stores.index', [
                    'brand' => LaboratoryBrand::OLAB->value,
                ])));
    }

    #[Test]
    public function require_auth_guarda_intended_y_redirige_a_login(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'auth-return',
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::SWISSLAB->value],
        ]);

        $this->get(route('campaign-links.require-auth', [
            'slug' => 'auth-return',
            'utm_source' => 'newsletter',
        ]))
            ->assertRedirect(route('login'));

        $this->assertSame(
            route('campaign-links.show', [
                'slug' => 'auth-return',
                'utm_source' => 'newsletter',
            ]),
            session('url.intended'),
        );
    }

    #[Test]
    public function landing_expone_props_de_carrito(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'cart-props',
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'cart-props']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('cart.add_url', route('laboratory-cart-items.store'))
                ->where('cart.requires_auth', true)
                ->where('can_add_to_cart', true)
                ->where('cart.login_url', route('campaign-links.require-auth', ['slug' => 'cart-props'])));
    }
}
