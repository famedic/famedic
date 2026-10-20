<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\GetGDAResultsAction;
use App\Actions\Laboratories\RecordGdaResultPdfVersionAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultStatus as ResultStatusEnum;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\User;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsRecoveryPatientAccessTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        Storage::buildTemporaryUrlsUsing(fn (string $path): string => 'https://results.test/'.$path);
        NotificationFacade::fake();

        config([
            'laboratory-results.otp_required' => false,
            'laboratory-results.recovery.gda_first' => true,
            'laboratory-results.recovery.lock_seconds' => 120,
            'laboratory-results.max_pdf_bytes' => 25 * 1024 * 1024,
        ]);

        $this->bootstrapIsolatedSchema();
        $this->withoutPatientGateMiddleware();
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
    public function flag_off_preserva_comportamiento_anterior_si_no_hay_storage(): void
    {
        config(['laboratory-results.recovery.gda_first' => false]);

        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertNotFound();

        $this->assertNull($purchase->fresh()->results);
    }

    #[Test]
    public function historico_sin_s3_se_recupera_desde_gda_y_actualiza_results(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $this->mockGdaPdf('Resultado final. Interpretacion realizada.');

        $response = $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase));

        $response->assertOk()
            ->assertJsonPath('url', 'https://results.test/'.$purchase->fresh()->results);

        $purchase->refresh();
        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertStringStartsWith('results/gda-', $purchase->results);
    }

    #[Test]
    public function segundo_acceso_a_resultado_recuperado_usa_s3_sin_consultar_gda(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $base64 = base64_encode($this->pdfWithText('Resultado final. Interpretacion realizada.'));

        $this->mock(GetGDAResultsAction::class, function ($mock) use ($base64) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $base64]);
        });

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertOk();

        $recoveredPath = $purchase->fresh()->results;
        $this->assertNotEmpty($recoveredPath);
        $this->assertTrue(Storage::exists($recoveredPath));

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertOk()
            ->assertJsonPath('url', 'https://results.test/'.$recoveredPath);

        $this->assertSame($recoveredPath, $purchase->fresh()->results);
    }

    #[Test]
    public function path_historico_inexistente_se_recupera_desde_gda_y_reemplaza_results(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems([
            'results' => 'results/old-historical.pdf',
        ]);
        $this->seedResultsNotificationRecord($purchase);

        $this->assertFalse(Storage::exists('results/old-historical.pdf'));

        $this->mockGdaPdf('Resultado final. Interpretacion realizada.');

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertOk();

        $purchase->refresh();
        $this->assertNotSame('results/old-historical.pdf', $purchase->results);
        $this->assertStringStartsWith('results/gda-', $purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
    }

    #[Test]
    public function fallo_de_clasificacion_no_permite_servir_resultado_via_legacy_fallback(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $this->mockGdaPdf('Resultado final. Interpretacion realizada.');

        $this->mock(RecordGdaResultPdfVersionAction::class, function ($mock) {
            $mock->shouldReceive('execute')
                ->once()
                ->andThrow(new \RuntimeException('classifier unavailable'));
        });

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertStatus(503)
            ->assertJsonPath('message', 'No fue posible obtener tus resultados en este momento. Intenta nuevamente en unos minutos.');

        $this->assertNull($purchase->fresh()->results);
        $this->assertCount(1, Storage::allFiles());
    }

    #[Test]
    public function pdf_recuperado_con_revision_manual_se_sirve_al_paciente(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $this->mockGdaPdf('Documento recibido sin patron deterministico para clasificacion automatica.');

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertOk()
            ->assertJsonPath('url', 'https://results.test/'.$purchase->fresh()->results);

        $purchase->refresh();
        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertSame(
            ResultStatusEnum::ManualReview,
            $purchase->laboratoryResultStatuses()->firstOrFail()->status,
        );
    }

    #[Test]
    public function gda_falla_y_s3_existente_se_usa_como_fallback(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $path = $this->storeGdaPdf($purchase, 'existing');
        $this->seedResultsNotificationRecord($purchase);

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andThrow(new \RuntimeException('GDA down'));
        });

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertOk()
            ->assertJsonPath('url', 'https://results.test/'.$path);

        $this->assertSame($path, $purchase->fresh()->results);
    }

    #[Test]
    public function gda_falla_y_s3_no_existe_devuelve_error_controlado(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andThrow(new \RuntimeException('GDA down'));
        });

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertStatus(503)
            ->assertJsonPath('message', 'No fue posible obtener tus resultados en este momento. Intenta nuevamente en unos minutos.');

        $this->assertNull($purchase->fresh()->results);
    }

    #[Test]
    public function base64_invalido_no_guarda_ni_actualiza_results(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andReturn(['infogda_resultado_b64' => '%%%invalid%%%']);
        });

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertStatus(503);

        $this->assertNull($purchase->fresh()->results);
        $this->assertCount(0, Storage::allFiles());
    }

    #[Test]
    public function contenido_no_pdf_no_guarda_ni_actualiza_results(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andReturn([
                'infogda_resultado_b64' => base64_encode('<html>not a pdf</html>'),
            ]);
        });

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertStatus(503);

        $this->assertNull($purchase->fresh()->results);
        $this->assertCount(0, Storage::allFiles());
    }

    #[Test]
    public function pdf_mayor_al_limite_no_guarda_ni_actualiza_results(): void
    {
        config(['laboratory-results.max_pdf_bytes' => 10]);

        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $this->mockGdaPdf('Resultado final. Interpretacion realizada.');

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertStatus(503);

        $this->assertNull($purchase->fresh()->results);
        $this->assertCount(0, Storage::allFiles());
    }

    #[Test]
    public function usuario_no_autorizado_no_llama_gda(): void
    {
        [, $purchase] = $this->seedPatientPurchaseWithItems();
        $otherUser = $this->seedUser();
        $this->seedResultsNotificationRecord($purchase);

        $this->assertNotSame($otherUser->customer?->id, $purchase->customer_id);

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $this->actingAs($otherUser)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertForbidden();

        $this->assertNull($purchase->fresh()->results);
    }

    #[Test]
    public function lock_en_progreso_no_llama_gda_ni_devuelve_500(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $lock = Cache::lock("laboratory-results-recovery:{$purchase->id}", 120);
        $this->assertTrue($lock->get());

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        try {
            $this->actingAs($user)
                ->getJson(route('laboratory-purchases.results', $purchase))
                ->assertStatus(409)
                ->assertJsonPath('message', 'Ya estamos obteniendo tus resultados. Intenta nuevamente en unos segundos.');
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function resultado_parcial_no_actualiza_results_ni_se_sirve_como_definitivo(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems();
        $this->seedResultsNotificationRecord($purchase);

        $this->mockGdaPdf('La interpretacion de este estudio aun no se ha realizado.');

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Tus resultados aún se están procesando. Intenta nuevamente más tarde.');

        $this->assertNull($purchase->fresh()->results);
        $this->assertTrue($purchase->fresh()->laboratoryResultStatuses()->exists());
    }

    #[Test]
    public function pdf_manual_existente_no_se_sobrescribe_con_recovery_gda_first(): void
    {
        [$user, $purchase] = $this->seedPatientPurchaseWithItems(['results' => 'results/manual-admin.pdf']);
        Storage::put('results/manual-admin.pdf', $this->pdfWithText('PDF manual'));
        $this->seedResultsNotificationRecord($purchase);

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $this->actingAs($user)
            ->getJson(route('laboratory-purchases.results', $purchase))
            ->assertOk()
            ->assertJsonPath('url', 'https://results.test/results/manual-admin.pdf');

        $this->assertSame('results/manual-admin.pdf', $purchase->fresh()->results);
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

    private function mockGdaPdf(string $text): void
    {
        $base64 = base64_encode($this->pdfWithText($text));

        $this->mock(GetGDAResultsAction::class, function ($mock) use ($base64) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $base64]);
        });
    }

    /**
     * @return array{0: User, 1: LaboratoryPurchase}
     */
    private function seedPatientPurchaseWithItems(array $purchaseOverrides = []): array
    {
        $purchase = $this->seedPurchase($purchaseOverrides);

        LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'IMG-1',
            'name' => 'Estudio de imagen',
            'price_cents' => 10000,
        ]);

        return [$purchase->customer->user, $purchase];
    }

    private function seedUser(): User
    {
        return User::query()->create([
            'name' => 'Paciente Ajeno',
            'email' => 'other-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);
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

    private function storeGdaPdf(LaboratoryPurchase $purchase, string $marker): string
    {
        $binary = $this->pdfWithText('Resultado final. Interpretacion realizada. '.$marker);
        $path = sprintf(
            GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN,
            $purchase->id,
            substr(hash('sha256', $binary), 0, 12)
        );
        Storage::put($path, $binary);
        $purchase->update(['results' => $path]);

        return $path;
    }

    private function pdfWithText(string $text): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body>'.e($text).'</body></html>');
        $dompdf->render();

        return $dompdf->output();
    }
}
