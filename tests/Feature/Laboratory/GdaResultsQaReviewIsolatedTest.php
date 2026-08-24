<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Actions\Laboratories\SyncGdaResultPdfToStorageAction;
use App\Actions\Laboratory\HandleResultsNotificationAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsQaReviewIsolatedTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        NotificationFacade::fake();

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

    // --- Monitor: storage/S3 indicators ---

    #[Test]
    public function monitor_muestra_archivo_en_storage_si_cuando_purchase_results_existe(): void
    {
        $this->travelTo(now()->subHour());
        $purchase = $this->seedPurchase(['results' => 'results/gda-1-abc.pdf']);
        Storage::put('results/gda-1-abc.pdf', $this->samplePdfBinary());
        touch(Storage::path('results/gda-1-abc.pdf'), now()->timestamp);
        $this->seedResultsNotificationRecord($purchase, [
            'results_received_at' => now()->subMinutes(10),
        ]);
        $this->travelBack();

        $user = $this->seedAdminUser();
        $orderKey = $purchase->gda_order_id;

        $response = $this->actingAs($user)
            ->getJson(route('admin.laboratory-notifications-monitor.order-details', ['orderKey' => $orderKey]));

        $response->assertOk();
        $response->assertJsonPath('summary.results_pdf.has_pdf_in_storage', true);
        $response->assertJsonPath('summary.results_pdf.location', 'storage');
        $response->assertJsonPath('summary.results_pdf.is_gda_automatic', true);
        $response->assertJsonPath('summary.results_pdf.is_stale', false);
        $response->assertJsonPath('summary.results_pdf.freshness_status', 'gda_current');
    }

    #[Test]
    public function monitor_marca_stale_si_pdf_gda_en_storage_y_notificacion_mas_nueva(): void
    {
        $this->travelTo(now()->subDays(3));
        $purchase = $this->seedPurchase(['results' => 'results/gda-2309-oldhash12.pdf']);
        Storage::put('results/gda-2309-oldhash12.pdf', $this->samplePdfBinary());
        touch(Storage::path('results/gda-2309-oldhash12.pdf'), now()->timestamp);
        $this->seedResultsNotificationRecord($purchase, [
            'results_received_at' => now(),
            'gda_message' => [
                'results_source' => 'storage',
                'results_fetched_at' => now()->toISOString(),
            ],
        ]);

        $this->travelBack();
        $this->seedResultsNotificationRecord($purchase, [
            'results_received_at' => now(),
        ]);

        $user = $this->seedAdminUser();
        $orderKey = $purchase->gda_order_id;

        $response = $this->actingAs($user)
            ->getJson(route('admin.laboratory-notifications-monitor.order-details', ['orderKey' => $orderKey]));

        $response->assertOk();
        $response->assertJsonPath('summary.results_pdf.has_pdf_in_storage', true);
        $response->assertJsonPath('summary.results_pdf.available_at_gda', true);
        $response->assertJsonPath('summary.results_pdf.is_stale', true);
        $response->assertJsonPath('summary.results_pdf.has_newer_results', true);
        $response->assertJsonPath('summary.results_pdf.is_automatic_overwrite_candidate', true);
        $response->assertJsonPath('summary.results_pdf.location', 'storage_stale');
        $response->assertJsonPath('summary.results_pdf.freshness_status', 'gda_stale');
        $response->assertJsonPath('summary.results_pdf.is_manual_result', false);
        $this->assertNotEmpty($response->json('summary.results_pdf.stale_lag_label'));
        $this->assertNotEmpty($response->json('summary.results_pdf.stored_pdf_at'));
        $this->assertNotEmpty($response->json('summary.results_pdf.latest_results_at'));
    }

    #[Test]
    public function monitor_muestra_base64_legacy_en_bd_si_cuando_results_pdf_base64_existe(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase, [
            'results_pdf_base64' => $this->samplePdfBase64(),
        ]);

        $user = $this->seedAdminUser();
        $orderKey = $purchase->gda_order_id;

        $response = $this->actingAs($user)
            ->getJson(route('admin.laboratory-notifications-monitor.order-details', ['orderKey' => $orderKey]));

        $response->assertOk();
        $response->assertJsonPath('summary.results_pdf.has_pdf_in_db', true);
        $response->assertJsonPath('summary.results_pdf.has_pdf_in_storage', false);
    }

    // --- Botón descargar usa storage si purchase.results existe ---

    #[Test]
    public function boton_descargar_usa_storage_si_purchase_results_existe(): void
    {
        $purchase = $this->seedPurchase(['results' => 'results/gda-test.pdf']);
        Storage::put('results/gda-test.pdf', $this->samplePdfBinary());
        $notification = $this->seedResultsNotificationRecord($purchase);

        $user = $this->seedAdminUser();
        $orderKey = $purchase->gda_order_id;

        $response = $this->actingAs($user)
            ->get(route('admin.laboratory-notifications-monitor.download-results', ['orderKey' => $orderKey]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    // --- Forzar/sincronizar desde GDA guarda en storage, no en base64 ---

    #[Test]
    public function forzar_actualizacion_desde_gda_guarda_en_storage_no_en_base64(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64()]);
        });

        $user = $this->seedAdminUser();
        $orderKey = $purchase->gda_order_id;

        $response = $this->actingAs($user)
            ->postJson(route('admin.laboratory-notifications-monitor.force-refresh-results', ['orderKey' => $orderKey]));

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $purchase->refresh();
        $notification->refresh();

        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertNull($notification->results_pdf_base64);
    }

    // --- Resultado manual no se sobrescribe ---

    #[Test]
    public function resultado_manual_no_se_sobrescribe_por_gda_automatico(): void
    {
        $purchase = $this->seedPurchase(['results' => 'results/manual-upload.pdf']);
        Storage::put('results/manual-upload.pdf', $this->samplePdfBinary());
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $user = $this->seedAdminUser();
        $orderKey = $purchase->gda_order_id;

        $response = $this->actingAs($user)
            ->postJson(route('admin.laboratory-notifications-monitor.force-refresh-results', ['orderKey' => $orderKey]));

        $response->assertOk();

        $purchase->refresh();
        $this->assertSame('results/manual-upload.pdf', $purchase->results);
    }

    // --- Paciente descarga resultado automático desde storage ---

    #[Test]
    public function paciente_descarga_resultado_automatico_desde_storage(): void
    {
        $purchase = $this->seedPurchase(['results' => 'results/gda-auto.pdf']);
        Storage::put('results/gda-auto.pdf', $this->samplePdfBinary());

        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
    }

    // --- Paciente descarga resultado manual desde storage ---

    #[Test]
    public function paciente_descarga_resultado_manual_desde_storage(): void
    {
        $purchase = $this->seedPurchase(['results' => 'results/manual-admin.pdf']);
        Storage::put('results/manual-admin.pdf', $this->samplePdfBinary());

        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
    }

    // --- Paciente con histórico base64 sigue funcionando ---

    #[Test]
    public function paciente_con_historico_base64_sigue_funcionando(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase, [
            'results_pdf_base64' => $this->samplePdfBase64(),
        ]);

        $result = app(ResolveGdaResultsPdfAction::class)($notification);

        $purchase->refresh();

        $this->assertNotEmpty($result['pdf_base64']);
        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
    }

    // --- Fallback lazy funciona si job no corrió ---

    #[Test]
    public function fallback_lazy_funciona_si_job_no_corrio(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64()]);
        });

        $result = app(ResolveGdaResultsPdfAction::class)($notification);

        $purchase->refresh();

        $this->assertTrue($result['refreshed']);
        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
    }

    // --- Gabinete descarga correctamente ---

    #[Test]
    public function gabinete_descarga_correctamente_con_gda_order_id_alfanumerico(): void
    {
        $purchase = $this->seedPurchase([
            'gda_order_id' => 'HD0L001392',
            'gda_consecutivo' => 1392,
            'results' => 'results/gda-gabinete.pdf',
        ]);
        Storage::put('results/gda-gabinete.pdf', $this->samplePdfBinary());

        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertSame('HD0L001392', $purchase->gda_order_id);
    }

    // --- Laboratorio normal descarga correctamente ---

    #[Test]
    public function laboratorio_normal_descarga_correctamente_con_gda_order_id_numerico(): void
    {
        $purchase = $this->seedPurchase([
            'gda_order_id' => '99001',
            'gda_consecutivo' => 99001,
            'results' => 'results/gda-lab-normal.pdf',
        ]);
        Storage::put('results/gda-lab-normal.pdf', $this->samplePdfBinary());

        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertSame('99001', $purchase->gda_order_id);
    }

    // --- Safe mode bloquea correos a pacientes no autorizados ---

    #[Test]
    public function en_qa_safe_mode_correos_a_pacientes_no_autorizados_se_bloquean(): void
    {
        $this->app['env'] = 'staging';
        config([
            'mail.safe_mode.enabled' => true,
            'mail.safe_mode.allowed_recipients' => ['qa@famedic.com.mx'],
            'mail.safe_mode.allowed_domains' => ['famedic.com.mx'],
            'mail.safe_mode.block_disallowed' => true,
            'mail.safe_mode.log_blocked' => true,
            'mail.default' => 'array',
        ]);

        $listener = app(\App\Listeners\ApplyMailSafetyPolicy::class);
        $event = $this->makeMessageSendingEvent(['paciente@gmail.com']);
        $result = $listener->handle($event);

        $this->assertFalse($result);
    }

    // --- Monitor muestra sync_logs ---

    #[Test]
    public function monitor_incluye_sync_logs_con_datos_de_sincronizacion(): void
    {
        $purchase = $this->seedPurchase(['results' => 'results/gda-sync.pdf']);
        Storage::put('results/gda-sync.pdf', $this->samplePdfBinary());
        $notification = $this->seedResultsNotificationRecord($purchase, [
            'gda_message' => [
                'results_fetched_at' => now()->toISOString(),
                'results_source' => 'storage',
                'results_storage_path' => 'results/gda-sync.pdf',
            ],
        ]);

        $user = $this->seedAdminUser();
        $orderKey = $purchase->gda_order_id;

        $response = $this->actingAs($user)
            ->getJson(route('admin.laboratory-notifications-monitor.order-details', ['orderKey' => $orderKey]));

        $response->assertOk();
        $response->assertJsonStructure([
            'summary' => [
                'sync_logs' => [
                    '*' => [
                        'notification_id',
                        'results_source',
                        'results_storage_path',
                        'stored_in_storage',
                        'purchase_results_path',
                    ],
                ],
            ],
        ]);

        $this->assertNotEmpty($response->json('summary.sync_logs'));
    }

    // ---- Helpers ----

    private function makeMessageSendingEvent(array $to): \Illuminate\Mail\Events\MessageSending
    {
        $message = (new \Symfony\Component\Mime\Email())
            ->subject('Test')
            ->text('Test body');

        foreach ($to as $email) {
            $message->addTo($email);
        }

        return new \Illuminate\Mail\Events\MessageSending($message);
    }

    private function seedResultsNotificationRecord(LaboratoryPurchase $purchase, array $overrides = []): LaboratoryNotification
    {
        return LaboratoryNotification::query()->create(array_merge([
            'laboratory_purchase_id' => $purchase->id,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
            'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
            'gda_order_id' => $purchase->gda_order_id,
            'gda_consecutivo' => $purchase->gda_consecutivo,
            'status' => LaboratoryNotification::STATUS_RECEIVED,
            'gda_status' => LaboratoryNotification::GDA_STATUS_COMPLETED,
            'resource_type' => 'ServiceRequest',
            'results_received_at' => now(),
            'payload' => [
                'header' => ['marca' => 5],
                'requisition' => ['convenio' => 99999, 'value' => 'REQ-1'],
                'id' => $purchase->gda_order_id,
            ],
        ], $overrides));
    }

    private function samplePdfBinary(): string
    {
        return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
    }

    private function samplePdfBase64(): string
    {
        return base64_encode($this->samplePdfBinary());
    }

    private function seedPurchase(array $overrides = []): LaboratoryPurchase
    {
        $user = User::query()->create([
            'name' => 'Paciente Test',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
        ]);

        return LaboratoryPurchase::query()->create(array_merge([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => 'GDA-ORDER-'.uniqid(),
            'gda_consecutivo' => 100,
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
        ], $overrides));
    }

    private function seedAdminUser(): User
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('administrators')) {
            \Illuminate\Support\Facades\Schema::create('administrators', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained();
                $table->timestamps();
            });
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('permissions')) {
            \Illuminate\Support\Facades\Schema::create('permissions', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name')->default('web');
                $table->timestamps();
            });
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('model_has_permissions')) {
            \Illuminate\Support\Facades\Schema::create('model_has_permissions', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_type', 'model_id']);
            });
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('roles')) {
            \Illuminate\Support\Facades\Schema::create('roles', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name')->default('web');
                $table->timestamps();
            });
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('model_has_roles')) {
            \Illuminate\Support\Facades\Schema::create('model_has_roles', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_type', 'model_id']);
            });
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('role_has_permissions')) {
            \Illuminate\Support\Facades\Schema::create('role_has_permissions', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
        }

        $user = User::query()->create([
            'name' => 'Admin Test',
            'email' => 'admin-'.uniqid().'@famedic.com.mx',
            'password' => bcrypt('secret'),
        ]);

        $admin = \Illuminate\Support\Facades\DB::table('administrators')->insertGetId([
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permission = \Spatie\Permission\Models\Permission::findOrCreate('laboratory-notifications.monitor', 'web');

        \Illuminate\Support\Facades\DB::table('model_has_permissions')->insert([
            'permission_id' => $permission->id,
            'model_type' => \App\Models\Administrator::class,
            'model_id' => $admin,
        ]);

        $user->load('administrator');

        return $user;
    }
}
