<?php

namespace Tests\Feature\Laboratory;

use App\Actions\CreateResultsAction;
use App\Actions\Laboratories\AttemptReleaseLaboratoryResultsNotificationAction;
use App\Actions\Laboratories\StoreGdaResultsPdfToStorageAction;
use App\Actions\Laboratory\HandleResultsNotificationAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultStatus as ResultStatusEnum;
use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Jobs\TagLaboratoryEmailToActiveCampaignJob;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultStatus;
use App\Models\LabOrderEventState;
use App\Models\User;
use App\Notifications\LaboratoryResultsAvailable;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultCompletionGatePhase3Test extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        NotificationFacade::fake();
        Bus::fake([TagLaboratoryEmailToActiveCampaignJob::class, SyncGdaResultPdfToStorageJob::class]);
        Config::set('services.gda.result_completion_gate.mode', 'off');

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
    public function flag_off_conserva_comportamiento_legacy(): void
    {
        [$purchase] = $this->seedPurchaseWithItems(2);
        $this->mockResultsCompletedAc(times: 1);

        $this->handleResultsWebhook($purchase, 'EST-1', 'acuse-1');
        $this->handleResultsWebhook($purchase, 'EST-2', 'acuse-2');

        NotificationFacade::assertSentTo($purchase->customer->user, LaboratoryResultsAvailable::class, 1);
        Bus::assertDispatched(TagLaboratoryEmailToActiveCampaignJob::class);
        $this->assertNotNull(LabOrderEventState::query()->firstOrFail()->results_email_sent_at);
    }

    #[Test]
    public function shadow_no_bloquea_pero_registra_diferencia_semantica(): void
    {
        Config::set('services.gda.result_completion_gate.mode', 'shadow');

        [$purchase, $items] = $this->seedPurchaseWithItems(2);
        $this->createResultStatus($purchase, $items[0], ResultStatusEnum::Complete);
        $this->createResultStatus($purchase, $items[1], ResultStatusEnum::PendingInterpretation);
        $this->mockResultsCompletedAc(times: 1);

        $this->handleResultsWebhook($purchase, 'EST-1', 'acuse-1');
        $this->handleResultsWebhook($purchase, 'EST-2', 'acuse-2');

        NotificationFacade::assertSentTo($purchase->customer->user, LaboratoryResultsAvailable::class, 1);
        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::CompletionGateEvaluated->value)
            ->count());
    }

    #[Test]
    public function enforced_con_todos_complete_envia_email_y_activecampaign_una_vez(): void
    {
        Config::set('services.gda.result_completion_gate.mode', 'enforced');

        [$purchase, $items] = $this->seedPurchaseWithItems(2);
        $this->createResultStatus($purchase, $items[0], ResultStatusEnum::Complete);
        $this->createResultStatus($purchase, $items[1], ResultStatusEnum::Complete);
        $this->mockResultsCompletedAc(times: 1);

        $this->handleResultsWebhook($purchase, 'EST-1', 'acuse-1');
        $this->handleResultsWebhook($purchase, 'EST-2', 'acuse-2');
        $this->handleResultsWebhook($purchase, 'EST-2', 'acuse-2');

        NotificationFacade::assertSentTo($purchase->customer->user, LaboratoryResultsAvailable::class, 1);
        Bus::assertDispatched(TagLaboratoryEmailToActiveCampaignJob::class, 1);
        $this->assertNotNull(LabOrderEventState::query()->firstOrFail()->results_email_sent_at);
    }

    #[Test]
    public function enforced_bloquea_si_un_estudio_sigue_pending(): void
    {
        Config::set('services.gda.result_completion_gate.mode', 'enforced');

        [$purchase, $items] = $this->seedPurchaseWithItems(2);
        $this->createResultStatus($purchase, $items[0], ResultStatusEnum::Complete);
        $this->createResultStatus($purchase, $items[1], ResultStatusEnum::PendingInterpretation);
        $this->mockResultsCompletedAc(times: 0);

        $this->handleResultsWebhook($purchase, 'EST-1', 'acuse-1');
        $this->handleResultsWebhook($purchase, 'EST-2', 'acuse-2');

        NotificationFacade::assertNothingSent();
        Bus::assertNotDispatched(TagLaboratoryEmailToActiveCampaignJob::class);
        $this->assertNull(LabOrderEventState::query()->firstOrFail()->results_email_sent_at);
        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::NotificationSuppressed->value)
            ->count());
    }

    #[Test]
    public function enforced_bloquea_manual_review_y_error(): void
    {
        foreach ([ResultStatusEnum::ManualReview, ResultStatusEnum::Error] as $status) {
            $this->tearDownIsolatedSchema();
            $this->bootstrapIsolatedSchema();
            NotificationFacade::fake();
            Bus::fake([TagLaboratoryEmailToActiveCampaignJob::class, SyncGdaResultPdfToStorageJob::class]);
            Config::set('services.gda.result_completion_gate.mode', 'enforced');

            [$purchase, $items] = $this->seedPurchaseWithItems(2);
            $this->createResultStatus($purchase, $items[0], ResultStatusEnum::Complete);
            $this->createResultStatus($purchase, $items[1], $status);
            $this->mockResultsCompletedAc(times: 0);

            $this->handleResultsWebhook($purchase, 'EST-1', 'acuse-1');
            $this->handleResultsWebhook($purchase, 'EST-2', 'acuse-2');

            NotificationFacade::assertNothingSent();
            $this->assertNull(LabOrderEventState::query()->firstOrFail()->results_email_sent_at);
        }
    }

    #[Test]
    public function legacy_order_sin_statuses_conserva_fallback_legacy_en_enforced(): void
    {
        Config::set('services.gda.result_completion_gate.mode', 'enforced');

        [$purchase] = $this->seedPurchaseWithItems(1);
        $this->mockResultsCompletedAc(times: 1);

        $this->handleResultsWebhook($purchase, 'EST-1', 'acuse-1');

        NotificationFacade::assertSentTo($purchase->customer->user, LaboratoryResultsAvailable::class, 1);
    }

    #[Test]
    public function ultimo_estudio_que_pasa_a_complete_libera_la_orden(): void
    {
        Config::set('services.gda.result_completion_gate.mode', 'enforced');

        [$purchase, $items] = $this->seedPurchaseWithItems(2);
        $this->createResultStatus($purchase, $items[0], ResultStatusEnum::Complete);
        $this->createResultStatus($purchase, $items[1], ResultStatusEnum::PendingInterpretation);
        $this->mockResultsCompletedAc(times: 1);

        $this->handleResultsWebhook($purchase, 'EST-1', 'acuse-1');
        $this->handleResultsWebhook($purchase, 'EST-2', 'acuse-2');
        NotificationFacade::assertNothingSent();

        $items[1]->laboratoryResultStatus->update(['status' => ResultStatusEnum::Complete]);
        app(AttemptReleaseLaboratoryResultsNotificationAction::class)->execute($purchase->fresh(), source: 'test_complete');
        app(AttemptReleaseLaboratoryResultsNotificationAction::class)->execute($purchase->fresh(), source: 'test_duplicate');

        NotificationFacade::assertSentTo($purchase->customer->user, LaboratoryResultsAvailable::class, 1);
        Bus::assertDispatched(TagLaboratoryEmailToActiveCampaignJob::class, 1);
        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::PatientNotificationRequested->value)
            ->count());
    }

    #[Test]
    public function pdf_complete_procesado_por_storage_dispara_release_si_era_ultimo_pendiente(): void
    {
        Config::set('services.gda.result_completion_gate.mode', 'enforced');

        [$purchase, $items] = $this->seedPurchaseWithItems(2);
        $this->createResultStatus($purchase, $items[0], ResultStatusEnum::Complete);
        $this->createResultStatus($purchase, $items[1], ResultStatusEnum::PendingInterpretation);
        $notification = $this->seedResultsNotification($purchase);
        $this->createReadyGateState($purchase);
        $this->mockResultsCompletedAc(times: 1);

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64('Resultado final. Interpretacion realizada.'),
            $notification
        );

        NotificationFacade::assertSentTo($purchase->customer->user, LaboratoryResultsAvailable::class, 1);
    }

    #[Test]
    public function upload_manual_legacy_sigue_notificando_explicito(): void
    {
        Config::set('services.gda.result_completion_gate.mode', 'enforced');

        [$purchase] = $this->seedPurchaseWithItems(1);

        app(CreateResultsAction::class)(
            $purchase,
            UploadedFile::fake()->createWithContent('manual.pdf', $this->pdfBinary('PDF manual'))
        );

        NotificationFacade::assertSentTo($purchase->customer->user, LaboratoryResultsAvailable::class, 1);
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

        $createdItems = collect(range(1, $items))->map(function ($index) use ($purchase) {
            return LaboratoryPurchaseItem::query()->create([
                'laboratory_purchase_id' => $purchase->id,
                'gda_id' => 'EST-'.$index,
                'name' => 'Estudio '.$index,
                'price_cents' => 10000,
            ]);
        });

        return [$purchase->fresh(['customer.user', 'laboratoryPurchaseItems']), $createdItems->all()];
    }

    private function createResultStatus(
        LaboratoryPurchase $purchase,
        LaboratoryPurchaseItem $item,
        ResultStatusEnum $status,
    ): LaboratoryResultStatus {
        return LaboratoryResultStatus::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_purchase_item_id' => $item->id,
            'status' => $status,
            'first_available_at' => now(),
            'last_checked_at' => now(),
            'interpreted_at' => $status === ResultStatusEnum::Complete ? now() : null,
        ]);
    }

    private function handleResultsWebhook(LaboratoryPurchase $purchase, string $code, string $acuse): void
    {
        $notification = LaboratoryNotification::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
            'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
            'gda_order_id' => $purchase->gda_order_id,
            'gda_consecutivo' => $purchase->gda_consecutivo,
            'status' => LaboratoryNotification::STATUS_RECEIVED,
            'gda_status' => LaboratoryNotification::GDA_STATUS_COMPLETED,
            'resource_type' => 'ServiceRequest',
            'payload' => [],
        ]);

        $payload = $this->resultsWebhookPayload($purchase, $code, $acuse);

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
    }

    private function resultsWebhookPayload(LaboratoryPurchase $purchase, string $code, string $acuse): array
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
                    'code' => $code,
                    'display' => $code,
                    'infogda_orden' => (string) $purchase->gda_consecutivo,
                ]],
            ],
            'GDA_menssage' => [
                'acuse' => $acuse,
                'codeHttp' => 200,
                'mensaje' => 'OK',
                'descripcion' => 'Resultados disponibles',
            ],
        ];
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

    private function createReadyGateState(LaboratoryPurchase $purchase): void
    {
        LabOrderEventState::query()->create([
            'gda_order_id' => $purchase->gda_order_id,
            'laboratory_purchase_id' => $purchase->id,
            'total_studies' => $purchase->laboratoryPurchaseItems()->count(),
            'results_received_count' => $purchase->laboratoryPurchaseItems()->count(),
            'first_event_at' => now(),
            'last_event_at' => now(),
        ]);
    }

    private function mockResultsCompletedAc(int $times): void
    {
        $mock = $this->mock(ActiveCampaignOutboundDispatcher::class);
        $expectation = $mock->shouldReceive('enqueueLaboratoryResultsCompleted');

        $times === 0
            ? $expectation->never()
            : $expectation->times($times);
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
