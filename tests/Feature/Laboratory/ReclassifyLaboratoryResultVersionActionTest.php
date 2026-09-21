<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\ReclassifyLaboratoryResultVersionAction;
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
use App\Services\LaboratoryResults\LaboratoryResultPdfClassifier;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReclassifyLaboratoryResultVersionActionTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        Bus::fake([ExtractLaboratoryResultReportJob::class]);
        Config::set('laboratory-results.structured_extraction.enabled', false);
        Config::set('laboratory-results.reclassification.allowed_environments', ['local', 'testing']);

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
    public function reclasifica_unknown_a_complete_con_pdf_swisslab_gda(): void
    {
        $version = $this->seedVersion(
            itemName: 'PERFIL BIOQUIMICO 24',
            pdfText: $this->swissLabGdaLaboratoryResultsText(),
            classification: LaboratoryResultPdfClassification::Unknown,
            classificationReason: 'unknown_document',
        );

        $result = app(ReclassifyLaboratoryResultVersionAction::class)->execute($version->id);

        $this->assertTrue($result->changed);
        $this->assertSame(LaboratoryResultPdfClassification::Complete, $result->version->classification);
        $this->assertSame(ResultStatusEnum::Complete, $result->version->resultStatus->status);
    }

    #[Test]
    public function registra_metadata_de_clasificacion_correcta(): void
    {
        $version = $this->seedVersion(
            itemName: 'PERFIL BIOQUIMICO 24',
            pdfText: $this->swissLabGdaLaboratoryResultsText(),
        );

        app(ReclassifyLaboratoryResultVersionAction::class)->execute($version->id);

        $version->refresh();

        $this->assertSame(LaboratoryResultPdfClassification::Complete, $version->classification);
        $this->assertSame('gda_laboratory_results_table_v1', $version->classification_reason);
        $this->assertSame('gda_laboratory_results_table_v1', $version->matched_rule);
        $this->assertSame(LaboratoryResultPdfClassifier::CLASSIFIER_NAME, $version->classifier);

        $event = LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::Reclassified->value)
            ->where('laboratory_result_version_id', $version->id)
            ->firstOrFail();

        $this->assertSame('unknown', $event->metadata['from_classification'] ?? null);
        $this->assertSame('complete', $event->metadata['to_classification'] ?? null);
        $this->assertSame('gda_laboratory_results_table_v1', $event->metadata['to_reason'] ?? null);
    }

    #[Test]
    public function conserva_pending_interpretation_en_reclasificacion(): void
    {
        $version = $this->seedVersion(
            itemName: 'PERFIL BIOQUIMICO 24',
            pdfText: 'La interpretacion de este estudio aun no se ha realizado.',
        );

        app(ReclassifyLaboratoryResultVersionAction::class)->execute($version->id);

        $version->refresh();

        $this->assertSame(LaboratoryResultPdfClassification::PendingInterpretation, $version->classification);
        $this->assertSame(ResultStatusEnum::PendingInterpretation, $version->resultStatus->status);
    }

    #[Test]
    public function es_idempotente_al_reclasificar_dos_veces(): void
    {
        $version = $this->seedVersion(
            itemName: 'PERFIL BIOQUIMICO 24',
            pdfText: $this->swissLabGdaLaboratoryResultsText(),
        );

        $action = app(ReclassifyLaboratoryResultVersionAction::class);
        $first = $action->execute($version->id);
        $second = $action->execute($version->id);

        $this->assertTrue($first->changed);
        $this->assertFalse($second->changed);
        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::Reclassified->value)
            ->where('laboratory_result_version_id', $version->id)
            ->count());
    }

    #[Test]
    public function no_despacha_extraction_cuando_flag_esta_apagado(): void
    {
        Config::set('laboratory-results.structured_extraction.enabled', false);

        $version = $this->seedVersion(
            itemName: 'PERFIL BIOQUIMICO 24',
            pdfText: $this->swissLabGdaLaboratoryResultsText(),
        );

        app(ReclassifyLaboratoryResultVersionAction::class)->execute($version->id);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function despacha_extraction_solo_para_item_elegible_cuando_flag_esta_encendido(): void
    {
        Config::set('laboratory-results.structured_extraction.enabled', true);

        $bioVersion = $this->seedVersion(
            itemName: 'PERFIL BIOQUIMICO 24',
            itemGdaId: 'BIO-24',
            pdfText: $this->mixedSwissLabGdaDocumentText(),
        );

        $bioVersion->load('resultStatus.laboratoryPurchase');

        $ecoVersion = $this->seedVersion(
            itemName: 'ECO ABDOMEN SUPERIOR',
            itemGdaId: 'ECO-ABD',
            pdfText: $this->mixedSwissLabGdaDocumentText(),
            purchase: $bioVersion->resultStatus->laboratoryPurchase,
        );

        $action = app(ReclassifyLaboratoryResultVersionAction::class);
        $bioResult = $action->execute($bioVersion->id);
        $ecoResult = $action->execute($ecoVersion->id);

        $this->assertTrue($bioResult->extractionDispatched);
        $this->assertFalse($ecoResult->extractionDispatched);
        $this->assertTrue($ecoResult->extractionSuppressed);

        Bus::assertDispatched(ExtractLaboratoryResultReportJob::class, 1);
        Bus::assertDispatched(
            ExtractLaboratoryResultReportJob::class,
            fn (ExtractLaboratoryResultReportJob $job): bool => $job->laboratoryResultVersionId === $bioVersion->id
        );
    }

    #[Test]
    public function pdf_mixto_reclasifica_ambos_items_pero_solo_bio_dispara_extraction(): void
    {
        Config::set('laboratory-results.structured_extraction.enabled', true);

        $purchase = $this->seedPurchaseShell();

        $bioItem = LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'BIO-24',
            'name' => 'PERFIL BIOQUIMICO 24',
            'price_cents' => 10000,
        ]);

        $ecoItem = LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'ECO-ABD',
            'name' => 'ECO ABDOMEN SUPERIOR',
            'price_cents' => 10000,
        ]);

        $pdfText = $this->mixedSwissLabGdaDocumentText();
        $path = 'results/gda-2009-test.pdf';
        Storage::put($path, $this->pdfBinary($pdfText));
        $sha256 = hash('sha256', Storage::get($path));

        $bioVersion = $this->seedVersionForItem($purchase, $bioItem, $path, $sha256);
        $ecoVersion = $this->seedVersionForItem($purchase, $ecoItem, $path, $sha256);

        $action = app(ReclassifyLaboratoryResultVersionAction::class);
        $action->execute($bioVersion->id);
        $action->execute($ecoVersion->id);

        $bioVersion->refresh();
        $ecoVersion->refresh();

        $this->assertSame(LaboratoryResultPdfClassification::Complete, $bioVersion->classification);
        $this->assertSame(LaboratoryResultPdfClassification::Complete, $ecoVersion->classification);

        Bus::assertDispatched(ExtractLaboratoryResultReportJob::class, 1);

        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::ExtractionSuppressed->value)
            ->where('laboratory_result_version_id', $ecoVersion->id)
            ->count());
    }

    private function seedVersion(
        string $itemName,
        string $pdfText,
        ?string $itemGdaId = null,
        LaboratoryResultPdfClassification $classification = LaboratoryResultPdfClassification::Unknown,
        string $classificationReason = 'unknown_document',
        ?LaboratoryPurchase $purchase = null,
    ): LaboratoryResultVersion {
        $purchase ??= $this->seedPurchaseShell();

        $item = LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => $itemGdaId ?? 'ITEM-'.uniqid(),
            'name' => $itemName,
            'price_cents' => 10000,
        ]);

        $path = 'results/gda-test-'.uniqid().'.pdf';
        Storage::put($path, $this->pdfBinary($pdfText));

        return $this->seedVersionForItem(
            $purchase,
            $item,
            $path,
            hash('sha256', Storage::get($path)),
            $classification,
            $classificationReason,
        );
    }

    private function seedVersionForItem(
        LaboratoryPurchase $purchase,
        LaboratoryPurchaseItem $item,
        string $path,
        string $sha256,
        LaboratoryResultPdfClassification $classification = LaboratoryResultPdfClassification::Unknown,
        string $classificationReason = 'unknown_document',
    ): LaboratoryResultVersion {
        $status = LaboratoryResultStatus::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_purchase_item_id' => $item->id,
            'status' => ResultStatusEnum::ManualReview,
        ]);

        return LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $status->id,
            'storage_path' => $path,
            'sha256' => $sha256,
            'source' => 'gda',
            'classification' => $classification,
            'classification_reason' => $classificationReason,
            'matched_rule' => null,
            'classifier' => LaboratoryResultPdfClassifier::CLASSIFIER_NAME,
            'classified_at' => now()->subDay(),
        ]);
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

    private function pdfBinary(string $text): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body>'.e($text).'</body></html>');
        $dompdf->render();

        return $dompdf->output();
    }
}
