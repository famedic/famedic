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
    public function alias_redirige_302_al_canonico_conservando_utms_y_limpiando_extras(): void
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
        ]))->assertRedirect();
    }

    #[Test]
    public function brand_redirige_a_catalogo_sin_url_externa(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'brand-link',
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::JENNER->value],
        ]);

        $response = $this->get(route('campaign-links.show', [
            'slug' => 'brand-link',
            'utm_medium' => 'cpc',
            'hack' => 'x',
        ]));

        $response->assertRedirect(route('laboratory-tests', [
            'laboratory_brand' => LaboratoryBrand::JENNER->value,
            'utm_medium' => 'cpc',
        ]));

        $this->assertStringNotContainsString('http://evil', $response->headers->get('Location') ?? '');
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
    public function category_redirige_con_parametro_category_nombre(): void
    {
        $category = LaboratoryTestCategory::query()->create(['name' => 'Chequeos']);
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, [
            'slug' => 'cat-link',
            'target_type' => MarketingCampaignTargetType::Category,
            'target_payload' => [
                'brand' => LaboratoryBrand::OLAB->value,
                'laboratory_test_category_id' => $category->id,
            ],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'cat-link']))
            ->assertRedirect(route('laboratory-tests', [
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'category' => 'Chequeos',
            ]));
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
    public function product_redirige_usando_id_y_marca_del_modelo(): void
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
                'brand' => LaboratoryBrand::OLAB->value, // no debe usarse para invalidar si el producto existe
            ],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'product-link']))
            ->assertRedirect(route('laboratory-tests.test', [
                'laboratory_test' => $test->id,
            ]));
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
    public function collection_renderiza_inertia_con_orden_y_estado_vacio(): void
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
                ->component('MarketingCampaigns/Collection')
                ->where('public_title', 'Pack verano')
                ->where('campaign_name', 'Campaña colección')
                ->where('brand.value', LaboratoryBrand::OLAB->value)
                ->where('add_all_available', false)
                ->has('products', 2)
                ->where('products.0.name', 'Segundo')
                ->where('products.1.name', 'Primero')
                ->has('catalog_url'));

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
                ->component('MarketingCampaigns/Collection')
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
                ->component('MarketingCampaigns/Collection')
                ->has('products', 1)
                ->where('products.0.id', $valid->id));
    }

    #[Test]
    public function no_requiere_auth_y_no_escribe_visits_ni_attribution(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'public-ok']);

        $tablesBefore = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table'"))
            ->pluck('name')
            ->all();

        $this->assertNotContains('marketing_campaign_visits', $tablesBefore);
        $this->assertNotContains('marketing_attribution_touches', $tablesBefore);

        $this->get(route('campaign-links.show', ['slug' => 'public-ok']))
            ->assertRedirect();

        $this->assertFalse(
            collect(DB::select("SELECT name FROM sqlite_master WHERE type='table'"))
                ->pluck('name')
                ->contains(fn ($name) => str_contains((string) $name, 'attribution') || str_contains((string) $name, 'visit'))
        );
    }

    #[Test]
    public function slug_con_mayusculas_no_coincide_por_restriccion_de_ruta(): void
    {
        $campaign = $this->makeActiveCampaign();
        $this->makeActiveLink($campaign, ['slug' => 'promo-ok']);

        $this->get('/c/Promo-OK')->assertNotFound();
    }
}
