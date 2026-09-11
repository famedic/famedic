<?php

namespace Tests\Feature\Marketing;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLandingTemplate;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\Administrator;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignVisit;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/Unit/Marketing/marketingCampaignIsolatedSchema.php';

class MarketingCampaignLandingTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        bootstrapIsolatedMarketingCampaignSchema();
        $this->seedMarketingPermissions();

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

    #[Test]
    public function conversion_es_la_plantilla_default_y_llega_al_view_model(): void
    {
        $campaign = $this->activeCampaign();
        MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'slug' => 'default-template',
            'status' => MarketingCampaignLinkStatus::Active,
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'default-template']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('content.landing_template', MarketingCampaignLandingTemplate::Conversion->value));
    }

    #[Test]
    public function admin_puede_crear_y_editar_cada_plantilla_sin_tocar_utms(): void
    {
        $admin = $this->marketingAdmin();
        $campaign = $this->activeCampaign();

        foreach (MarketingCampaignLandingTemplate::cases() as $template) {
            $slug = 'template-'.$template->value;

            $this->actingAs($admin)
                ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                    ...$this->linkPayload($slug),
                    'landing_template' => $template->value,
                    'utm_source' => 'newsletter',
                ])
                ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

            $this->assertSame(
                $template,
                MarketingCampaignLink::query()->where('slug', $slug)->firstOrFail()->landing_template,
            );
        }

        $link = MarketingCampaignLink::query()->where('slug', 'template-conversion')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.marketing-campaigns.links.update', [$campaign, $link]), [
                ...$this->linkPayload('template-conversion'),
                'landing_template' => MarketingCampaignLandingTemplate::Catalog->value,
                'utm_source' => 'newsletter',
            ])
            ->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $link->refresh();
        $this->assertSame(MarketingCampaignLandingTemplate::Catalog, $link->landing_template);
        $this->assertSame('newsletter', $link->utm_source);
    }

    #[Test]
    public function admin_rechaza_plantilla_invalida_y_editorial_html(): void
    {
        $admin = $this->marketingAdmin();
        $campaign = $this->activeCampaign();

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                ...$this->linkPayload('bad-template'),
                'landing_template' => 'inventada',
            ])
            ->assertSessionHasErrors('landing_template');

        $this->actingAs($admin)
            ->post(route('admin.marketing-campaigns.links.store', $campaign), [
                ...$this->linkPayload('bad-html'),
                'landing_template' => MarketingCampaignLandingTemplate::Editorial->value,
                'editorial_body' => '<strong>No permitido</strong>',
            ])
            ->assertSessionHasErrors('editorial_body');
    }

    #[Test]
    public function editorial_items_estan_limitados_y_normalizados_en_landing(): void
    {
        $campaign = $this->activeCampaign();
        MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'slug' => 'editorial-content',
            'status' => MarketingCampaignLinkStatus::Active,
            'landing_template' => MarketingCampaignLandingTemplate::Editorial,
            'editorial_eyebrow' => 'Prevención',
            'editorial_title' => 'Cuida tu salud',
            'editorial_body' => 'Información administrable.',
            'editorial_items' => [
                ['title' => 'Uno', 'description' => 'Texto', 'icon' => 'heart'],
                ['title' => 'Dos', 'description' => 'Texto', 'icon' => 'lab'],
                ['title' => 'Tres', 'description' => 'Texto', 'icon' => 'clock'],
                ['title' => 'Cuatro', 'description' => 'Texto', 'icon' => 'shield'],
                ['title' => 'Cinco', 'description' => 'No debe salir', 'icon' => 'bad'],
            ],
        ]);

        $this->get(route('campaign-links.show', ['slug' => 'editorial-content']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('content.editorial.eyebrow', 'Prevención')
                ->where('content.editorial.title', 'Cuida tu salud')
                ->has('content.editorial.items', 4)
                ->where('content.editorial.items.0.icon', 'heart'));
    }

    #[Test]
    public function preview_admin_requiere_permiso_valida_pertenencia_y_no_escribe_tracking(): void
    {
        config(['marketing-attribution.enabled' => true]);

        $admin = $this->marketingAdmin();
        $plain = $this->marketingAdmin([]);
        $campaign = $this->activeCampaign();
        $otherCampaign = $this->activeCampaign();
        $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'slug' => 'preview-template',
            'status' => MarketingCampaignLinkStatus::Active,
            'landing_template' => MarketingCampaignLandingTemplate::Catalog,
        ]);

        $this->get(route('admin.marketing-campaigns.links.preview', [$campaign, $link]))
            ->assertRedirect();

        $this->actingAs($plain)
            ->get(route('admin.marketing-campaigns.links.preview', [$campaign, $link]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.links.preview', [$otherCampaign, $link]))
            ->assertNotFound();

        $before = [
            MarketingCampaignVisit::query()->count(),
            MarketingCampaignAttribution::query()->count(),
            MarketingCampaignConversion::query()->count(),
        ];

        $this->actingAs($admin)
            ->get(route('admin.marketing-campaigns.links.preview', [$campaign, $link]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MarketingCampaigns/Landing')
                ->where('preview.admin', true)
                ->where('content.landing_template', MarketingCampaignLandingTemplate::Catalog->value));

        $this->assertSame($before, [
            MarketingCampaignVisit::query()->count(),
            MarketingCampaignAttribution::query()->count(),
            MarketingCampaignConversion::query()->count(),
        ]);
    }

    private function activeCampaign(): MarketingCampaign
    {
        return MarketingCampaign::factory()->create([
            'status' => MarketingCampaignStatus::Active,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function linkPayload(string $slug): array
    {
        return [
            'name' => 'Enlace '.$slug,
            'slug' => $slug,
            'status' => MarketingCampaignLinkStatus::Draft->value,
            'target_type' => MarketingCampaignTargetType::Brand->value,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            'show_prices' => true,
            'show_brand_logo' => true,
            'show_campaign_dates' => false,
            'landing_layout' => 'default',
        ];
    }

    private function marketingAdmin(array $permissions = [
        'marketing-campaigns.manage',
        'marketing-campaigns.manage.edit',
    ]): User {
        $user = User::factory()->create();
        $administrator = Administrator::factory()->for($user)->create();

        foreach ($permissions as $permission) {
            $administrator->givePermissionTo(Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]));
        }

        return $user->fresh()->load('administrator');
    }

    private function seedMarketingPermissions(): void
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
}
