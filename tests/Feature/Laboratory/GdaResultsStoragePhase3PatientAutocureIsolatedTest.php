<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\EnsureLatestGdaResultsPdfAction;
use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Actions\Laboratories\SyncGdaResultPdfToStorageAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Exceptions\GdaResultsNotAvailableException;
use App\Http\Controllers\LaboratoryResultsController;
use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsStoragePhase3PatientAutocureIsolatedTest extends TestCase
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
    public function pdf_gda_current_no_despacha_y_sirve_el_actual(): void
    {
        Bus::fake();

        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        $purchase = $this->seedPurchase();
        $path = $this->storeGdaPdf($purchase, 'A');

        $this->travelTo(Carbon::parse('2026-08-21 16:00:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);
        $notification->update(['results_received_at' => now()]);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $result = app(ResolveGdaResultsPdfAction::class)($notification->fresh());
        $response = app(LaboratoryResultsController::class)->fetch(Request::create('/fake', 'POST'), $purchase->id);

        $this->assertTrue($result['cached']);
        $this->assertFalse($result['refresh_dispatched']);
        $this->assertSame($path, $result['storage_path']);
        $this->assertTrue($response->getData(true)['cached']);
        $this->assertSame($path, $purchase->fresh()->results);
        Bus::assertNotDispatched(SyncGdaResultPdfToStorageJob::class);
    }

    #[Test]
    public function pdf_stale_despacha_refresh_y_sigue_sirviendo_pdf_a(): void
    {
        Bus::fake();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $result = app(ResolveGdaResultsPdfAction::class)($notification);
        $response = app(LaboratoryResultsController::class)->fetch(Request::create('/fake', 'POST'), $purchase->id);

        $this->assertTrue($result['cached']);
        $this->assertTrue($result['refresh_dispatched']);
        $this->assertSame($pathA, $result['storage_path']);
        $this->assertSame($this->samplePdfBinary('A'), base64_decode($result['pdf_base64']));
        $this->assertSame($pathA, $purchase->fresh()->results);
        $this->assertTrue($response->getData(true)['success']);
        $this->assertTrue($response->getData(true)['cached']);
        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class, function (SyncGdaResultPdfToStorageJob $job) use ($purchase) {
            return $job->purchaseId === $purchase->id;
        });
    }

    #[Test]
    public function segundo_acceso_despues_del_job_sirve_pdf_b_sin_nuevo_dispatch(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);

        Bus::fake();
        $first = app(ResolveGdaResultsPdfAction::class)($notification);
        $this->assertTrue($first['refresh_dispatched']);
        $this->assertSame($pathA, $first['storage_path']);
        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class);
        Bus::fake();

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('B')]);
        });

        $pathB = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);
        touch(Storage::path($pathB), now()->addMinute()->timestamp);

        $second = app(ResolveGdaResultsPdfAction::class)($notification->fresh());

        $this->assertFalse($second['refresh_dispatched']);
        $this->assertSame($pathB, $second['storage_path']);
        $this->assertSame($this->samplePdfBinary('B'), base64_decode($second['pdf_base64']));
        Bus::assertNotDispatched(SyncGdaResultPdfToStorageJob::class);
    }

    #[Test]
    public function pdf_manual_no_despacha_y_se_sirve_igual(): void
    {
        Bus::fake();

        $purchase = $this->seedPurchase(['results' => 'results/manual-admin.pdf']);
        Storage::put('results/manual-admin.pdf', $this->samplePdfBinary('manual'));
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $result = app(ResolveGdaResultsPdfAction::class)($notification);
        $ensure = app(EnsureLatestGdaResultsPdfAction::class)->execute($purchase, 'patient_results');

        $this->assertFalse($result['refresh_dispatched']);
        $this->assertFalse($ensure['refresh_dispatched']);
        $this->assertSame('results/manual-admin.pdf', $result['storage_path']);
        $this->assertSame('results/manual-admin.pdf', $purchase->fresh()->results);
        Bus::assertNotDispatched(SyncGdaResultPdfToStorageJob::class);
    }

    #[Test]
    public function refresh_async_falla_paciente_sigue_recibiendo_pdf_a(): void
    {
        Bus::fake();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);

        $response = app(LaboratoryResultsController::class)->fetch(Request::create('/fake', 'POST'), $purchase->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame($pathA, $purchase->fresh()->results);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) use ($purchase) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andThrow(new GdaResultsNotAvailableException(
                    orderId: (string) $purchase->gda_order_id,
                    gdaMessage: 'No contiene resultados.',
                ));
        });

        try {
            app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);
            $this->fail('Se esperaba GdaResultsNotAvailableException');
        } catch (GdaResultsNotAvailableException) {
            // expected
        }

        $purchase->refresh();
        $this->assertSame($pathA, $purchase->results);
        $this->assertTrue(Storage::exists($pathA));
        $this->assertSame($this->samplePdfBinary('A'), Storage::get($pathA));
    }

    #[Test]
    public function unique_lock_del_webhook_impide_segundo_job_efectivo(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $lock = new UniqueLock(Cache::store());
        $queued = new SyncGdaResultPdfToStorageJob($purchase->id, 1);
        $this->assertTrue($lock->acquire($queued));

        $ensure = app(EnsureLatestGdaResultsPdfAction::class)->execute($purchase, 'patient_results');

        $this->assertTrue($ensure['refresh_dispatched']);
        $this->assertSame($pathA, $purchase->fresh()->results);
        $this->assertFalse($lock->acquire(new SyncGdaResultPdfToStorageJob($purchase->id, 2)));

        $lock->release($queued);
    }

    #[Test]
    public function results_controller_stale_despacha_y_usa_temporary_url_de_pdf_a(): void
    {
        Bus::fake();
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $this->seedResultsNotificationRecord($purchase);

        $response = $this->actingAs($user->fresh())
            ->get(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));

        $response->assertRedirect('https://results.test/'.$pathA);
        $this->assertSame($pathA, $purchase->fresh()->results);
        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class);
    }

    #[Test]
    public function view_stale_despacha_refresh_y_redirige_al_pdf_actual(): void
    {
        Bus::fake();
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $this->seedResultsNotificationRecord($purchase);

        $response = $this->actingAs($user->fresh())
            ->get(route('laboratory-results.view', ['type' => 'purchase', 'id' => $purchase->id]));

        $response->assertRedirect(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));
        $this->assertSame($pathA, $purchase->fresh()->results);
        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class);
    }

    #[Test]
    public function download_stale_despacha_refresh_y_redirige_al_pdf_actual(): void
    {
        Bus::fake();
        $this->withoutPatientGateMiddleware();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase] = $this->seedPatientPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $this->seedResultsNotificationRecord($purchase);

        $response = $this->actingAs($user->fresh())
            ->get(route('laboratory-results.download', ['type' => 'purchase', 'id' => $purchase->id]));

        $response->assertRedirect(route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]));
        $this->assertSame($pathA, $purchase->fresh()->results);
        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class);
    }

    #[Test]
    public function sin_pdf_el_fallback_siguen_resolviendo_desde_gda(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('nuevo')]);
        });

        $result = app(ResolveGdaResultsPdfAction::class)($notification);

        $purchase->refresh();
        $this->assertTrue($result['refreshed']);
        $this->assertFalse($result['refresh_dispatched']);
        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertSame($this->samplePdfBinary('nuevo'), Storage::get($purchase->results));
    }

    #[Test]
    public function abrir_pdf_stale_no_marca_como_visto_el_resultado_nuevo(): void
    {
        Bus::fake();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase, [
            'gda_message' => [
                'results_fetched_at' => now()->toISOString(),
                'results_source' => 'storage',
            ],
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $latest = $this->seedResultsNotificationRecord($purchase);

        $this->assertTrue(LaboratoryNotification::hasUpdatedResultsSinceLastPatientAccess(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        ));

        $result = app(ResolveGdaResultsPdfAction::class)($latest);

        $this->assertTrue($result['refresh_dispatched']);
        $this->assertNull($latest->fresh()->read_at);

        $controller = app(\App\Http\Controllers\LaboratoryResultController::class);
        $method = new \ReflectionMethod($controller, 'fetchAndSaveResults');
        $method->setAccessible(true);
        $method->invoke($controller, $latest->fresh());

        $this->assertNull($latest->fresh()->read_at);
        $this->assertTrue(LaboratoryNotification::hasUpdatedResultsSinceLastPatientAccess(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        ));
    }

    #[Test]
    public function webhook_y_paciente_comparten_el_mismo_unique_id(): void
    {
        $webhookJob = new SyncGdaResultPdfToStorageJob(2309, 11);
        $patientJob = new SyncGdaResultPdfToStorageJob(2309);

        $this->assertSame($webhookJob->uniqueId(), $patientJob->uniqueId());
        $this->assertSame('gda-results-pdf:2309', $patientJob->uniqueId());
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
