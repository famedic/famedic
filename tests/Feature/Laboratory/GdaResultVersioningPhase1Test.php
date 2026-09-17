<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\StoreGdaResultsPdfToStorageAction;
use App\Actions\Laboratory\HandleResultsNotificationAction;
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
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultVersioningPhase1Test extends TestCase
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

    #[Test]
    public function crea_estado_por_estudio_y_version_por_hash_de_pdf(): void
    {
        $purchase = $this->seedPurchaseWithItems(2);

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado.')
        );

        $this->assertSame(2, LaboratoryResultStatus::query()->where('laboratory_purchase_id', $purchase->id)->count());
        $this->assertSame(2, LaboratoryResultVersion::query()->count());
        $this->assertTrue(LaboratoryResultStatus::query()
            ->where('status', ResultStatusEnum::PendingInterpretation->value)
            ->whereNotNull('first_available_at')
            ->whereNotNull('last_checked_at')
            ->exists());
    }

    #[Test]
    public function no_duplica_estados_versiones_ni_eventos_al_reprocesar_mismo_pdf(): void
    {
        $purchase = $this->seedPurchaseWithItems();
        $pdfBase64 = $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado.');

        app(StoreGdaResultsPdfToStorageAction::class)->execute($purchase, $pdfBase64);
        app(StoreGdaResultsPdfToStorageAction::class)->execute($purchase->fresh(), $pdfBase64);

        $this->assertSame(1, LaboratoryResultStatus::query()->count());
        $this->assertSame(1, LaboratoryResultVersion::query()->count());
        $this->assertSame(3, LaboratoryResultEvent::query()->count());
        $this->assertSame(1, LaboratoryResultEvent::query()->where('event_type', LaboratoryResultEventType::PdfFetched->value)->count());
        $this->assertSame(1, LaboratoryResultEvent::query()->where('event_type', LaboratoryResultEventType::Classified->value)->count());
        $this->assertSame(1, LaboratoryResultEvent::query()->where('event_type', LaboratoryResultEventType::InterpretationPending->value)->count());
    }

    #[Test]
    public function registra_nueva_version_cuando_cambia_el_hash_del_pdf(): void
    {
        $purchase = $this->seedPurchaseWithItems();

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado.')
        );

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase->fresh(),
            $this->pdfBase64('Resultado disponible para consulta medica. Folio nuevo.'),
            overwrite: true
        );

        $this->assertSame(1, LaboratoryResultStatus::query()->count());
        $this->assertSame(2, LaboratoryResultVersion::query()->count());
        $this->assertSame(1, LaboratoryResultEvent::query()->where('event_type', LaboratoryResultEventType::PdfChanged->value)->count());
    }

    #[Test]
    public function transiciona_a_revision_manual_si_el_pdf_no_tiene_regla_deterministica(): void
    {
        $purchase = $this->seedPurchaseWithItems();

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64('Resultado disponible para consulta medica.')
        );

        $status = LaboratoryResultStatus::query()->firstOrFail();
        $version = LaboratoryResultVersion::query()->firstOrFail();

        $this->assertSame(ResultStatusEnum::ManualReview, $status->status);
        $this->assertSame(LaboratoryResultPdfClassification::Unknown, $version->classification);
        $this->assertSame('unknown_document', $version->classification_reason);
        $this->assertSame(1, LaboratoryResultEvent::query()->where('event_type', LaboratoryResultEventType::ClassificationFailed->value)->count());
    }

    #[Test]
    public function eventos_no_guardan_texto_extraido_del_pdf(): void
    {
        $clinicalText = 'La interpretacion de este estudio aun no se ha realizado. Glucosa 99 mg/dL.';
        $purchase = $this->seedPurchaseWithItems();

        app(StoreGdaResultsPdfToStorageAction::class)->execute($purchase, $this->pdfBase64($clinicalText));

        $metadata = LaboratoryResultEvent::query()
            ->pluck('metadata')
            ->map(fn ($metadata) => json_encode($metadata ?? []))
            ->implode("\n");

        $this->assertStringNotContainsString('Glucosa', $metadata);
        $this->assertStringNotContainsString('interpretacion de este estudio', strtolower($metadata));
    }

    #[Test]
    public function webhook_con_pdf_mantiene_email_y_active_campaign_existentes(): void
    {
        Bus::fake([TagLaboratoryEmailToActiveCampaignJob::class]);
        $dispatcher = $this->mock(ActiveCampaignOutboundDispatcher::class);
        $dispatcher->shouldReceive('enqueueLaboratoryResultsCompleted')->once();

        $purchase = $this->seedPurchaseWithItems();
        $notification = $this->seedResultsNotification($purchase);
        $payload = $this->resultsWebhookPayload($purchase, $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado.'));

        app(HandleResultsNotificationAction::class)->execute(
            $notification,
            $payload,
            [
                'purchase_id' => $purchase->id,
                'gda' => [
                    'gda_order_id' => $purchase->gda_order_id,
                    'gda_consecutivo' => $purchase->gda_consecutivo,
                ],
            ]
        );

        NotificationFacade::assertSentTo($purchase->customer->user, LaboratoryResultsAvailable::class);
        Bus::assertDispatched(TagLaboratoryEmailToActiveCampaignJob::class);

        $this->assertSame(LaboratoryNotification::STATUS_PROCESSED, $notification->fresh()->status);
        $this->assertNotNull($notification->fresh()->email_sent_at);
        $this->assertSame(1, LaboratoryResultStatus::query()->count());
        $this->assertSame(1, LaboratoryResultVersion::query()->count());
    }

    private function seedPurchaseWithItems(int $items = 1): LaboratoryPurchase
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

        foreach (range(1, $items) as $index) {
            LaboratoryPurchaseItem::query()->create([
                'laboratory_purchase_id' => $purchase->id,
                'gda_id' => 'EST-'.$index,
                'name' => 'Estudio '.$index,
                'price_cents' => 10000,
            ]);
        }

        return $purchase->fresh(['customer.user', 'laboratoryPurchaseItems']);
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
            'payload' => [
                'header' => ['marca' => 5],
                'requisition' => ['convenio' => 99999, 'value' => 'REQ-1'],
                'id' => $purchase->gda_order_id,
            ],
        ]);
    }

    private function resultsWebhookPayload(LaboratoryPurchase $purchase, string $pdfBase64): array
    {
        return [
            'resourceType' => 'ServiceRequest',
            'id' => $purchase->gda_order_id,
            'status' => 'completed',
            'header' => [
                'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
                'marca' => 5,
            ],
            'requisition' => [
                'value' => 'REQ-1',
                'convenio' => 99999,
            ],
            'code' => [
                'coding' => [[
                    'code' => 'EST-1',
                    'display' => 'Estudio 1',
                    'infogda_orden' => (string) $purchase->gda_consecutivo,
                ]],
            ],
            'GDA_menssage' => [
                'acuse' => 'acuse-'.uniqid(),
                'codeHttp' => 200,
                'mensaje' => 'OK',
                'descripcion' => 'Resultados disponibles',
            ],
            'infogda_resultado_b64' => $pdfBase64,
        ];
    }

    private function pdfBase64(string $text): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body>'.e($text).'</body></html>');
        $dompdf->render();

        return base64_encode($dompdf->output());
    }
}
