<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\RecordPatientResultsAccessAction;
use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Actions\Laboratories\SyncGdaResultPdfToStorageAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\LaboratoryResultsController;
use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsStoragePhase4PatientAccessSemanticsIsolatedTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        Storage::buildTemporaryUrlsUsing(function (string $path): string {
            return 'https://results.test/'.$path;
        });
        NotificationFacade::fake();
        config(['laboratory-results.otp_required' => false]);

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
    public function test_1_fetch_gda_automatico_no_cuenta_como_lectura_del_paciente(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('A')]);
        });

        app(ResolveGdaResultsPdfAction::class)($notification);

        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_2_job_refresh_de_pdf_no_cuenta_como_lectura(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $this->storeGdaPdf($purchase, 'A');
        $notifA = $this->seedResultsNotificationRecord($purchase, [
            'read_at' => now()->addMinutes(20),
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notifB = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('B')]);
        });

        $pathB = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notifB->id);
        touch(Storage::path($pathB), now()->addMinute()->timestamp);

        $this->assertNotNull($notifA->fresh()->read_at);
        $this->assertNull($notifB->fresh()->read_at);
        $this->assertNotEmpty(data_get($notifB->fresh()->gda_message, 'results_fetched_at'));
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_3_notif_posterior_al_ultimo_read_at_es_resultado_nuevo(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $this->seedResultsNotificationRecord($purchase, ['read_at' => Carbon::parse('2026-08-21 17:00:00')]);

        $this->travelTo(Carbon::parse('2026-08-21 17:43:00'));
        $this->seedResultsNotificationRecord($purchase);

        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertTrue(LaboratoryNotification::hasUpdatedResultsSinceLastPatientAccess(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        ));
    }

    #[Test]
    public function test_4_paciente_abre_pdf_current_marca_notificaciones_cubiertas(): void
    {
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $path = $this->storeGdaPdf($purchase, 'A');
        $notifA = $this->seedResultsNotificationRecord($purchase);
        $notifA->update(['results_received_at' => Carbon::parse('2026-08-21 16:38:00')]);

        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());

        $response = $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));

        $response->assertRedirect('https://results.test/'.$path);
        $this->assertNotNull($notifA->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_5_paciente_abre_pdf_stale_notif_nueva_sigue_unread(): void
    {
        Bus::fake();
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase, [
            'read_at' => Carbon::parse('2026-08-21 17:00:00'),
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notifB = $this->seedResultsNotificationRecord($purchase);

        $response = $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));

        $response->assertRedirect('https://results.test/'.$pathA);
        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class);
        $this->assertNull($notifB->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_6_despues_del_refresh_antes_de_reabrir_sigue_new(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase, [
            'read_at' => Carbon::parse('2026-08-21 17:00:00'),
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notifB = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('B')]);
        });

        $pathB = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notifB->id);
        touch(Storage::path($pathB), now()->addMinute()->timestamp);

        $this->assertNull($notifB->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_7_despues_de_abrir_pdf_actualizado_new_false(): void
    {
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase, [
            'read_at' => Carbon::parse('2026-08-21 17:00:00'),
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notifB = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('B')]);
        });

        $pathB = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notifB->id);
        touch(Storage::path($pathB), now()->addMinute()->timestamp);

        $response = $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));

        $response->assertRedirect('https://results.test/'.$pathB);
        $this->assertNotNull($notifB->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_8_results_fetched_at_posterior_no_apaga_el_badge(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase, [
            'read_at' => Carbon::parse('2026-08-21 17:00:00'),
            'gda_message' => [
                'results_source' => 'storage',
                'results_fetched_at' => Carbon::parse('2026-08-21 16:40:00')->toISOString(),
            ],
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $this->seedResultsNotificationRecord($purchase, [
            'gda_message' => [
                'results_source' => 'gda_api',
                'results_fetched_at' => now()->toISOString(),
            ],
        ]);

        $lastAccess = LaboratoryNotification::lastPatientResultsAccessAtForOrder(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        );

        $this->assertNotNull($lastAccess);
        $this->assertTrue($lastAccess->equalTo(Carbon::parse('2026-08-21 17:00:00')));
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_9_pending_results_count_coincide_con_detalle(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase, [
            'read_at' => Carbon::parse('2026-08-21 17:00:00'),
        ]);
        $user = $purchase->customer->user;

        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(0, $user->fresh()->pending_results_count);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $this->seedResultsNotificationRecord($purchase, [
            'gda_message' => [
                'results_fetched_at' => now()->toISOString(),
            ],
        ]);

        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(1, $user->fresh()->pending_results_count);

        $this->travelTo(Carbon::parse('2026-08-24 12:30:00'));
        $this->storeGdaPdf($purchase, 'B');
        app(RecordPatientResultsAccessAction::class)->execute($purchase->fresh());

        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(0, $user->fresh()->pending_results_count);
    }

    #[Test]
    public function test_10_automatic_fetch_tecnico_no_consume_badge(): void
    {
        Bus::fake();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase, [
            'read_at' => Carbon::parse('2026-08-21 17:00:00'),
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notifB = $this->seedResultsNotificationRecord($purchase);

        $response = app(LaboratoryResultsController::class)->fetch(
            Request::create('/fake', 'POST'),
            $purchase->id
        );

        $this->assertTrue($response->getData(true)['success']);
        $this->assertTrue($response->getData(true)['cached']);
        $this->assertNull($notifB->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_11_view_current_consume_estado_nuevo(): void
    {
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $this->storeGdaPdf($purchase, 'A');
        $notification = $this->seedResultsNotificationRecord($purchase);
        $notification->update(['results_received_at' => Carbon::parse('2026-08-21 16:38:00')]);

        $view = $this->actingAs($user->fresh())
            ->get(route('laboratory-results.view', ['type' => 'purchase', 'id' => $purchase->id]));

        $view->assertRedirect(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));

        $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]))
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_12_download_current_consume_estado_nuevo(): void
    {
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $this->storeGdaPdf($purchase, 'A');
        $notification = $this->seedResultsNotificationRecord($purchase);
        $notification->update(['results_received_at' => Carbon::parse('2026-08-21 16:38:00')]);

        $download = $this->actingAs($user->fresh())
            ->get(route('laboratory-results.download', ['type' => 'purchase', 'id' => $purchase->id]));

        $download->assertRedirect(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));

        $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]))
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_13_results_controller_current_consume_estado_nuevo(): void
    {
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $path = $this->storeGdaPdf($purchase, 'A');
        $notification = $this->seedResultsNotificationRecord($purchase);
        $notification->update(['results_received_at' => Carbon::parse('2026-08-21 16:38:00')]);

        $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]))
            ->assertRedirect('https://results.test/'.$path);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_14_pdf_manual_mantiene_comportamiento_correcto(): void
    {
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase] = $this->seedPatientPurchase(['results' => 'results/manual-admin.pdf']);
        Storage::put('results/manual-admin.pdf', $this->samplePdfBinary('manual'));
        touch(Storage::path('results/manual-admin.pdf'), now()->timestamp);
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(0, $user->fresh()->pending_results_count);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $later = $this->seedResultsNotificationRecord($purchase);

        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame('results/manual-admin.pdf', $purchase->fresh()->results);

        $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]))
            ->assertRedirect('https://results.test/results/manual-admin.pdf');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($later->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame('results/manual-admin.pdf', $purchase->fresh()->results);
    }

    #[Test]
    public function test_15_carrera_stale_refresh_luego_abrir_current(): void
    {
        Bus::fake();
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-21 17:43:00'));
        $notifB = $this->seedResultsNotificationRecord($purchase);

        $staleResponse = $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));

        $staleResponse->assertRedirect('https://results.test/'.$pathA);
        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class);
        $this->assertNull($notifB->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());

        Bus::fake();
        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('B')]);
        });

        $pathB = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notifB->id);
        touch(Storage::path($pathB), now()->addMinute()->timestamp);

        $this->assertNull($notifB->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());

        $currentResponse = $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));

        $currentResponse->assertRedirect('https://results.test/'.$pathB);
        $this->assertNotNull($notifB->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_16_no_marca_leido_si_el_archivo_no_existe(): void
    {
        $this->withoutPatientGateMiddleware();

        [$user, $purchase] = $this->seedPatientPurchase(['results' => 'results/gda-missing.pdf']);
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function replay_pedido_2309_badge_sobrevive_al_job_hasta_abrir_pdf_final(): void
    {
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase] = $this->seedPatientPurchase(['gda_order_id' => 'GDA-2309']);
        $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]))
            ->assertRedirect();
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(0, $user->fresh()->pending_results_count);

        $this->travelTo(Carbon::parse('2026-08-21 17:43:00'));
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-21 18:10:00'));
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notifF = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('F')]);
        });

        $pathF = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notifF->id);
        touch(Storage::path($pathF), now()->addMinute()->timestamp);

        $this->assertNotEmpty(data_get($notifF->fresh()->gda_message, 'results_fetched_at'));
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(1, $user->fresh()->pending_results_count);
        $this->assertNull($notifF->fresh()->read_at);

        $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]))
            ->assertRedirect('https://results.test/'.$pathF);

        $this->assertNotNull($notifF->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(0, $user->fresh()->pending_results_count);
    }

    private function withoutPatientGateMiddleware(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            \App\Http\Middleware\EnsurePhoneIsVerified::class,
            \App\Http\Middleware\EnsureUserHasCustomerAccount::class,
            \App\Http\Middleware\EnsureLabResultsOtpVerified::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
    }

    /**
     * @return array{0: User, 1: LaboratoryPurchase}
     */
    private function seedPatientPurchase(array $overrides = []): array
    {
        $purchase = $this->seedPurchase($overrides);
        $user = $purchase->customer->user;

        return [$user, $purchase];
    }

    private function storeGdaPdf(LaboratoryPurchase $purchase, string $marker): string
    {
        $binary = $this->samplePdfBinary($marker);
        $path = sprintf(
            GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN,
            $purchase->id,
            substr(hash('sha256', $binary), 0, 12)
        );
        Storage::put($path, $binary);
        touch(Storage::path($path), now()->timestamp);
        $purchase->update(['results' => $path]);

        return $path;
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

    private function samplePdfBinary(string $marker = 'A'): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Marker ({$marker}) >>\nendobj\ntrailer\n<<>>\n%%EOF";
    }

    private function samplePdfBase64(string $marker = 'A'): string
    {
        return base64_encode($this->samplePdfBinary($marker));
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
}
