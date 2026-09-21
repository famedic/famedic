<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\StoreGdaResultsPdfToStorageAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultPdfClassification;
use App\Enums\LaboratoryResultStatus as ResultStatusEnum;
use App\Jobs\ExtractLaboratoryResultReportJob;
use App\Models\Customer;
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

class LaboratoryResultExtractionEligibilityDispatchTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        Bus::fake([ExtractLaboratoryResultReportJob::class]);
        Config::set('laboratory-results.structured_extraction.enabled', true);

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
    public function pdf_mixto_solo_despacha_extraction_para_item_de_laboratorio(): void
    {
        $purchase = $this->seedMixedPurchase();

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64($this->mixedSwissLabGdaDocumentText()),
        );

        $bioItem = $purchase->laboratoryPurchaseItems->firstWhere('name', 'PERFIL BIOQUIMICO 24');
        $ecoItem = $purchase->laboratoryPurchaseItems->firstWhere('name', 'ECO ABDOMEN SUPERIOR');

        $bioVersion = LaboratoryResultVersion::query()
            ->whereHas('resultStatus', fn ($query) => $query->where('laboratory_purchase_item_id', $bioItem->id))
            ->firstOrFail();

        $ecoVersion = LaboratoryResultVersion::query()
            ->whereHas('resultStatus', fn ($query) => $query->where('laboratory_purchase_item_id', $ecoItem->id))
            ->firstOrFail();

        $this->assertSame(LaboratoryResultPdfClassification::Complete, $bioVersion->classification);
        $this->assertSame(LaboratoryResultPdfClassification::Complete, $ecoVersion->classification);

        Bus::assertDispatched(ExtractLaboratoryResultReportJob::class, 1);
        Bus::assertDispatched(
            ExtractLaboratoryResultReportJob::class,
            fn (ExtractLaboratoryResultReportJob $job): bool => $job->laboratoryResultVersionId === $bioVersion->id
        );

        $dispatchedVersionIds = collect(Bus::dispatched(ExtractLaboratoryResultReportJob::class))
            ->map(function (mixed $dispatch): int {
                $job = $dispatch instanceof ExtractLaboratoryResultReportJob
                    ? $dispatch
                    : $dispatch[0];

                return $job->laboratoryResultVersionId;
            })
            ->all();

        $this->assertNotContains($ecoVersion->id, $dispatchedVersionIds);

        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::ExtractionSuppressed->value)
            ->where('laboratory_result_version_id', $ecoVersion->id)
            ->count());

        $suppressed = LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::ExtractionSuppressed->value)
            ->where('laboratory_result_version_id', $ecoVersion->id)
            ->firstOrFail();

        $this->assertSame('imaging_study_name', $suppressed->metadata['reason'] ?? null);
        $this->assertSame($ecoItem->id, $suppressed->metadata['purchase_item_id'] ?? null);
    }

    #[Test]
    public function clasificacion_unknown_no_despacha_extraction(): void
    {
        Config::set('laboratory-results.structured_extraction.enabled', true);
        $purchase = $this->seedMixedPurchase();

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64('Resultado disponible para consulta medica.'),
        );

        Bus::assertNothingDispatched();
        $this->assertSame(
            ResultStatusEnum::ManualReview,
            LaboratoryResultStatus::query()->first()->status
        );
    }

    #[Test]
    public function clasificacion_pending_no_despacha_extraction(): void
    {
        $purchase = $this->seedMixedPurchase();

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64('La interpretacion de este estudio aun no se ha realizado.'),
        );

        Bus::assertNothingDispatched();
        $this->assertSame(
            ResultStatusEnum::PendingInterpretation,
            LaboratoryResultStatus::query()->first()->status
        );
    }

    #[Test]
    public function item_elegible_complete_despacha_extraction(): void
    {
        $purchase = $this->seedPurchaseWithSingleItem('PERFIL BIOQUIMICO 24', 'BIO-24');

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64($this->swissLabGdaLaboratoryResultsText()),
        );

        Bus::assertDispatched(ExtractLaboratoryResultReportJob::class, 1);
    }

    #[Test]
    public function item_no_elegible_complete_no_despacha_extraction(): void
    {
        $purchase = $this->seedPurchaseWithSingleItem('ECO ABDOMEN SUPERIOR', 'ECO-ABD');

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->pdfBase64($this->swissLabGdaLaboratoryResultsText()),
        );

        Bus::assertNothingDispatched();
        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::ExtractionSuppressed->value)
            ->count());
    }

    #[Test]
    public function reprocesar_mismo_pdf_no_duplica_despachos(): void
    {
        $purchase = $this->seedPurchaseWithSingleItem('PERFIL BIOQUIMICO 24', 'BIO-24');
        $pdfBase64 = $this->pdfBase64($this->swissLabGdaLaboratoryResultsText());

        app(StoreGdaResultsPdfToStorageAction::class)->execute($purchase, $pdfBase64);
        app(StoreGdaResultsPdfToStorageAction::class)->execute($purchase->fresh(), $pdfBase64);

        Bus::assertDispatched(ExtractLaboratoryResultReportJob::class, 1);
        $this->assertSame(1, LaboratoryResultVersion::query()->count());
    }

    private function seedMixedPurchase(): LaboratoryPurchase
    {
        $purchase = $this->seedPurchaseShell();

        LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'BIO-24',
            'name' => 'PERFIL BIOQUIMICO 24',
            'price_cents' => 10000,
        ]);

        LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'ECO-ABD',
            'name' => 'ECO ABDOMEN SUPERIOR',
            'price_cents' => 10000,
        ]);

        return $purchase->fresh('laboratoryPurchaseItems');
    }

    private function seedPurchaseWithSingleItem(string $name, string $gdaId): LaboratoryPurchase
    {
        $purchase = $this->seedPurchaseShell();

        LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => $gdaId,
            'name' => $name,
            'price_cents' => 10000,
        ]);

        return $purchase->fresh('laboratoryPurchaseItems');
    }

    private function seedPurchaseShell(): LaboratoryPurchase
    {
        $user = User::query()->create([
            'name' => 'Paciente Test',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
        ]);

        return LaboratoryPurchase::query()->create([
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
    }

    private function swissLabGdaLaboratoryResultsText(): string
    {
        return implode("\n", [
            'SwissLab S.A. de C.V.',
            'Solicitud: HD0L001354',
            'ESTUDIO RESULTADO UNIDADES VALORES DE REFERENCIA',
            '(A) PERFIL BIOQUIMICO 24 (ELEC,CALCIO,FOS,TGP)',
            '78(A) GLUCOSA mg/dL 60-100',
            '5.9(A) NITROGENO UREICO mg/dL 6-20',
            '12.6(A) UREA SERICA mg/dL 10-50',
            '0.6(A) CREATININA mg/dL 0.55-1.02',
            'Método: Química seca',
            'Muestra: SUERO',
        ]);
    }

    private function mixedSwissLabGdaDocumentText(): string
    {
        return implode("\n", [
            $this->swissLabGdaLaboratoryResultsText(),
            'REPORTE RADIOLOGICO',
            'US ABDOMINAL SUPERIOR',
            'ECOGRAFÍA DE ABDOMEN SUPERIOR',
            'IMPRESIÓN DIAGNÓSTICA',
            'Hígado de tamaño y ecogenicidad normal.',
        ]);
    }

    private function pdfBase64(string $text): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body>'.e($text).'</body></html>');
        $dompdf->render();

        return base64_encode($dompdf->output());
    }
}
