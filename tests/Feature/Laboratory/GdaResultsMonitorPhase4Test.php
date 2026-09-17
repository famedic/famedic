<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultPdfClassification;
use App\Enums\LaboratoryResultStatus as ResultStatusEnum;
use App\Models\ActiveCampaignDispatch;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\LabOrderEventState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsMonitorPhase4Test extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        $this->bootstrapIsolatedSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function monitor_detalle_devuelve_status_por_estudio_gate_timeline_versiones_y_activecampaign(): void
    {
        [$purchase, $items] = $this->seedPurchaseWithItems(2);
        $notification = $this->seedResultsNotification($purchase);
        $status = $this->createResultStatus($purchase, $items[0], ResultStatusEnum::PendingInterpretation, [
            'last_checked_at' => now()->subHour(),
            'next_check_at' => now()->subMinutes(5),
            'check_attempts' => 2,
        ]);
        $this->createResultStatus($purchase, $items[1], ResultStatusEnum::Complete);
        $version = $this->createVersion($status, $notification);
        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $status->id,
            'laboratory_result_version_id' => $version->id,
            'event_type' => LaboratoryResultEventType::CompletionGateEvaluated,
            'from_status' => ResultStatusEnum::AvailableUnchecked,
            'to_status' => ResultStatusEnum::PendingInterpretation,
            'metadata' => [
                'reason' => 'pending_interpretation',
                'extracted_text' => 'dato clinico que no debe salir',
                'pdf_base64' => 'JVBERi0x',
            ],
            'created_at' => now(),
        ]);
        LabOrderEventState::query()->create([
            'gda_order_id' => $purchase->gda_order_id,
            'laboratory_purchase_id' => $purchase->id,
            'total_studies' => 2,
            'results_received_count' => 2,
            'first_event_at' => now(),
            'last_event_at' => now(),
        ]);
        ActiveCampaignDispatch::query()->create([
            'event_type' => 'laboratory_results_completed',
            'entity_type' => 'laboratory_purchase',
            'entity_id' => $purchase->id,
            'user_id' => $purchase->customer->user_id,
            'email' => $purchase->customer->user->email,
            'idempotency_key' => 'laboratory_purchase:'.$purchase->id.':results_completed:lab_fields',
            'status' => ActiveCampaignDispatch::STATUS_FAILED,
            'attempts' => 3,
            'last_error' => 'API 500 con detalle interno muy largo',
        ]);

        $response = $this->actingAs($this->seedAdminUser())
            ->getJson(route('admin.laboratory-notifications-monitor.order-details', ['orderKey' => $purchase->gda_order_id]));

        $response->assertOk()
            ->assertJsonPath('studies.0.status', ResultStatusEnum::PendingInterpretation->value)
            ->assertJsonPath('studies.0.check_attempts', 2)
            ->assertJsonPath('summary.result_summary.pending_interpretation', 1)
            ->assertJsonPath('summary.completion_gate.legacy_ready', true)
            ->assertJsonPath('summary.completion_gate.semantic_ready', false)
            ->assertJsonPath('summary.completion_gate.mismatch', true)
            ->assertJsonPath('versions.0.sha256_short', substr($version->sha256, 0, 8))
            ->assertJsonPath('summary.activecampaign.entries.0.status', ActiveCampaignDispatch::STATUS_FAILED);

        $metadata = $response->json('timeline.0.metadata');
        $this->assertArrayHasKey('reason', $metadata);
        $this->assertArrayNotHasKey('extracted_text', $metadata);
        $this->assertArrayNotHasKey('pdf_base64', $metadata);
    }

    #[Test]
    public function monitor_filtra_por_pending_manual_review_error_y_shadow_mismatch(): void
    {
        [$pendingPurchase, $pendingItems] = $this->seedPurchaseWithItems(1, 'GDA-PENDING');
        $this->seedResultsNotification($pendingPurchase);
        $this->createResultStatus($pendingPurchase, $pendingItems[0], ResultStatusEnum::PendingInterpretation);
        $this->createReadyGateState($pendingPurchase, 1, 1);

        [$manualPurchase, $manualItems] = $this->seedPurchaseWithItems(1, 'GDA-MANUAL');
        $this->seedResultsNotification($manualPurchase);
        $this->createResultStatus($manualPurchase, $manualItems[0], ResultStatusEnum::ManualReview);

        [$errorPurchase, $errorItems] = $this->seedPurchaseWithItems(1, 'GDA-ERROR');
        $this->seedResultsNotification($errorPurchase);
        $this->createResultStatus($errorPurchase, $errorItems[0], ResultStatusEnum::Error);

        $admin = $this->seedAdminUser();

        $this->actingAs($admin)
            ->get(route('admin.laboratory-notifications-monitor.index', ['result_status' => 'pending_interpretation']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.order_key', (string) $pendingPurchase->gda_consecutivo)
            );

        $this->actingAs($admin)
            ->get(route('admin.laboratory-notifications-monitor.index', ['result_status' => 'manual_review']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.order_key', (string) $manualPurchase->gda_consecutivo)
            );

        $this->actingAs($admin)
            ->get(route('admin.laboratory-notifications-monitor.index', ['result_status' => 'error']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.order_key', (string) $errorPurchase->gda_consecutivo)
            );

        $this->actingAs($admin)
            ->get(route('admin.laboratory-notifications-monitor.index', ['gate' => 'legacy_ready_semantic_blocked']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.order_key', (string) $pendingPurchase->gda_consecutivo)
            );
    }

    #[Test]
    public function refresh_admin_no_autorizado_devuelve_json_consistente(): void
    {
        [$purchase] = $this->seedPurchaseWithItems(1);
        $this->seedResultsNotification($purchase);
        $this->seedAdminUser();
        $user = User::query()->create([
            'name' => 'No Admin',
            'email' => 'no-admin@example.test',
            'password' => bcrypt('secret'),
        ]);
        DB::table('administrators')->insert([
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->load('administrator');

        $this->actingAs($user)
            ->postJson(route('admin.laboratory-notifications-monitor.force-refresh-results', ['orderKey' => $purchase->gda_order_id]))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'monitor_error');
    }

    #[Test]
    public function refresh_admin_respeta_lock_y_devuelve_json_consistente(): void
    {
        [$purchase, $items] = $this->seedPurchaseWithItems(1);
        $this->seedResultsNotification($purchase);
        $status = $this->createResultStatus($purchase, $items[0], ResultStatusEnum::PendingInterpretation, [
            'next_check_at' => now()->subMinute(),
        ]);

        $lock = Cache::lock('laboratory-result-refresh:'.$status->id, 600);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->seedAdminUser())
                ->postJson(route('admin.laboratory-notifications-monitor.force-refresh-results', ['orderKey' => $purchase->gda_order_id]))
                ->assertStatus(409)
                ->assertJsonPath('success', false)
                ->assertJsonPath('ok', false)
                ->assertJsonPath('code', 'refresh_locked');
        } finally {
            $lock->release();
        }
    }

    private function seedPurchaseWithItems(int $items, ?string $gdaOrderId = null): array
    {
        $user = User::query()->create([
            'name' => 'Paciente Test',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
        ]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => $gdaOrderId ?: 'GDA-ORDER-'.uniqid(),
            'gda_consecutivo' => random_int(100, 999),
            'name' => 'Juan',
            'paternal_lastname' => 'Perez',
            'maternal_lastname' => 'Lopez',
            'phone' => '5555555555',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE->value,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'CDMX',
            'city' => 'CDMX',
            'zipcode' => '01000',
            'total_cents' => 10000,
            'status' => 'pending',
        ]);

        $createdItems = collect(range(1, $items))->map(fn ($index) => LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'EST-'.$index,
            'name' => 'Estudio '.$index,
            'price_cents' => 10000,
        ]));

        return [$purchase->fresh(['customer.user', 'laboratoryPurchaseItems']), $createdItems->all()];
    }

    private function seedResultsNotification(LaboratoryPurchase $purchase): LaboratoryNotification
    {
        return LaboratoryNotification::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
            'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
            'gda_order_id' => $purchase->gda_order_id,
            'gda_consecutivo' => $purchase->gda_consecutivo,
            'status' => LaboratoryNotification::STATUS_RECEIVED,
            'gda_status' => LaboratoryNotification::GDA_STATUS_COMPLETED,
            'resource_type' => 'ServiceRequest',
            'results_received_at' => now(),
            'payload' => [],
        ]);
    }

    private function createResultStatus(
        LaboratoryPurchase $purchase,
        LaboratoryPurchaseItem $item,
        ResultStatusEnum $status,
        array $overrides = [],
    ): LaboratoryResultStatus {
        return LaboratoryResultStatus::query()->create(array_merge([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_purchase_item_id' => $item->id,
            'status' => $status,
            'first_available_at' => now(),
            'last_checked_at' => now(),
            'interpreted_at' => $status === ResultStatusEnum::Complete ? now() : null,
        ], $overrides));
    }

    private function createVersion(LaboratoryResultStatus $status, LaboratoryNotification $notification): LaboratoryResultVersion
    {
        Storage::put('results/gda-monitor-phase4.pdf', "%PDF-1.4\n%%EOF");

        return LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $status->id,
            'laboratory_notification_id' => $notification->id,
            'storage_path' => 'results/gda-monitor-phase4.pdf',
            'sha256' => hash('sha256', 'version-one'),
            'source' => 'gda',
            'classification' => LaboratoryResultPdfClassification::PendingInterpretation,
            'classification_reason' => 'pending_signal',
            'classifier' => 'test',
            'classified_at' => now(),
            'pdf_available_at' => now(),
        ]);
    }

    private function createReadyGateState(LaboratoryPurchase $purchase, int $total, int $received): void
    {
        LabOrderEventState::query()->create([
            'gda_order_id' => $purchase->gda_order_id,
            'laboratory_purchase_id' => $purchase->id,
            'total_studies' => $total,
            'results_received_count' => $received,
            'first_event_at' => now(),
            'last_event_at' => now(),
        ]);
    }

    private function seedAdminUser(): User
    {
        if (! Schema::hasTable('administrators')) {
            Schema::create('administrators', function ($table) {
                $table->id();
                $table->foreignId('user_id')->constrained();
                $table->timestamps();
            });
        }

        foreach (['permissions', 'roles'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function ($table) {
                    $table->id();
                    $table->string('name');
                    $table->string('guard_name')->default('web');
                    $table->timestamps();
                });
            }
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function ($table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_type', 'model_id']);
            });
        }

        foreach (['model_has_roles', 'role_has_permissions'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function ($table) use ($tableName) {
                    $table->unsignedBigInteger($tableName === 'model_has_roles' ? 'role_id' : 'permission_id');
                    $table->string('model_type')->nullable();
                    $table->unsignedBigInteger('model_id')->nullable();
                    $table->unsignedBigInteger('role_id')->nullable();
                });
            }
        }

        $user = User::query()->create([
            'name' => 'Admin Test',
            'email' => 'admin-'.uniqid().'@famedic.com.mx',
            'password' => bcrypt('secret'),
        ]);

        $admin = DB::table('administrators')->insertGetId([
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permission = \Spatie\Permission\Models\Permission::findOrCreate('laboratory-notifications.monitor', 'web');

        $user = $user->fresh('administrator');
        $user->administrator->givePermissionTo($permission);

        return $user->fresh('administrator');
    }
}
