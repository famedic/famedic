<?php

namespace Tests\Feature\Admin;

use App\Enums\LaboratoryBrand;
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
use Illuminate\Support\Facades\Route;
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
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

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
