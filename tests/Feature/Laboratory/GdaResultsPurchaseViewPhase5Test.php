<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultPdfClassification;
use App\Enums\LaboratoryResultStatus as ResultStatusEnum;
use App\Jobs\TagLaboratoryEmailToActiveCampaignJob;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use App\Notifications\LaboratoryResultsAvailable;
use App\Services\LaboratoryResults\LaboratoryPurchaseResultControlPresenter;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GdaResultsPurchaseViewPhase5Test extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        Notification::fake();
        Bus::fake([TagLaboratoryEmailToActiveCampaignJob::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->bootstrapIsolatedSchema();
        $this->ensureAdminTables();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->tearDownIsolatedSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function presentador_expone_status_individual_y_oculta_campos_admin_al_paciente(): void
    {
        [$purchase, $items] = $this->seedPurchaseWithItems(2);
        $notification = $this->seedResultsNotification($purchase);
        $pending = $this->createResultStatus($purchase, $items[0], ResultStatusEnum::PendingInterpretation, [
            'check_attempts' => 2,
            'next_check_at' => now()->subMinute(),
        ]);
        $this->createVersion($pending, $notification, LaboratoryResultPdfClassification::PendingInterpretation);
        $this->createResultStatus($purchase, $items[1], ResultStatusEnum::Complete);

        $patientPayload = app(LaboratoryPurchaseResultControlPresenter::class)
            ->present($purchase->fresh(), $purchase->customer->user);
        $adminPayload = app(LaboratoryPurchaseResultControlPresenter::class)
            ->present($purchase->fresh(), $this->seedAdminUser());

        $this->assertSame('pending_interpretation', $patientPayload['resultControl']['overall_status']);
        $this->assertSame('Pendiente interpretación', $patientPayload['studyResultStatuses'][0]['status_label']);
        $this->assertArrayNotHasKey('gda_id', $patientPayload['studyResultStatuses'][0]);
        $this->assertArrayNotHasKey('check_attempts', $patientPayload['studyResultStatuses'][0]);

        $this->assertTrue($adminPayload['resultControl']['can_admin_manage']);
        $this->assertSame('EST-1', $adminPayload['studyResultStatuses'][0]['gda_id']);
        $this->assertSame(2, $adminPayload['studyResultStatuses'][0]['check_attempts']);
        $this->assertTrue($adminPayload['studyResultStatuses'][0]['can_refresh_from_gda']);
    }

    #[Test]
    public function orden_sin_pdf_ni_estatus_no_se_marca_completa(): void
    {
        [$purchase] = $this->seedPurchaseWithItems(3);

        $payload = app(LaboratoryPurchaseResultControlPresenter::class)
            ->present($purchase->fresh(), $purchase->customer->user);

        $this->assertSame('pending', $payload['resultControl']['overall_status']);
        $this->assertFalse($payload['resultControl']['is_complete']);
        $this->assertFalse($payload['resultControl']['can_view_results']);
    }

    #[Test]
    public function admin_no_puede_enviar_aviso_si_gate_semantico_no_esta_completo(): void
    {
        [$purchase, $items] = $this->seedPurchaseWithItems(1);
        $this->seedResultsNotification($purchase);
        $this->createResultStatus($purchase, $items[0], ResultStatusEnum::PendingInterpretation);

        $response = $this->actingAs($this->seedAdminUser())
            ->postJson(route('admin.laboratory-purchases.result-control.notify', $purchase));

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'semantic_gate_incomplete');

        Notification::assertNothingSent();
        Bus::assertNotDispatched(TagLaboratoryEmailToActiveCampaignJob::class);
        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::AdminNotificationFailed->value)
            ->count());
    }

    #[Test]
    public function admin_reenvia_aviso_completo_sin_duplicar_active_campaign(): void
    {
        [$purchase, $items] = $this->seedPurchaseWithItems(1);
        $notification = $this->seedResultsNotification($purchase);
        $status = $this->createResultStatus($purchase, $items[0], ResultStatusEnum::Complete);
        $this->createVersion($status, $notification, LaboratoryResultPdfClassification::Complete);

        $response = $this->actingAs($this->seedAdminUser())
            ->postJson(route('admin.laboratory-purchases.result-control.notify', $purchase));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'admin_notification_sent');

        Notification::assertSentTo($purchase->customer->user, LaboratoryResultsAvailable::class);
        Bus::assertNotDispatched(TagLaboratoryEmailToActiveCampaignJob::class);
        $this->assertNotNull($notification->fresh()->email_sent_at);
        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::AdminNotificationSent->value)
            ->count());
    }

    #[Test]
    public function admin_analiza_resultado_historico_sin_enviar_email_ni_active_campaign(): void
    {
        [$purchase] = $this->seedPurchaseWithItems(1);
        $path = 'results/legacy-complete.pdf';
        Storage::put($path, $this->pdfWithText('Resultado final. Interpretacion realizada.'));
        $purchase->forceFill(['results' => $path])->save();
        $this->seedResultsNotification($purchase);

        $response = $this->actingAs($this->seedAdminUser())
            ->postJson(route('admin.laboratory-purchases.result-control.analyze-legacy', $purchase));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'legacy_analysis_processed')
            ->assertJsonPath('resultControl.is_complete', true);

        Notification::assertNothingSent();
        Bus::assertNotDispatched(TagLaboratoryEmailToActiveCampaignJob::class);
        $this->assertSame(ResultStatusEnum::Complete, LaboratoryResultStatus::query()->firstOrFail()->status);
        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::LegacyAnalysisRequested->value)
            ->count());
    }

    private function seedPurchaseWithItems(int $items): array
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
            'gda_order_id' => 'GDA-ORDER-'.uniqid(),
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

    private function createVersion(
        LaboratoryResultStatus $status,
        LaboratoryNotification $notification,
        LaboratoryResultPdfClassification $classification,
    ): LaboratoryResultVersion {
        Storage::put('results/gda-phase5.pdf', "%PDF-1.4\n%%EOF");

        return LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $status->id,
            'laboratory_notification_id' => $notification->id,
            'storage_path' => 'results/gda-phase5.pdf',
            'sha256' => hash('sha256', 'phase5-'.$classification->value),
            'source' => 'gda',
            'classification' => $classification,
            'classification_reason' => $classification === LaboratoryResultPdfClassification::Complete
                ? 'explicit_complete_signal'
                : 'pending_signal',
            'classifier' => 'test',
            'classified_at' => now(),
            'pdf_available_at' => now(),
        ]);
    }

    private function seedAdminUser(): User
    {
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

        $permission = Permission::findOrCreate('laboratory-purchases.manage', 'web');

        $user = $user->fresh('administrator');
        $user->administrator->givePermissionTo($permission);

        return $user->fresh('administrator');
    }

    private function ensureAdminTables(): void
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
    }

    private function pdfWithText(string $text): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body>'.e($text).'</body></html>');
        $dompdf->render();

        return $dompdf->output();
    }
}
