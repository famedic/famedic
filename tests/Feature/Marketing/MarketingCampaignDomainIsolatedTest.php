<?php

namespace Tests\Feature\Marketing;

use App\Actions\Admin\MarketingCampaigns\ArchiveMarketingCampaignAction;
use App\Actions\Admin\MarketingCampaigns\CreateMarketingCampaignAction;
use App\Actions\Admin\MarketingCampaigns\CreateMarketingCampaignCollectionAction;
use App\Actions\Admin\MarketingCampaigns\CreateMarketingCampaignLinkAction;
use App\Actions\Admin\MarketingCampaigns\UpdateMarketingCampaignAction;
use App\Actions\Admin\MarketingCampaigns\UpdateMarketingCampaignCollectionAction;
use App\Actions\Admin\MarketingCampaigns\UpdateMarketingCampaignLinkAction;
use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\Administrator;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkAlias;
use App\Models\Permission;
use App\Models\User;
use App\Services\Marketing\MarketingCampaignCollectionService;
use App\Services\Marketing\MarketingCampaignTargetPayloadValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/Unit/Marketing/marketingCampaignIsolatedSchema.php';

class MarketingCampaignDomainIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        bootstrapIsolatedMarketingCampaignSchema();
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
    public function sincroniza_lista_vacia_limpiando_items(): void
    {
        $collection = MarketingCampaignCollection::factory()->create([
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $collection->laboratoryTests()->attach($test->id, ['position' => 0]);

        $result = app(MarketingCampaignCollectionService::class)->syncItems($collection, []);

        $this->assertSame([], $result->orderedItems->pluck('laboratory_test_id')->all());
    }

    #[Test]
    public function sincroniza_primer_producto_y_varios_monomarca_conservando_orden(): void
    {
        $collection = MarketingCampaignCollection::factory()->create([
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $first = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $second = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);

        $withOne = app(MarketingCampaignCollectionService::class)->syncItems($collection, [$first->id]);
        $this->assertSame([$first->id], $withOne->orderedItems->pluck('laboratory_test_id')->all());

        $result = app(MarketingCampaignCollectionService::class)->syncItems(
            $collection,
            [$second->id, $first->id],
        );

        $this->assertSame([$second->id, $first->id], $result->orderedItems->pluck('laboratory_test_id')->all());
        $this->assertSame([0, 1], $result->orderedItems->pluck('position')->all());
    }

    #[Test]
    public function rechaza_duplicados_sin_deduplicar_en_silencio(): void
    {
        $collection = MarketingCampaignCollection::factory()->create([
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);

        $this->expectException(ValidationException::class);

        app(MarketingCampaignCollectionService::class)->syncItems($collection, [$test->id, $test->id]);
    }

    #[Test]
    public function rechaza_productos_de_otra_marca_sin_modificar_la_coleccion(): void
    {
        $collection = MarketingCampaignCollection::factory()->create([
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $existing = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $otherBrand = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::JENNER]);
        $collection->laboratoryTests()->attach($existing, ['position' => 0]);

        try {
            app(MarketingCampaignCollectionService::class)->syncItems($collection, [$otherBrand->id]);
            $this->fail('La colección debía rechazar el producto de otra marca.');
        } catch (ValidationException) {
            $this->assertSame([$existing->id], $collection->laboratoryTests()->pluck('laboratory_tests.id')->all());
        }
    }

    #[Test]
    public function reordena_items_existentes(): void
    {
        $collection = MarketingCampaignCollection::factory()->create([
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $a = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $b = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        app(MarketingCampaignCollectionService::class)->syncItems($collection, [$a->id, $b->id]);

        $result = app(MarketingCampaignCollectionService::class)->syncItems($collection, [$b->id, $a->id]);

        $this->assertSame([$b->id, $a->id], $result->orderedItems->pluck('laboratory_test_id')->all());
    }

    #[Test]
    public function cambio_de_marca_compatible_con_lista_vacia(): void
    {
        $collection = MarketingCampaignCollection::factory()->create([
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $collection->laboratoryTests()->attach($test->id, ['position' => 0]);

        $updated = app(UpdateMarketingCampaignCollectionAction::class)($collection, [
            'name' => $collection->name,
            'public_title' => $collection->public_title,
            'public_description' => $collection->public_description,
            'laboratory_brand' => LaboratoryBrand::JENNER->value,
            'is_active' => true,
            'laboratory_test_ids' => [],
        ]);

        $this->assertSame(LaboratoryBrand::JENNER, $updated->laboratory_brand);
        $this->assertSame([], $updated->orderedItems->all());
    }

    #[Test]
    public function cambio_de_marca_incompatible_hace_rollback(): void
    {
        $collection = MarketingCampaignCollection::factory()->create([
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'name' => 'Colección original',
        ]);
        $olab = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $collection->laboratoryTests()->attach($olab->id, ['position' => 0]);

        try {
            app(UpdateMarketingCampaignCollectionAction::class)($collection, [
                'name' => 'Nombre nuevo',
                'public_title' => $collection->public_title,
                'public_description' => null,
                'laboratory_brand' => LaboratoryBrand::JENNER->value,
                'is_active' => true,
                'laboratory_test_ids' => [$olab->id],
            ]);
            $this->fail('Debía fallar por marca incompatible.');
        } catch (ValidationException) {
            $fresh = $collection->fresh();
            $this->assertSame('Colección original', $fresh->name);
            $this->assertSame(LaboratoryBrand::OLAB, $fresh->laboratory_brand);
            $this->assertSame([$olab->id], $fresh->laboratoryTests()->pluck('laboratory_tests.id')->all());
        }
    }

    #[Test]
    public function sync_con_error_hace_rollback_de_items(): void
    {
        $collection = MarketingCampaignCollection::factory()->create([
            'laboratory_brand' => LaboratoryBrand::OLAB,
        ]);
        $existing = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB]);
        $missingId = 999999;
        $collection->laboratoryTests()->attach($existing->id, ['position' => 0]);

        try {
            app(MarketingCampaignCollectionService::class)->syncItems($collection, [$missingId]);
            $this->fail('Debía fallar por estudio inexistente.');
        } catch (ValidationException) {
            $this->assertSame([$existing->id], $collection->fresh()->laboratoryTests()->pluck('laboratory_tests.id')->all());
        }
    }

    #[Test]
    public function create_collection_action_permite_coleccion_vacia(): void
    {
        $campaign = MarketingCampaign::factory()->create();

        $collection = app(CreateMarketingCampaignCollectionAction::class)([
            'marketing_campaign_id' => $campaign->id,
            'name' => 'Vacía',
            'public_title' => 'Título',
            'public_description' => null,
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'is_active' => true,
            'laboratory_test_ids' => [],
        ]);

        $this->assertTrue($collection->exists);
        $this->assertSame([], $collection->orderedItems->all());
    }

    #[Test]
    public function valida_targets_y_producto_deriva_marca_canonica(): void
    {
        $validator = app(MarketingCampaignTargetPayloadValidator::class);
        $campaign = MarketingCampaign::factory()->create();
        $category = LaboratoryTestCategory::factory()->create();
        $product = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::AZTECA]);
        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create();

        $this->assertSame(
            ['brand' => LaboratoryBrand::OLAB->value],
            $validator->validate(MarketingCampaignTargetType::Brand, ['brand' => LaboratoryBrand::OLAB->value]),
        );
        $this->assertSame(
            ['brand' => LaboratoryBrand::JENNER->value, 'laboratory_test_category_id' => $category->id],
            $validator->validate(MarketingCampaignTargetType::Category, [
                'brand' => LaboratoryBrand::JENNER->value,
                'laboratory_test_category_id' => $category->id,
            ]),
        );
        $this->assertSame(
            ['laboratory_test_id' => $product->id, 'brand' => LaboratoryBrand::AZTECA->value],
            $validator->validate(MarketingCampaignTargetType::Product, [
                'laboratory_test_id' => $product->id,
                'brand' => LaboratoryBrand::OLAB->value,
            ]),
        );
        $this->assertSame(
            ['marketing_campaign_collection_id' => $collection->id],
            $validator->validate(
                MarketingCampaignTargetType::Collection,
                ['marketing_campaign_collection_id' => $collection->id],
                $campaign->id,
            ),
        );
    }

    #[Test]
    public function rechaza_brand_invalido_y_category_inexistente(): void
    {
        $validator = app(MarketingCampaignTargetPayloadValidator::class);

        try {
            $validator->validate(MarketingCampaignTargetType::Brand, ['brand' => 'no-existe']);
            $this->fail('Brand inválido debía fallar.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        $validator->validate(MarketingCampaignTargetType::Category, [
            'brand' => LaboratoryBrand::OLAB->value,
            'laboratory_test_category_id' => 999999,
        ]);
    }

    #[Test]
    public function rechaza_product_inexistente(): void
    {
        $this->expectException(ValidationException::class);

        app(MarketingCampaignTargetPayloadValidator::class)->validate(
            MarketingCampaignTargetType::Product,
            ['laboratory_test_id' => 999999],
        );
    }

    #[Test]
    public function collection_requiere_contexto_de_campana(): void
    {
        $collection = MarketingCampaignCollection::factory()->create();

        $this->expectException(ValidationException::class);

        app(MarketingCampaignTargetPayloadValidator::class)->validate(
            MarketingCampaignTargetType::Collection,
            ['marketing_campaign_collection_id' => $collection->id],
            null,
        );
    }

    #[Test]
    public function rechaza_coleccion_inexistente_soft_deleted_u_otra_campana(): void
    {
        $campaign = MarketingCampaign::factory()->create();
        $otherCollection = MarketingCampaignCollection::factory()->create();
        $deleted = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create();
        $deleted->delete();

        $validator = app(MarketingCampaignTargetPayloadValidator::class);

        try {
            $validator->validate(
                MarketingCampaignTargetType::Collection,
                ['marketing_campaign_collection_id' => 999999],
                $campaign->id,
            );
            $this->fail('Colección inexistente debía fallar.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        try {
            $validator->validate(
                MarketingCampaignTargetType::Collection,
                ['marketing_campaign_collection_id' => $deleted->id],
                $campaign->id,
            );
            $this->fail('Colección soft-deleted debía fallar.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        $validator->validate(
            MarketingCampaignTargetType::Collection,
            ['marketing_campaign_collection_id' => $otherCollection->id],
            $campaign->id,
        );
    }

    #[Test]
    public function rechaza_campos_no_permitidos_en_el_payload(): void
    {
        $this->expectException(ValidationException::class);

        app(MarketingCampaignTargetPayloadValidator::class)->validate(
            MarketingCampaignTargetType::Brand,
            ['brand' => LaboratoryBrand::OLAB->value, 'external_url' => 'https://example.com'],
        );
    }

    #[Test]
    public function create_link_action_usa_transaccion_y_reserva_slug_tras_soft_delete(): void
    {
        $administrator = Administrator::factory()->create();
        $campaign = MarketingCampaign::factory()->create();

        $link = app(CreateMarketingCampaignLinkAction::class)([
            'marketing_campaign_id' => $campaign->id,
            'name' => 'Link A',
            'slug' => 'slug-reservado-tx',
            'status' => MarketingCampaignLinkStatus::Draft,
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
        ], $administrator);

        $this->assertSame('slug-reservado-tx', $link->slug);
        $link->delete();

        $this->expectException(ValidationException::class);
        app(CreateMarketingCampaignLinkAction::class)([
            'marketing_campaign_id' => $campaign->id,
            'name' => 'Link B',
            'slug' => 'slug-reservado-tx',
            'status' => MarketingCampaignLinkStatus::Draft,
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
        ], $administrator);
    }

    #[Test]
    public function create_link_rechaza_alias_existente_y_rollback_deja_estado_limpio(): void
    {
        $administrator = Administrator::factory()->create();
        $campaign = MarketingCampaign::factory()->create();
        MarketingCampaignLinkAlias::factory()->create(['slug' => 'alias-ocupado']);

        $before = MarketingCampaignLink::query()->count();

        try {
            app(CreateMarketingCampaignLinkAction::class)([
                'marketing_campaign_id' => $campaign->id,
                'name' => 'Link alias',
                'slug' => 'alias-ocupado',
                'status' => MarketingCampaignLinkStatus::Draft,
                'target_type' => MarketingCampaignTargetType::Brand,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            ], $administrator);
            $this->fail('Debía rechazar alias reservado.');
        } catch (ValidationException) {
            $this->assertSame($before, MarketingCampaignLink::query()->count());
        }
    }

    #[Test]
    public function update_link_action_mantiene_transaccion_al_cambiar_slug(): void
    {
        $administrator = Administrator::factory()->create();
        $link = MarketingCampaignLink::factory()->create(['slug' => 'slug-previo']);

        $updated = app(UpdateMarketingCampaignLinkAction::class)($link, [
            'name' => 'Actualizado',
            'slug' => 'slug-nuevo',
            'status' => MarketingCampaignLinkStatus::Active->value,
            'target_type' => MarketingCampaignTargetType::Brand->value,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
        ], $administrator);

        $this->assertSame('slug-nuevo', $updated->slug);
        $this->assertTrue($updated->aliases()->where('slug', 'slug-previo')->exists());
    }

    #[Test]
    public function archive_es_idempotente_y_no_soft_delete(): void
    {
        $user = User::factory()->create();
        $administrator = Administrator::factory()->for($user)->create();
        $edit = Permission::query()->where('name', 'marketing-campaigns.manage.edit')->sole();
        $administrator->givePermissionTo($edit);

        $campaign = MarketingCampaign::factory()->create([
            'status' => MarketingCampaignStatus::Active,
        ]);
        $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create();
        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create();

        $archived = app(ArchiveMarketingCampaignAction::class)($campaign, $administrator);
        $again = app(ArchiveMarketingCampaignAction::class)($archived, $administrator);

        $this->assertSame(MarketingCampaignStatus::Archived, $archived->status);
        $this->assertSame(MarketingCampaignStatus::Archived, $again->status);
        $this->assertNull($campaign->fresh()->deleted_at);
        $this->assertNotNull($link->fresh());
        $this->assertNotNull($collection->fresh());
        $this->assertSame($administrator->id, $again->updated_by);
    }

    #[Test]
    public function archive_sin_permiso_de_edicion_es_rechazado(): void
    {
        $user = User::factory()->create();
        $administrator = Administrator::factory()->for($user)->create();
        $manage = Permission::query()->where('name', 'marketing-campaigns.manage')->sole();
        $administrator->givePermissionTo($manage);
        $campaign = MarketingCampaign::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(ArchiveMarketingCampaignAction::class)($campaign, $administrator);
    }

    #[Test]
    public function create_y_update_campaign_actions_funcionan(): void
    {
        $administrator = Administrator::factory()->create();

        $campaign = app(CreateMarketingCampaignAction::class)([
            'name' => 'Campaña nueva',
            'description' => null,
            'status' => MarketingCampaignStatus::Draft,
        ], $administrator);

        $updated = app(UpdateMarketingCampaignAction::class)($campaign, [
            'name' => 'Campaña editada',
            'description' => 'desc',
            'status' => MarketingCampaignStatus::Active,
        ], $administrator);

        $this->assertSame('Campaña editada', $updated->name);
        $this->assertSame($administrator->id, $updated->updated_by);
    }

    #[Test]
    public function modelos_exponen_casts_relaciones_soft_deletes_y_unique_slug(): void
    {
        $administrator = Administrator::factory()->create();
        $campaign = MarketingCampaign::factory()->create([
            'status' => MarketingCampaignStatus::Scheduled,
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
            'starts_at' => now(),
        ]);
        $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'status' => MarketingCampaignLinkStatus::Active,
            'slug' => 'slug-unico',
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::AZTECA->value],
        ]);
        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create([
            'laboratory_brand' => LaboratoryBrand::AZTECA,
        ]);

        $this->assertSame(MarketingCampaignStatus::Scheduled, $campaign->status);
        $this->assertTrue($campaign->createdBy->is($administrator));
        $this->assertTrue($campaign->links->first()->is($link));
        $this->assertTrue($campaign->collections->first()->is($collection));
        $this->assertSame(MarketingCampaignLinkStatus::Active, $link->status);
        $this->assertSame(MarketingCampaignTargetType::Brand, $link->target_type);
        $this->assertSame(['brand' => LaboratoryBrand::AZTECA->value], $link->target_payload);
        $this->assertSame(LaboratoryBrand::AZTECA, $collection->laboratory_brand);
        $this->assertTrue($collection->is_active);

        $link->delete();
        $this->assertSoftDeleted($link);
        $collection->delete();
        $this->assertSoftDeleted($collection);

        $this->expectException(QueryException::class);
        MarketingCampaignLink::factory()->for($campaign, 'campaign')->create(['slug' => 'slug-unico']);
    }

    #[Test]
    public function policies_separan_lectura_edicion_y_prohiben_delete_y_force_delete(): void
    {
        $user = User::factory()->create();
        $administrator = Administrator::factory()->for($user)->create();
        $campaign = MarketingCampaign::factory()->create();
        $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create();
        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create();

        $managePermission = Permission::query()->where('name', 'marketing-campaigns.manage')->sole();
        $editPermission = Permission::query()->where('name', 'marketing-campaigns.manage.edit')->sole();
        $administrator->givePermissionTo($managePermission);

        $this->assertTrue($user->can('viewAny', MarketingCampaign::class));
        $this->assertTrue($user->can('view', $campaign));
        $this->assertTrue($user->can('viewAny', MarketingCampaignLink::class));
        $this->assertTrue($user->can('view', $link));
        $this->assertTrue($user->can('viewAny', MarketingCampaignCollection::class));
        $this->assertTrue($user->can('view', $collection));

        $this->assertFalse($user->can('create', MarketingCampaign::class));
        $this->assertFalse($user->can('update', $campaign));
        $this->assertFalse($user->can('delete', $campaign));
        $this->assertFalse($user->can('forceDelete', $campaign));
        $this->assertFalse($user->can('delete', $link));
        $this->assertFalse($user->can('forceDelete', $link));
        $this->assertFalse($user->can('delete', $collection));
        $this->assertFalse($user->can('forceDelete', $collection));

        $administrator->givePermissionTo($editPermission);
        $administrator->unsetRelation('permissions');
        $user->unsetRelation('administrator');

        $this->assertTrue($user->can('create', MarketingCampaign::class));
        $this->assertTrue($user->can('update', $campaign));
        $this->assertTrue($user->can('create', MarketingCampaignLink::class));
        $this->assertTrue($user->can('update', $link));
        $this->assertTrue($user->can('create', MarketingCampaignCollection::class));
        $this->assertTrue($user->can('update', $collection));
        $this->assertFalse($user->can('delete', $campaign));
        $this->assertFalse($user->can('forceDelete', $campaign));
    }

    #[Test]
    public function rol_administrador_recibe_manage_y_edit(): void
    {
        $adminRole = \App\Models\Role::query()->firstOrCreate(
            ['name' => 'Administrador', 'guard_name' => 'web'],
        );

        $permissionMigration = require database_path('migrations/2026_08_06_230100_add_marketing_campaign_permissions.php');
        $permissionMigration->up();

        $this->assertTrue($adminRole->hasPermissionTo('marketing-campaigns.manage'));
        $this->assertTrue($adminRole->hasPermissionTo('marketing-campaigns.manage.edit'));
    }
}
