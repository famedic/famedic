<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsPdfFreshnessIsolatedTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();

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
    public function sin_pdf_con_notificacion_de_resultados_es_stale(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotification($purchase, [
            'results_received_at' => now(),
        ]);

        $assessment = GdaResultsPdfStatus::assess($purchase->fresh(), collect([$notification]));

        $this->assertFalse($assessment->hasPdfInStorage);
        $this->assertTrue($assessment->availableAtGda);
        $this->assertTrue($assessment->isStale);
        $this->assertTrue($assessment->hasNewerResults);
        $this->assertFalse($assessment->isAutomaticOverwriteCandidate);
        $this->assertSame('gda_available', $assessment->freshnessStatus);
        $this->assertSame(GdaResultsPdfStatus::PDF_KIND_NONE, $assessment->pdfKind);
    }

    #[Test]
    public function pdf_gda_almacenado_despues_de_la_ultima_notificacion_no_es_stale(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:00:00'));
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotification($purchase, [
            'results_received_at' => now(),
        ]);

        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        $path = sprintf(GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN, $purchase->id, 'currentpdf12');
        $this->storePdfAt($path, now());
        $purchase->update(['results' => $path]);

        $assessment = GdaResultsPdfStatus::assess(
            $purchase->fresh(),
            collect([$notification->fresh()])
        );

        $this->assertTrue($assessment->hasPdfInStorage);
        $this->assertTrue($assessment->isGdaManaged);
        $this->assertFalse($assessment->isStale);
        $this->assertFalse($assessment->hasNewerResults);
        $this->assertFalse($assessment->isAutomaticOverwriteCandidate);
        $this->assertSame('gda_current', $assessment->freshnessStatus);
        $this->assertSame(GdaResultsPdfStatus::STORED_AT_SOURCE_LAST_MODIFIED, $assessment->storedPdfAtSource);
    }

    #[Test]
    public function pdf_gda_almacenado_antes_de_una_nueva_notificacion_es_stale(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $path = sprintf(GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN, $purchase->id, 'oldpdfstored');
        $this->storePdfAt($path, now());
        $purchase->update(['results' => $path]);
        $this->seedResultsNotification($purchase, [
            'results_received_at' => now(),
            'gda_message' => [
                'results_source' => 'storage',
                'results_fetched_at' => now()->toISOString(),
            ],
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $this->seedResultsNotification($purchase, [
            'results_received_at' => now(),
        ]);

        $assessment = GdaResultsPdfStatus::assess(
            $purchase->fresh(),
            LaboratoryNotification::query()->where('laboratory_purchase_id', $purchase->id)->get()
        );

        $this->assertTrue($assessment->hasPdfInStorage);
        $this->assertTrue($assessment->availableAtGda);
        $this->assertTrue($assessment->latestResultsAt->gt($assessment->storedPdfAt));
        $this->assertTrue($assessment->isStale);
        $this->assertTrue($assessment->hasNewerResults);
        $this->assertTrue($assessment->isAutomaticOverwriteCandidate);
        $this->assertSame('gda_stale', $assessment->freshnessStatus);
        $this->assertNotNull($assessment->staleLagLabel);
    }

    #[Test]
    public function pdf_manual_no_es_candidato_automatico_de_sobrescritura(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase(['results' => 'results/manual-admin.pdf']);
        $this->storePdfAt('results/manual-admin.pdf', now());

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $this->seedResultsNotification($purchase, [
            'results_received_at' => now(),
        ]);

        $assessment = GdaResultsPdfStatus::assess(
            $purchase->fresh(),
            LaboratoryNotification::query()->where('laboratory_purchase_id', $purchase->id)->get()
        );

        $this->assertTrue($assessment->hasPdfInStorage);
        $this->assertTrue($assessment->isManual);
        $this->assertFalse($assessment->isGdaManaged);
        $this->assertTrue($assessment->hasNewerResults);
        $this->assertFalse($assessment->isStale);
        $this->assertFalse($assessment->isAutomaticOverwriteCandidate);
        $this->assertSame('manual', $assessment->freshnessStatus);
        $this->assertSame(GdaResultsPdfStatus::PDF_KIND_MANUAL, $assessment->pdfKind);
    }

    #[Test]
    public function compara_contra_la_notificacion_mas_reciente(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:00:00'));
        $purchase = $this->seedPurchase();
        $this->seedResultsNotification($purchase, [
            'results_received_at' => now(),
        ]);

        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        $path = sprintf(GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN, $purchase->id, 'midversion12');
        $this->storePdfAt($path, now());
        $purchase->update(['results' => $path]);

        $assessmentAfterFirst = GdaResultsPdfStatus::assess(
            $purchase->fresh(),
            LaboratoryNotification::query()->where('laboratory_purchase_id', $purchase->id)->get()
        );
        $this->assertFalse($assessmentAfterFirst->isStale);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $latest = $this->seedResultsNotification($purchase, [
            'results_received_at' => now(),
        ]);

        $assessment = GdaResultsPdfStatus::assess(
            $purchase->fresh(),
            LaboratoryNotification::query()->where('laboratory_purchase_id', $purchase->id)->orderBy('id')->get()
        );

        $this->assertTrue($assessment->isStale);
        $this->assertTrue($assessment->latestResultsAt->equalTo($latest->results_received_at));
        $this->assertTrue($assessment->latestResultsAt->gt($assessment->storedPdfAt));
    }

    #[Test]
    public function identifica_rutas_gda_versus_manuales(): void
    {
        $this->assertTrue(GdaResultsPdfStatus::isGdaManagedPath('results/gda-2309-64842a86baf7.pdf'));
        $this->assertFalse(GdaResultsPdfStatus::isManualPath('results/gda-2309-64842a86baf7.pdf'));
        $this->assertTrue(GdaResultsPdfStatus::isManualPath('results/manual-admin.pdf'));
        $this->assertFalse(GdaResultsPdfStatus::isGdaManagedPath('results/manual-admin.pdf'));
        $this->assertFalse(GdaResultsPdfStatus::isGdaManagedPath(null));
        $this->assertFalse(GdaResultsPdfStatus::isManualPath(null));
    }

    private function seedResultsNotification(LaboratoryPurchase $purchase, array $overrides = []): LaboratoryNotification
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

    private function storePdfAt(string $path, Carbon $at): void
    {
        Storage::put($path, $this->samplePdfBinary());
        touch(Storage::path($path), $at->timestamp);
    }

    private function samplePdfBinary(): string
    {
        return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
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
