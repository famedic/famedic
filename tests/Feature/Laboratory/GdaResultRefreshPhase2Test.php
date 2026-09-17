<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\GetGDAResultsAction;
use App\Actions\Laboratories\RefreshGdaLaboratoryResultAction;
use App\Actions\Laboratories\StoreGdaResultsPdfToStorageAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultPdfClassification;
use App\Enums\LaboratoryResultStatus as ResultStatusEnum;
use App\Exceptions\GdaResultsNotAvailableException;
use App\Jobs\Laboratory\RefreshGdaLaboratoryResultJob;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultRefreshPhase2Test extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        Config::set('services.gda.result_refresh.enabled', true);
        Config::set('services.gda.result_refresh.max_attempts', 5);
        Config::set('services.gda.result_refresh.backoff_minutes', [30, 60, 120, 240, 480]);

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
    public function pending_interpretation_programa_primer_next_check(): void
    {
        $this->travelTo('2026-09-16 18:00:00');

        [$purchase, $notification] = $this->seedPurchaseAndNotification();

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado.'),
            $notification
        );

        $status = LaboratoryResultStatus::query()->firstOrFail();

        $this->assertSame(ResultStatusEnum::PendingInterpretation, $status->status);
        $this->assertSame('2026-09-16 18:30:00', $status->next_check_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $status->check_attempts);
    }

    #[Test]
    public function feature_flag_off_no_programa_refresh_automatico(): void
    {
        Config::set('services.gda.result_refresh.enabled', false);

        [$purchase, $notification] = $this->seedPurchaseAndNotification();

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado.'),
            $notification
        );

        $this->assertNull(LaboratoryResultStatus::query()->firstOrFail()->next_check_at);
    }

    #[Test]
    public function refresh_con_mismo_pdf_no_crea_version_y_reagenda_con_backoff(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        [$purchase, $notification] = $this->seedPendingResult();
        $pdfBase64 = base64_encode(Storage::get($purchase->fresh()->results));

        $this->travelTo('2026-09-16 18:31:00');
        $this->mock(GetGDAResultsAction::class, function ($mock) use ($pdfBase64) {
            $mock->shouldReceive('__invoke')->once()->andReturn(['infogda_resultado_b64' => $pdfBase64]);
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute(LaboratoryResultStatus::query()->firstOrFail()->id);

        $status = LaboratoryResultStatus::query()->firstOrFail();

        $this->assertSame(ResultStatusEnum::PendingInterpretation, $status->status);
        $this->assertSame(1, $status->check_attempts);
        $this->assertSame('2026-09-16 19:31:00', $status->next_check_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, LaboratoryResultVersion::query()->count());
        $this->assertSame(1, LaboratoryResultEvent::query()->where('event_type', LaboratoryResultEventType::RefreshChecked->value)->count());
    }

    #[Test]
    public function refresh_con_nuevo_pdf_pendiente_crea_version_y_sigue_pending(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        [$purchase] = $this->seedPendingResult();

        $this->travelTo('2026-09-16 18:31:00');
        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andReturn([
                'infogda_resultado_b64' => $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado. Version 2.'),
            ]);
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute(LaboratoryResultStatus::query()->firstOrFail()->id);

        $status = LaboratoryResultStatus::query()->firstOrFail();

        $this->assertSame(ResultStatusEnum::PendingInterpretation, $status->status);
        $this->assertSame(2, LaboratoryResultVersion::query()->count());
        $this->assertSame(1, $status->check_attempts);
        $this->assertTrue(Storage::exists($purchase->fresh()->results));
        $this->assertSame(1, LaboratoryResultEvent::query()->where('event_type', LaboratoryResultEventType::PdfChanged->value)->count());
    }

    #[Test]
    public function refresh_con_nuevo_pdf_completo_detiene_reintentos(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        $this->seedPendingResult();

        $this->travelTo('2026-09-16 18:31:00');
        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andReturn([
                'infogda_resultado_b64' => $this->pdfBase64('Resultado final. Interpretacion realizada.'),
            ]);
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute(LaboratoryResultStatus::query()->firstOrFail()->id);

        $status = LaboratoryResultStatus::query()->firstOrFail();
        $version = LaboratoryResultVersion::query()->latest('id')->firstOrFail();

        $this->assertSame(ResultStatusEnum::Complete, $status->status);
        $this->assertSame(LaboratoryResultPdfClassification::Complete, $version->classification);
        $this->assertNotNull($status->interpreted_at);
        $this->assertNull($status->next_check_at);
    }

    #[Test]
    public function refresh_con_nuevo_pdf_unknown_pasa_a_manual_review(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        $this->seedPendingResult();

        $this->travelTo('2026-09-16 18:31:00');
        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andReturn([
                'infogda_resultado_b64' => $this->pdfBase64('Documento sin senal deterministica.'),
            ]);
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute(LaboratoryResultStatus::query()->firstOrFail()->id);

        $status = LaboratoryResultStatus::query()->firstOrFail();

        $this->assertSame(ResultStatusEnum::ManualReview, $status->status);
        $this->assertNull($status->next_check_at);
    }

    #[Test]
    public function max_attempts_pasa_a_manual_review_sin_loop_infinito(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        $this->seedPendingResult();
        $status = LaboratoryResultStatus::query()->firstOrFail();
        $status->forceFill([
            'check_attempts' => 4,
            'next_check_at' => now()->subMinute(),
        ])->save();

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andReturn([
                'infogda_resultado_b64' => base64_encode(Storage::get(LaboratoryPurchase::query()->firstOrFail()->results)),
            ]);
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute($status->id);

        $status = $status->fresh();

        $this->assertSame(ResultStatusEnum::ManualReview, $status->status);
        $this->assertSame(5, $status->check_attempts);
        $this->assertNull($status->next_check_at);
        $this->assertSame(1, LaboratoryResultEvent::query()->where('event_type', LaboratoryResultEventType::RefreshAttemptsExhausted->value)->count());
    }

    #[Test]
    public function webhook_completa_antes_del_job_y_el_job_no_consulta_gda(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        [$purchase, $notification] = $this->seedPendingResult();

        $this->travelTo('2026-09-16 18:15:00');
        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase->fresh(),
            $this->pdfBase64('Resultado final. Interpretacion realizada.'),
            $notification->fresh(),
            overwrite: true,
            preserveExisting: true
        );

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $this->travelTo('2026-09-16 18:31:00');
        app(RefreshGdaLaboratoryResultAction::class)->execute(LaboratoryResultStatus::query()->firstOrFail()->id);

        $this->assertSame(ResultStatusEnum::Complete, LaboratoryResultStatus::query()->firstOrFail()->status);
    }

    #[Test]
    public function dos_refresh_secuenciales_no_duplican_procesamiento_efectivo(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        [$purchase] = $this->seedPendingResult();
        $status = LaboratoryResultStatus::query()->firstOrFail();
        $status->forceFill(['next_check_at' => now()->subMinute()])->save();
        $pdfBase64 = base64_encode(Storage::get($purchase->fresh()->results));

        $this->mock(GetGDAResultsAction::class, function ($mock) use ($pdfBase64) {
            $mock->shouldReceive('__invoke')->once()->andReturn(['infogda_resultado_b64' => $pdfBase64]);
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute($status->id);
        app(RefreshGdaLaboratoryResultAction::class)->execute($status->id);

        $this->assertSame(1, LaboratoryResultVersion::query()->count());
        $this->assertSame(1, LaboratoryResultStatus::query()->firstOrFail()->check_attempts);
    }

    #[Test]
    public function refresh_respeta_pdf_manual_y_no_lo_sobrescribe(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        [$purchase] = $this->seedPendingResult();
        $manualPath = 'results/manual-admin.pdf';
        Storage::put($manualPath, $this->pdfBinary('PDF manual'));
        $purchase->update(['results' => $manualPath]);
        LaboratoryResultStatus::query()->firstOrFail()->forceFill(['next_check_at' => now()->subMinute()])->save();

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute(LaboratoryResultStatus::query()->firstOrFail()->id);

        $this->assertSame($manualPath, $purchase->fresh()->results);
        $this->assertNull(LaboratoryResultStatus::query()->firstOrFail()->next_check_at);
    }

    #[Test]
    public function mismo_webhook_repetido_no_posterga_next_check(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        [$purchase, $notification] = $this->seedPurchaseAndNotification();
        $pdfBase64 = $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado.');

        app(StoreGdaResultsPdfToStorageAction::class)->execute($purchase, $pdfBase64, $notification);
        $firstNext = LaboratoryResultStatus::query()->firstOrFail()->next_check_at;

        $this->travelTo('2026-09-16 18:05:00');
        app(StoreGdaResultsPdfToStorageAction::class)->execute($purchase->fresh(), $pdfBase64, $notification->fresh());

        $this->assertTrue($firstNext->equalTo(LaboratoryResultStatus::query()->firstOrFail()->next_check_at));
    }

    #[Test]
    public function command_despacha_solo_status_pending_vencidos(): void
    {
        Bus::fake();

        $this->travelTo('2026-09-16 18:00:00');
        $this->seedPendingResult();
        LaboratoryResultStatus::query()->firstOrFail()->forceFill(['next_check_at' => now()->subMinute()])->save();

        $this->artisan('laboratory-results:dispatch-due-refreshes')
            ->expectsOutput('Dispatched 1 laboratory result refresh job(s).')
            ->assertSuccessful();

        Bus::assertDispatched(RefreshGdaLaboratoryResultJob::class);
    }

    #[Test]
    public function estados_complete_y_manual_review_no_consultan_gda(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        $this->seedPendingResult();
        $status = LaboratoryResultStatus::query()->firstOrFail();
        $status->forceFill([
            'status' => ResultStatusEnum::Complete,
            'next_check_at' => now()->subMinute(),
        ])->save();

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute($status->id);

        $status->forceFill([
            'status' => ResultStatusEnum::ManualReview,
            'next_check_at' => now()->subMinute(),
        ])->save();

        app(RefreshGdaLaboratoryResultAction::class)->execute($status->id);

        $this->assertTrue(true);
    }

    #[Test]
    public function fallo_temporal_gda_mantiene_politica_de_retry(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        $this->seedPendingResult();
        $status = LaboratoryResultStatus::query()->firstOrFail();
        $status->forceFill(['next_check_at' => now()->subMinute()])->save();

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andThrow(new GdaResultsNotAvailableException('GDA-1', 'No contiene resultados'));
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute($status->id);

        $status = $status->fresh();

        $this->assertSame(ResultStatusEnum::PendingInterpretation, $status->status);
        $this->assertSame(1, $status->check_attempts);
        $this->assertNotNull($status->next_check_at);
    }

    #[Test]
    public function fallo_permanente_de_pdf_invalido_queda_en_estado_seguro(): void
    {
        $this->travelTo('2026-09-16 18:00:00');
        $this->seedPendingResult();
        $status = LaboratoryResultStatus::query()->firstOrFail();
        $status->forceFill(['next_check_at' => now()->subMinute()])->save();

        $this->mock(GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andReturn(['infogda_resultado_b64' => base64_encode('not-a-pdf')]);
        });

        app(RefreshGdaLaboratoryResultAction::class)->execute($status->id);

        $this->assertSame(ResultStatusEnum::ManualReview, $status->fresh()->status);
        $this->assertNull($status->fresh()->next_check_at);
    }

    private function seedPendingResult(): array
    {
        [$purchase, $notification] = $this->seedPurchaseAndNotification();

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado.'),
            $notification
        );

        return [$purchase->fresh(), $notification->fresh()];
    }

    private function seedPurchaseAndNotification(): array
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
        ]);

        LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'EST-1',
            'name' => 'Estudio 1',
            'price_cents' => 10000,
        ]);

        $notification = LaboratoryNotification::query()->create([
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
        ]);

        return [$purchase->fresh(['customer.user', 'laboratoryPurchaseItems']), $notification];
    }

    private function pdfBase64(string $text): string
    {
        return base64_encode($this->pdfBinary($text));
    }

    private function pdfBinary(string $text): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body>'.e($text).'</body></html>');
        $dompdf->render();

        return $dompdf->output();
    }
}
