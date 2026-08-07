<?php

namespace Tests\Feature\Admin;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignHeroImageSource;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\Administrator;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkAlias;
use App\Models\Permission;
use App\Models\User;
use App\Support\Workspace\WorkspaceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/Unit/Marketing/marketingCampaignIsolatedSchema.php';

/**
 * CRUD admin Inertia sobre esquema aislado (evita migraciones históricas incompatibles con SQLite).
 */
class MarketingCampaignAdminTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        config(['permission.teams' => false]);
        bootstrapIsolatedMarketingCampaignSchema();
        $this->seedAdminNavigationPermissions();

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

    /**
     * EnsureUserHasAdminAccount llama hasPermissionTo para armar la nav.
     * Si el permiso no existe en DB, Spatie lanza PermissionDoesNotExist.
     * Solo se crean los registros; no se asignan al usuario salvo en makeMarketingAdmin.
     */
    private function seedAdminNavigationPermissions(): void
    {
        $names = [
            'administrators.manage',
            'laboratory-purchases.manage',
            'laboratory-tests.manage',
            'laboratory-purchases.manage.vendor-payments',
            'online-pharmacy-purchases.manage',
            'online-pharmacy-purchases.manage.vendor-payments',
            'medical-attention-subscriptions.manage',
            'marketing-campaigns.manage',
            'marketing-campaigns.manage.edit',
            'customers.manage',
            'coupons.manage',
            'documentation.manage',
            'simulators.manage',
            'logs-general.manage',
            'users.manage',
            'view carts',
            'efevoo-tokens.manage',
            'tax-profiles.manage',
            'payment-attempts.manage',
            'laboratory-notifications.monitor',
            'view_config_monitor',
            'activecampaign.manage',
            'automation.manage',
            'clinical-interpreter.manage',
            'monitoring-ai.manage',
        ];

        foreach ($names as $name) {
            Permission::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['permission_id' => null],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function makeMarketingAdmin(array $permissions = [
        'marketing-campaigns.manage',
        'marketing-campaigns.manage.edit',
    ]): User
    {
        $user = User::factory()->create();
        $administrator = Administrator::factory()->for($user)->create();

        $manage = Permission::query()->firstOrCreate(
            ['name' => 'marketing-campaigns.manage', 'guard_name' => 'web'],
            ['permission_id' => null],
        );
        $edit = Permission::query()->firstOrCreate(
            ['name' => 'marketing-campaigns.manage.edit', 'guard_name' => 'web'],
            ['permission_id' => $manage->id],
        );

        $map = [
            'marketing-campaigns.manage' => $manage,
            'marketing-campaigns.manage.edit' => $edit,
        ];

        foreach ($permissions as $permissionName) {
            $administrator->givePermissionTo($map[$permissionName] ?? Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]));
        }

        return $user->fresh()->load('administrator');
    }

    #[Test]
    public function autorizado_ve_index_con_props_minimas(): void
    {
        $admin = $this->makeMarketingAdmin(['marketing-campaigns.manage']);
        MarketingCampaign::factory()->create(['name' => 'Campaña demo']);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Index')
                ->has('campaigns.data', 1)
                ->has('filters')
                ->has('statusOptions')
                ->has('capabilities')
                ->where('capabilities.canView', true)
                ->where('capabilities.canCreate', false)
                ->where('capabilities.canEdit', false)
                ->where('capabilities.canArchive', false)
                ->where('campaigns.data.0.name', 'Campaña demo'));
    }

    #[Test]
    public function index_devuelve_campos_slim_por_campana(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create([
            'name' => 'Slim demo',
            'description' => 'No debe ir en index',
            'status' => MarketingCampaignStatus::Active,
        ]);
        MarketingCampaignLink::factory()->for($campaign, 'campaign')->create();
        MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Index')
                ->has('campaigns.data', 1)
                ->has('campaigns.data.0.id')
                ->has('campaigns.data.0.name')
                ->has('campaigns.data.0.status')
                ->has('campaigns.data.0.starts_at')
                ->has('campaigns.data.0.ends_at')
                ->has('campaigns.data.0.links_count')
                ->has('campaigns.data.0.collections_count')
                ->has('campaigns.data.0.created_at')
                ->has('campaigns.data.0.can_edit')
                ->has('campaigns.data.0.can_archive')
                ->where('campaigns.data.0.name', 'Slim demo')
                ->where('campaigns.data.0.links_count', 1)
                ->where('campaigns.data.0.collections_count', 1)
                ->where('campaigns.data.0.can_edit', true)
                ->where('campaigns.data.0.can_archive', true)
                ->where('capabilities.canCreate', true)
                ->where('capabilities.canEdit', true)
                ->where('capabilities.canArchive', true)
                ->where('capabilities.canView', true)
                ->missing('campaigns.data.0.description')
                ->missing('campaigns.data.0.deleted_at')
                ->missing('campaigns.data.0.created_by')
                ->missing('campaigns.data.0.updated_by'));
    }

    #[Test]
    public function sin_permiso_recibe_403_en_index(): void
    {
        $user = User::factory()->create();
        Administrator::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('admin.marketing-campaigns.index'))
            ->assertForbidden();
    }

    #[Test]
    public function filtros_de_index_se_conservan_en_props(): void
    {
        $admin = $this->makeMarketingAdmin(['marketing-campaigns.manage']);
        MarketingCampaign::factory()->create([
            'name' => 'Alpha activa',
            'status' => MarketingCampaignStatus::Active,
            'starts_at' => '2026-08-01 10:00:00',
        ]);
        MarketingCampaign::factory()->create([
            'name' => 'Beta borrador',
            'status' => MarketingCampaignStatus::Draft,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.index', [
                'search' => 'Alpha',
                'status' => 'active',
                'sort' => 'name',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Index')
                ->has('campaigns.data', 1)
                ->where('filters.search', 'Alpha')
                ->where('filters.status', 'active')
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc'));
    }

    #[Test]
    public function sort_invalido_es_rechazado(): void
    {
        $admin = $this->makeMarketingAdmin(['marketing-campaigns.manage']);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.index', [
                'sort' => 'drop table;',
                'direction' => 'desc',
            ]))
            ->assertSessionHasErrors('sort');
    }

    #[Test]
    public function puede_crear_y_almacenar_campana(): void
    {
        $admin = $this->makeMarketingAdmin();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Create')
                ->has('statusOptions'));

        $response = $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.store'), [
                'name' => 'Nueva campaña',
                'description' => 'Descripción',
                'status' => MarketingCampaignStatus::Draft->value,
                'starts_at' => null,
                'ends_at' => null,
            ]);

        $campaign = MarketingCampaign::query()->where('name', 'Nueva campaña')->first();
        $this->assertNotNull($campaign);
        $response->assertRedirect(route('admin.marketing-campaigns.show', $campaign))
            ->assertSessionHas('flashMessage.message', 'Campaña creada.');
    }

    #[Test]
    public function puede_editar_y_actualizar_campana(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create(['name' => 'Original']);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.edit', $campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Edit')
                ->where('campaign.name', 'Original')
                ->where('capabilities.canEdit', true)
                ->where('capabilities.canView', true));

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.update', $campaign), [
                'name' => 'Actualizada',
                'description' => null,
                'status' => MarketingCampaignStatus::Active->value,
                'starts_at' => null,
                'ends_at' => null,
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign))
            ->assertSessionHas('flashMessage.message', 'Campaña actualizada.');

        $this->assertSame('Actualizada', $campaign->fresh()->name);
    }

    #[Test]
    public function archivar_es_idempotente_y_no_soft_delete(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create([
            'status' => MarketingCampaignStatus::Active,
        ]);
        $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create();
        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.archive', $campaign))
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign))
            ->assertSessionHas('flashMessage.message', 'Campaña archivada.');

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.archive', $campaign))
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign))
            ->assertSessionHas('flashMessage.message', 'Campaña archivada.');

        $this->assertSame(MarketingCampaignStatus::Archived, $campaign->fresh()->status);
        $this->assertNull($campaign->fresh()->deleted_at);
        $this->assertNotNull($link->fresh());
        $this->assertNotNull($collection->fresh());
    }

    #[Test]
    public function campana_archivada_permite_ver_y_bloquea_mutaciones(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create([
            'status' => MarketingCampaignStatus::Archived,
        ]);
        $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'slug' => 'link-archivado',
        ]);
        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create([
            'name' => 'Colección archivada',
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Show')
                ->where('capabilities.canView', true)
                ->where('capabilities.canEdit', false)
                ->where('capabilities.canCreateLink', false)
                ->where('capabilities.canCreateCollection', false)
                ->where('capabilities.canArchive', false));

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.edit', $campaign))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.update', $campaign), [
                'name' => 'No debe actualizar',
                'description' => null,
                'status' => MarketingCampaignStatus::Active->value,
                'starts_at' => null,
                'ends_at' => null,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.links.create', $campaign))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Nuevo link',
                'slug' => 'nuevo-link-archivado',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.links.update', [$campaign, $link]), [
                'name' => 'Link editado',
                'slug' => 'link-archivado',
                'status' => MarketingCampaignLinkStatus::Active->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.collections.create', $campaign))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.collections.store', $campaign), [
                'name' => 'Nueva colección',
                'public_title' => 'Título',
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [],
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.collections.update', [$campaign, $collection]), [
                'name' => 'Colección editada',
                'public_title' => 'Título',
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [],
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.archive', $campaign))
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign))
            ->assertSessionHas('flashMessage.message', 'Campaña archivada.');

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.archive', $campaign))
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign))
            ->assertSessionHas('flashMessage.message', 'Campaña archivada.');

        $this->assertFalse(Route::has('admin.marketing-campaigns.destroy'));
        $this->assertSame(MarketingCampaignStatus::Archived, $campaign->fresh()->status);
    }

    #[Test]
    public function no_existe_ruta_destroy(): void
    {
        $this->assertFalse(Route::has('admin.marketing-campaigns.destroy'));
    }

    #[Test]
    public function show_incluye_enlaces_colecciones_y_capabilities(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();
        MarketingCampaignLink::factory()->for($campaign, 'campaign')->create(['name' => 'Link show']);
        MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create(['name' => 'Colección show']);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Show')
                ->has('links', 1)
                ->has('collections', 1)
                ->where('capabilities.canView', true)
                ->where('capabilities.canEdit', true)
                ->where('capabilities.canCreateLink', true)
                ->where('capabilities.canCreateCollection', true)
                ->where('capabilities.canArchive', true));
    }

    #[Test]
    public function puede_crear_y_actualizar_enlaces_con_aliases_en_props(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.links.create', $campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Links/Create')
                ->has('brands')
                ->has('categories')
                ->has('collections')
                ->has('targetTypeOptions'));

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Link UTM',
                'slug' => 'link-utm-demo',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'utm_source' => 'facebook',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'verano',
                'utm_term' => null,
                'utm_content' => null,
                'starts_at' => null,
                'ends_at' => null,
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign))
            ->assertSessionHas('flashMessage.message', 'Enlace creado.');

        $link = MarketingCampaignLink::query()->where('slug', 'link-utm-demo')->first();
        $this->assertNotNull($link);
        $this->assertSame('facebook', $link->utm_source);

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.links.update', [$campaign, $link]), [
                'name' => 'Link UTM',
                'slug' => 'link-utm-nuevo',
                'status' => MarketingCampaignLinkStatus::Active->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'utm_source' => 'facebook',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'verano',
                'starts_at' => null,
                'ends_at' => null,
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.links.edit', [$campaign, $link->fresh()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Links/Edit')
                ->has('link.aliases', 1)
                ->where('link.aliases.0.slug', 'link-utm-demo'));
    }

    #[Test]
    public function puede_crear_y_editar_contenido_de_landing_y_rechaza_valores_invalidos(): void
    {
        Storage::fake('local');
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();
        $heroUpload = UploadedFile::fake()->image('hero.jpg');

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Link landing',
                'slug' => 'link-landing-demo',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'public_title' => 'Título landing',
                'public_subtitle' => 'Subtítulo landing',
                'public_description' => 'Descripción landing',
                'eyebrow' => 'Promo',
                'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                'hero_image' => $heroUpload,
                'hero_image_alt' => 'Hero principal',
                'primary_cta_label' => 'Ver ahora',
                'secondary_cta_label' => 'Explorar',
                'show_prices' => false,
                'show_brand_logo' => true,
                'show_campaign_dates' => true,
                'landing_layout' => 'default',
                'starts_at' => null,
                'ends_at' => null,
                'gallery_items' => json_encode([]),
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign))
            ->assertSessionHas('flashMessage.message', 'Enlace creado.');

        $link = MarketingCampaignLink::query()->where('slug', 'link-landing-demo')->first();
        $this->assertNotNull($link);
        $this->assertSame('Título landing', $link->public_title);
        $this->assertFalse((bool) $link->show_prices);
        $this->assertTrue((bool) $link->show_campaign_dates);
        $this->assertSame(MarketingCampaignHeroImageSource::Upload, $link->hero_image_source);
        $this->assertNotNull($link->hero_image_path);
        Storage::disk('local')->assertExists($link->hero_image_path);

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Layout inválido',
                'slug' => 'link-layout-invalido',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'landing_layout' => 'custom-freeform',
                'gallery_items' => json_encode([]),
            ])
            ->assertSessionHasErrors('landing_layout');

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Hero externo inválido',
                'slug' => 'link-hero-externo',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::External->value,
                'hero_image_url' => 'http://inseguro.example.com/x.jpg',
                'gallery_items' => json_encode([]),
            ])
            ->assertSessionHasErrors('hero_image_url');

        $replacementUpload = UploadedFile::fake()->image('hero-v2.jpg');

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.links.update', [$campaign, $link]), [
                'name' => 'Link landing',
                'slug' => 'link-landing-demo',
                'status' => MarketingCampaignLinkStatus::Active->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'public_title' => 'Título editado',
                'public_subtitle' => 'Sub editado',
                'public_description' => 'Desc editada',
                'eyebrow' => 'Promo editada',
                'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                'hero_image' => $replacementUpload,
                'hero_image_alt' => 'Hero editado',
                'primary_cta_label' => 'CTA editado',
                'secondary_cta_label' => null,
                'show_prices' => true,
                'show_brand_logo' => false,
                'show_campaign_dates' => false,
                'landing_layout' => 'default',
                'starts_at' => null,
                'ends_at' => null,
                'gallery_items' => json_encode([]),
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $previousPath = $link->hero_image_path;
        $link->refresh();
        $this->assertSame('Título editado', $link->public_title);
        $this->assertTrue((bool) $link->show_prices);
        $this->assertFalse((bool) $link->show_brand_logo);
        $this->assertSame(MarketingCampaignHeroImageSource::Upload, $link->hero_image_source);
        $this->assertNotSame($previousPath, $link->hero_image_path);
        Storage::disk('local')->assertExists($link->hero_image_path);
        Storage::disk('local')->assertMissing($previousPath);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.links.edit', [$campaign, $link]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Links/Edit')
                ->where('link.public_title', 'Título editado')
                ->where('link.show_prices', true)
                ->where('link.show_brand_logo', false)
                ->where('link.hero_image_source', MarketingCampaignHeroImageSource::Upload->value)
                ->where('link.landing_layout', 'default'));
    }

    #[Test]
    public function rechaza_slug_duplicado_y_alias_historico(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();
        MarketingCampaignLink::factory()->for($campaign, 'campaign')->create(['slug' => 'ocupado']);
        MarketingCampaignLinkAlias::factory()->create(['slug' => 'alias-historico']);

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Dup',
                'slug' => 'ocupado',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            ])
            ->assertSessionHasErrors('slug');

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Alias',
                'slug' => 'alias-historico',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            ])
            ->assertSessionHasErrors('slug');
    }

    #[Test]
    public function rechaza_payload_invalido_de_destino(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Inválido',
                'slug' => 'payload-invalido',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => 'no-existe'],
            ])
            ->assertSessionHasErrors();
    }

    #[Test]
    public function impide_acceso_cruzado_a_enlaces(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaignA = MarketingCampaign::factory()->create();
        $campaignB = MarketingCampaign::factory()->create();
        $link = MarketingCampaignLink::factory()->for($campaignA, 'campaign')->create();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.links.edit', [
                'marketing_campaign' => $campaignB,
                'marketing_campaign_link' => $link,
            ]))
            ->assertNotFound();
    }

    #[Test]
    public function enlace_sin_permiso_de_edicion_recibe_403(): void
    {
        $admin = $this->makeMarketingAdmin(['marketing-campaigns.manage']);
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.links.create', $campaign))
            ->assertForbidden();
    }

    #[Test]
    public function puede_crear_coleccion_vacia_y_actualizar_monomarca_con_reorden(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();
        $first = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $second = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $other = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::JENNER]);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.collections.create', $campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Collections/Create')
                ->has('brands')
                ->has('productSearchUrl'));

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.collections.store', $campaign), [
                'name' => 'Colección vacía',
                'public_title' => 'Título',
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [],
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign))
            ->assertSessionHas('flashMessage.message', 'Colección creada.');

        $collection = MarketingCampaignCollection::query()->where('name', 'Colección vacía')->first();
        $this->assertNotNull($collection);
        $this->assertSame(0, $collection->items()->count());

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.collections.update', [$campaign, $collection]), [
                'name' => 'Colección llena',
                'public_title' => 'Título',
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [$second->id, $first->id],
            ])
            ->assertRedirect(route('admin.marketing-campaigns.collections.edit', [$campaign, $collection]));

        $this->assertSame(
            [$second->id, $first->id],
            $collection->fresh()->orderedItems()->pluck('laboratory_test_id')->all()
        );

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.collections.update', [$campaign, $collection]), [
                'name' => 'Colección llena',
                'public_title' => 'Título',
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [$first->id, $first->id],
            ])
            ->assertSessionHasErrors('laboratory_test_ids');

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.collections.update', [$campaign, $collection]), [
                'name' => 'Colección llena',
                'public_title' => 'Título',
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [$other->id],
            ])
            ->assertSessionHasErrors('laboratory_test_ids');
    }

    #[Test]
    public function impide_acceso_cruzado_a_colecciones(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaignA = MarketingCampaign::factory()->create();
        $campaignB = MarketingCampaign::factory()->create();
        $collection = MarketingCampaignCollection::factory()->for($campaignA, 'campaign')->create();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.collections.edit', [
                'marketing_campaign' => $campaignB,
                'marketing_campaign_collection' => $collection,
            ]))
            ->assertNotFound();
    }

    #[Test]
    public function coleccion_sin_permiso_de_edicion_recibe_403(): void
    {
        $admin = $this->makeMarketingAdmin(['marketing-campaigns.manage']);
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.collections.create', $campaign))
            ->assertForbidden();
    }

    #[Test]
    public function navegacion_visible_con_manage_y_catalogo_workspace(): void
    {
        $withManage = $this->makeMarketingAdmin(['marketing-campaigns.manage']);

        $response = $this->actingAs($withManage)
            ->get(route('admin.marketing-campaigns.index'))
            ->assertOk();

        $navigation = $response->original->getData()['page']['props']['adminNavigation'] ?? [];
        $hasLabel = collect($navigation)
            ->flatMap(fn ($section) => $section['items'] ?? [])
            ->contains(fn ($item) => ($item['label'] ?? null) === 'Campañas y enlaces');
        $this->assertTrue($hasLabel);

        $without = User::factory()->create();
        Administrator::factory()->for($without)->create();
        $this->actingAs($without)
            ->get(route('admin.marketing-campaigns.index'))
            ->assertForbidden();

        $workspace = collect(WorkspaceCatalog::workspaces())->firstWhere('slug', 'marketing');
        $this->assertNotNull($workspace);
        $tool = collect($workspace['tools'] ?? [])->firstWhere('id', 'marketing-campaigns');
        $this->assertSame('admin.marketing-campaigns.index', $tool['route'] ?? null);
        $this->assertContains('marketing-campaigns.manage', $tool['permissions'] ?? []);
    }

    #[Test]
    public function wizard_create_carga_props_guiadas(): void
    {
        $admin = $this->makeMarketingAdmin();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Create')
                ->has('statusOptions')
                ->has('linkStatusOptions')
                ->has('brands')
                ->has('categories')
                ->has('productSearchUrl')
                ->has('utmPresets')
                ->has('promotionOptions'));
    }

    #[Test]
    public function setup_crea_campana_enlace_y_coleccion_inline_en_transaccion(): void
    {
        $admin = $this->makeMarketingAdmin();
        $first = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $second = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.setup.store'), [
                'activate' => false,
                'campaign' => [
                    'name' => 'Campaña wizard',
                    'description' => 'Interna',
                    'status' => MarketingCampaignStatus::Draft->value,
                    'starts_at' => null,
                    'ends_at' => null,
                ],
                'collection' => [
                    'name' => 'Colección inline',
                    'public_title' => 'Estudios OLAB',
                    'public_description' => 'Descripción pública',
                    'laboratory_brand' => LaboratoryBrand::OLAB->value,
                    'is_active' => true,
                    'laboratory_test_ids' => [$first->id, $second->id],
                ],
                'link' => [
                    'name' => 'Enlace principal',
                    'slug' => 'campana-wizard-olab',
                    'status' => MarketingCampaignLinkStatus::Draft->value,
                    'target_type' => MarketingCampaignTargetType::Collection->value,
                    'target_payload' => [],
                    'public_title' => 'Estudios OLAB',
                    'utm_source' => 'facebook',
                    'utm_medium' => 'paid_social',
                    'show_prices' => true,
                    'show_brand_logo' => true,
                    'show_campaign_dates' => false,
                    'landing_layout' => 'default',
                    'hero_image_source' => MarketingCampaignHeroImageSource::None->value,
                    'primary_laboratory_test_ids' => [],
                    'related_laboratory_test_ids' => [],
                    'related_category_ids' => [],
                    'gallery_items' => '[]',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('flashMessage.message', 'Campaña y enlace creados.');

        $campaign = MarketingCampaign::query()->where('name', 'Campaña wizard')->first();
        $this->assertNotNull($campaign);
        $this->assertSame(1, $campaign->links()->count());
        $this->assertSame(1, $campaign->collections()->count());

        $collection = $campaign->collections()->first();
        $link = $campaign->links()->first();
        $this->assertSame(
            $collection->id,
            (int) ($link->target_payload['marketing_campaign_collection_id'] ?? 0)
        );
    }

    #[Test]
    public function puede_duplicar_enlace_como_borrador_con_slug_unico(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();
        $source = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'name' => 'Enlace original',
            'slug' => 'enlace-original',
            'status' => MarketingCampaignLinkStatus::Active,
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            'utm_source' => 'email',
            'utm_medium' => 'email',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.duplicate', [$campaign, $source]))
            ->assertRedirect()
            ->assertSessionHas('flashMessage.message', 'Enlace duplicado como borrador.');

        $duplicate = MarketingCampaignLink::query()
            ->where('marketing_campaign_id', $campaign->id)
            ->where('slug', '!=', $source->slug)
            ->first();

        $this->assertNotNull($duplicate);
        $this->assertSame(MarketingCampaignLinkStatus::Draft, $duplicate->status);
        $this->assertSame('Copia de Enlace original', $duplicate->name);
        $this->assertSame('email', $duplicate->utm_source);
        $this->assertSame(2, $campaign->fresh()->links()->count());
    }

    #[Test]
    public function show_incluye_dashboard_checklist_y_urls_publicas(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create([
            'name' => 'Dashboard campaña',
            'status' => MarketingCampaignStatus::Draft,
        ]);
        MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'slug' => 'dashboard-link',
            'public_title' => 'Landing dashboard',
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Show')
                ->has('summary')
                ->has('checklist', 8)
                ->where('links.0.public_url', url('/c/dashboard-link'))
                ->where('summary.links_count', 1));
    }

    #[Test]
    public function edit_coleccion_carga_productos_ordenados_y_enlaces_que_la_usan(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();
        $first = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $second = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);

        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create([
            'name' => 'Colección usada',
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $collection->laboratoryTests()->sync([
            $second->id => ['position' => 0],
            $first->id => ['position' => 1],
        ]);

        $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'slug' => 'link-coleccion-usada',
            'target_type' => MarketingCampaignTargetType::Collection,
            'target_payload' => ['marketing_campaign_collection_id' => $collection->id],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.collections.edit', [$campaign, $collection]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MarketingCampaigns/Collections/Edit')
                ->has('selectedItems', 2)
                ->where('selectedItems.0.id', $second->id)
                ->where('selectedItems.1.id', $first->id)
                ->where('usingLinksCount', 1)
                ->where('usingLinks.0.id', $link->id)
                ->has('maxCollectionItems'));

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.collections.create', $campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('maxCollectionItems'));
    }

    #[Test]
    public function puede_crear_coleccion_inline_via_json(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();
        $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.marketing-campaigns.collections.store', $campaign), [
                'name' => 'Inline JSON',
                'public_title' => 'Inline',
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [$test->id],
            ])
            ->assertOk()
            ->assertJsonPath('collection.name', 'Inline JSON');

        $collectionId = $response->json('collection.id');
        $this->assertNotNull($collectionId);
        $this->assertSame(1, MarketingCampaignCollection::query()->find($collectionId)->items()->count());
    }

    #[Test]
    public function create_link_compensa_hero_si_falla_la_galeria(): void
    {
        Storage::fake('local');
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $beforeLinks = MarketingCampaignLink::query()->count();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Enlace con hero',
                'slug' => 'enlace-hero-cleanup',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                'hero_image' => UploadedFile::fake()->image('hero.jpg'),
                'gallery_items' => json_encode([
                    ['kind' => 'invalid'],
                ]),
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
            ])
            ->assertSessionHasErrors();

        $this->assertSame($beforeLinks, MarketingCampaignLink::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function puede_crear_coleccion_con_titulo_autogenerado_desde_nombre(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.collections.store', $campaign), [
                'name' => 'Colección autotítulo',
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [],
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $collection = MarketingCampaignCollection::query()
            ->where('name', 'Colección autotítulo')
            ->first();

        $this->assertNotNull($collection);
        $this->assertSame('Colección autotítulo', $collection->public_title);
    }

    #[Test]
    public function puede_crear_coleccion_con_titulo_manual_distinto(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.collections.store', $campaign), [
                'name' => 'Nombre interno',
                'public_title' => 'Título visible al público',
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [],
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $collection = MarketingCampaignCollection::query()
            ->where('name', 'Nombre interno')
            ->first();

        $this->assertNotNull($collection);
        $this->assertSame('Título visible al público', $collection->public_title);
    }

    #[Test]
    public function update_coleccion_cambio_marca_limpia_productos_con_payload_vacio(): void
    {
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();
        $olabTest = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.collections.store', $campaign), [
                'name' => 'Colección con estudios',
                'public_title' => 'Colección con estudios',
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
                'is_active' => true,
                'laboratory_test_ids' => [$olabTest->id],
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $collection = MarketingCampaignCollection::query()
            ->where('name', 'Colección con estudios')
            ->first();

        $this->assertNotNull($collection);
        $this->assertSame(1, $collection->items()->count());

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.collections.update', [$campaign, $collection]), [
                'name' => 'Colección con estudios',
                'public_title' => 'Colección con estudios',
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::JENNER->value,
                'is_active' => true,
                'laboratory_test_ids' => [],
            ])
            ->assertRedirect(route('admin.marketing-campaigns.collections.edit', [$campaign, $collection]));

        $collection->refresh();
        $this->assertSame(LaboratoryBrand::JENNER, $collection->laboratory_brand);
        $this->assertSame(0, $collection->items()->count());
    }

    #[Test]
    public function update_link_compensa_hero_nuevo_y_conserva_anterior_si_falla_galeria(): void
    {
        Storage::fake('local');
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Enlace base',
                'slug' => 'enlace-update-cleanup',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                'hero_image' => UploadedFile::fake()->image('hero-original.jpg'),
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
                'gallery_items' => json_encode([]),
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $link = MarketingCampaignLink::query()->where('slug', 'enlace-update-cleanup')->firstOrFail();
        $originalPath = $link->hero_image_path;
        Storage::disk('local')->assertExists($originalPath);

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.links.update', [$campaign, $link]), [
                'name' => 'Enlace base',
                'slug' => 'enlace-update-cleanup',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                'hero_image' => UploadedFile::fake()->image('hero-nuevo.jpg'),
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
                'gallery_items' => json_encode([
                    ['kind' => 'invalid'],
                ]),
            ])
            ->assertSessionHasErrors();

        $link->refresh();
        $this->assertSame($originalPath, $link->hero_image_path);
        Storage::disk('local')->assertExists($originalPath);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function update_link_exitoso_reemplaza_hero_y_elimina_anterior_cuando_no_es_compartido(): void
    {
        Storage::fake('local');
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Enlace reemplazo',
                'slug' => 'enlace-reemplazo-hero',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                'hero_image' => UploadedFile::fake()->image('hero-original.jpg'),
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
                'gallery_items' => json_encode([]),
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $link = MarketingCampaignLink::query()->where('slug', 'enlace-reemplazo-hero')->firstOrFail();
        $originalPath = $link->hero_image_path;

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.links.update', [$campaign, $link]), [
                'name' => 'Enlace reemplazo',
                'slug' => 'enlace-reemplazo-hero',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                'hero_image' => UploadedFile::fake()->image('hero-nuevo.jpg'),
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
                'gallery_items' => json_encode([]),
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $link->refresh();
        $this->assertNotSame($originalPath, $link->hero_image_path);
        Storage::disk('local')->assertMissing($originalPath);
        Storage::disk('local')->assertExists($link->hero_image_path);
    }

    #[Test]
    public function update_link_no_elimina_hero_compartido_por_duplicado(): void
    {
        Storage::fake('local');
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Enlace original',
                'slug' => 'enlace-compartido-hero',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                'hero_image' => UploadedFile::fake()->image('hero-compartido.jpg'),
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
                'gallery_items' => json_encode([]),
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $link = MarketingCampaignLink::query()->where('slug', 'enlace-compartido-hero')->firstOrFail();
        $sharedPath = $link->hero_image_path;

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.duplicate', [$campaign, $link]))
            ->assertRedirect();

        $duplicate = MarketingCampaignLink::query()
            ->where('id', '!=', $link->id)
            ->where('marketing_campaign_id', $campaign->id)
            ->first();

        $this->assertNotNull($duplicate);
        $this->assertSame($sharedPath, $duplicate->hero_image_path);

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.links.update', [$campaign, $link]), [
                'name' => 'Enlace original',
                'slug' => 'enlace-compartido-hero',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                'hero_image' => UploadedFile::fake()->image('hero-nuevo-compartido.jpg'),
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
                'gallery_items' => json_encode([]),
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $link->refresh();
        $this->assertNotSame($sharedPath, $link->hero_image_path);
        Storage::disk('local')->assertExists($sharedPath);
        Storage::disk('local')->assertExists($link->hero_image_path);
    }

    #[Test]
    public function update_link_galeria_nueva_compensa_archivos_si_falla_despues(): void
    {
        Storage::fake('local');
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Enlace galería',
                'slug' => 'enlace-galeria-cleanup',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::None->value,
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
                'gallery_items' => json_encode([]),
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $link = MarketingCampaignLink::query()->where('slug', 'enlace-galeria-cleanup')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.links.update', [$campaign, $link]), [
                'name' => 'Enlace galería',
                'slug' => 'enlace-galeria-cleanup',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::None->value,
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
                'gallery_items' => json_encode([
                    [
                        'kind' => 'upload',
                        'upload_index' => 0,
                        'alt' => 'Nueva galería',
                    ],
                    ['kind' => 'invalid'],
                ]),
                'gallery_uploads' => [
                    UploadedFile::fake()->image('gallery-nueva.jpg'),
                ],
            ])
            ->assertSessionHasErrors();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, $link->landingImages()->count());
    }

    #[Test]
    public function update_link_hero_externo_no_elimina_archivos_inexistentes_en_storage(): void
    {
        Storage::fake('local');
        $admin = $this->makeMarketingAdmin();
        $campaign = MarketingCampaign::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                'name' => 'Enlace externo',
                'slug' => 'enlace-hero-externo-ok',
                'status' => MarketingCampaignLinkStatus::Draft->value,
                'target_type' => MarketingCampaignTargetType::Brand->value,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'hero_image_source' => MarketingCampaignHeroImageSource::External->value,
                'hero_image_url' => 'https://cdn.example.com/hero.jpg',
                'landing_layout' => 'default',
                'show_prices' => true,
                'show_brand_logo' => true,
                'show_campaign_dates' => false,
                'gallery_items' => json_encode([
                    [
                        'kind' => 'external',
                        'url' => 'https://cdn.example.com/galeria.jpg',
                        'alt' => 'Galería externa',
                    ],
                ]),
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $link = MarketingCampaignLink::query()->where('slug', 'enlace-hero-externo-ok')->firstOrFail();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(MarketingCampaignHeroImageSource::External, $link->hero_image_source);
        $this->assertSame(1, $link->landingImages()->count());
    }

    #[Test]
    public function product_search_valida_consulta_filtra_y_autoriza(): void
    {
        $admin = $this->makeMarketingAdmin(['marketing-campaigns.manage']);

        LaboratoryTest::factory()->create([
            'name' => 'Hemoglobina',
            'brand' => LaboratoryBrand::OLAB,
        ]);
        LaboratoryTest::factory()->create([
            'name' => 'Glucosa',
            'brand' => LaboratoryBrand::JENNER,
        ]);
        LaboratoryTest::factory()->count(3)->create([
            'brand' => LaboratoryBrand::OLAB,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.marketing-campaigns.product-search', ['q' => 'H']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);

        $this->actingAs($admin)
            ->getJson(route('admin.marketing-campaigns.product-search'))
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $brandOnly = $this->actingAs($admin)
            ->getJson(route('admin.marketing-campaigns.product-search', [
                'brand' => LaboratoryBrand::OLAB->value,
            ]))
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($brandOnly);
        $this->assertTrue(collect($brandOnly)->every(
            fn (array $row) => ($row['brand'] ?? null) === LaboratoryBrand::OLAB->value
        ));

        $this->actingAs($admin)
            ->getJson(route('admin.marketing-campaigns.product-search', [
                'q' => 'Hemo',
                'brand' => LaboratoryBrand::OLAB->value,
            ]))
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->actingAs($admin)
            ->getJson(route('admin.marketing-campaigns.product-search', [
                'brand' => LaboratoryBrand::OLAB->value,
                'limit' => 1,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin)
            ->getJson(route('admin.marketing-campaigns.product-search', [
                'brand' => LaboratoryBrand::OLAB->value,
                'limit' => 51,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);

        $plain = User::factory()->create();
        Administrator::factory()->for($plain)->create();

        $this->actingAs($plain)
            ->getJson(route('admin.marketing-campaigns.product-search', ['q' => 'Hemo']))
            ->assertForbidden();
    }
}
