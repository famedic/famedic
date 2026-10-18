<?php

namespace Tests\Feature\Admin;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Http\Middleware\EnsureDocumentationIsAccepted;
use App\Models\Administrator;
use App\Models\AiExecution;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Models\User;
use App\Services\LaboratoryPreparation\Admin\LaboratoryPreparationMonitorService;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LaboratoryPreparationMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();

        $this->bootstrapSchema();
        $this->seedNavigationPermissions();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutMiddleware([
            EnsureDocumentationIsAccepted::class,
            EnsureEmailIsVerified::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function administrador_puede_ver_monitor_con_metricas_v3_y_pendientes(): void
    {
        $admin = $this->seedAdminUser(withAdministratorRole: true);

        $auto = $this->seedPurchase(['gda_order_id' => 'AUTO-R04']);
        $this->seedSummary($auto, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED,
            'rules_applied' => ['R01'],
            'ai_execution_id' => $this->seedExecution($auto, AiExecution::STATUS_SUCCEEDED)->id,
            'summary_text' => 'Ayuno de 8 horas.',
        ]);

        $clinical = $this->seedPurchase(['gda_order_id' => 'CLINICAL-R04']);
        $this->seedSummary($clinical, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'rules_applied' => ['R04'],
            'fallback_reason' => 'incompatible_urine_simple_protocols',
            'fallback_category' => LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT,
            'needs_provider_review' => true,
        ]);

        $sourceQuality = $this->seedPurchase(['gda_order_id' => 'SOURCE-QUALITY']);
        $this->seedSummary($sourceQuality, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'fallback_reason' => 'missing_preparation_source',
            'fallback_category' => LaboratoryPurchasePreparationSummary::FALLBACK_SOURCE_QUALITY,
        ]);

        $technical = $this->seedPurchase(['gda_order_id' => 'TECHNICAL']);
        $this->seedSummary($technical, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'fallback_reason' => 'openai_failed',
            'fallback_category' => LaboratoryPurchasePreparationSummary::FALLBACK_TECHNICAL_AI_FAILURE,
            'ai_execution_id' => $this->seedExecution($technical, AiExecution::STATUS_FAILED)->id,
        ]);

        $pending = $this->seedPurchase(['gda_order_id' => 'PENDING']);
        $this->seedExecution($pending, AiExecution::STATUS_QUEUED);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/LaboratoryPreparation/Monitor')
                ->where('metrics.0.value', 5)
                ->where('metrics.1.value', 1)
                ->where('metrics.2.value', 3)
                ->where('metrics.3.value', 1)
                ->where('metrics.4.value', 1)
                ->where('metrics.5.value', 1)
                ->where('metrics.6.value', 1)
                ->where('metrics.7.value', 1)
                ->where('metrics.8.value', 1)
                ->where('metrics.9.value', 1)
                ->where('metrics.10.value', 'N/D')
                ->has('purchases.data', 5)
                ->where('featureFlags.shadow_ui_available', false)
            );
    }

    #[Test]
    public function metricas_usan_ids_de_summary_y_el_mismo_universo_filtrado_que_la_tabla(): void
    {
        $admin = $this->seedAdminUser(withAdministratorRole: true);
        $todayInMexicoCity = '2026-01-15';
        $insideTodayFilter = \Carbon\Carbon::parse('2026-01-15 12:00:00', 'UTC');
        $outsideTodayFilter = \Carbon\Carbon::parse('2026-01-14 12:00:00', 'UTC');

        for ($i = 0; $i < 10; $i++) {
            $this->seedPurchase(['gda_order_id' => 'OUT-OF-UNIVERSE-'.$i]);
        }

        for ($i = 0; $i < 3; $i++) {
            $auto = $this->seedPurchase([
                'brand' => $i === 0 ? LaboratoryBrand::JENNER : LaboratoryBrand::OLAB,
                'gda_order_id' => 'AUTO-'.$i,
                'created_at' => $outsideTodayFilter,
            ]);

            $this->seedSummary($auto, [
                'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED,
                'rules_applied' => ['R01'],
                'ai_execution_id' => $this->seedExecution($auto, AiExecution::STATUS_SUCCEEDED)->id,
                'summary_text' => 'Ayuno '.$i,
            ]);
        }

        $clinical = $this->seedPurchase([
            'brand' => LaboratoryBrand::JENNER,
            'gda_order_id' => 'FALLBACK-CLINICAL',
            'created_at' => $insideTodayFilter,
        ]);
        $this->seedSummary($clinical, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'rules_applied' => ['R04'],
            'fallback_reason' => 'incompatible_urine_simple_protocols',
            'fallback_category' => LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT,
            'needs_provider_review' => true,
        ]);

        $sourceQuality = $this->seedPurchase([
            'gda_order_id' => 'FALLBACK-SOURCE',
            'created_at' => $insideTodayFilter,
        ]);
        $this->seedSummary($sourceQuality, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'rules_applied' => ['R01'],
            'fallback_reason' => 'uninterpreted_instruction_fragment',
            'fallback_category' => LaboratoryPurchasePreparationSummary::FALLBACK_SOURCE_QUALITY,
            'needs_provider_review' => true,
        ]);

        $technical = $this->seedPurchase([
            'gda_order_id' => 'FALLBACK-TECHNICAL',
            'created_at' => $insideTodayFilter,
        ]);
        $this->seedSummary($technical, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'rules_applied' => [],
            'fallback_reason' => 'openai_failed',
            'fallback_category' => LaboratoryPurchasePreparationSummary::FALLBACK_TECHNICAL_AI_FAILURE,
            'ai_execution_id' => $this->seedExecution($technical, AiExecution::STATUS_FAILED)->id,
        ]);

        $legacy = $this->seedPurchase([
            'gda_order_id' => 'LEGACY-SUMMARY',
            'created_at' => $insideTodayFilter,
        ]);
        $this->seedSummary($legacy, [
            'decision_status' => null,
            'rules_version' => 'legacy-v2',
            'rules_applied' => [],
            'summary_text' => 'Resumen legacy',
        ]);

        $pending = $this->seedPurchase([
            'gda_order_id' => 'PENDING-AI',
            'created_at' => $insideTodayFilter,
        ]);
        $this->seedExecution($pending, AiExecution::STATUS_QUEUED);

        $this->seedPurchase(['gda_order_id' => 'NO-SUMMARY-NO-AI']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.0.value', 8)
                ->where('metrics.1.value', 3)
                ->where('metrics.2.value', 3)
                ->where('metrics.3.value', 1)
                ->where('metrics.4.value', 1)
                ->where('metrics.5.value', 1)
                ->where('metrics.6.value', 1)
                ->where('metrics.7.value', 2)
                ->where('metrics.8.value', 3)
                ->where('metrics.9.value', 1)
                ->has('purchases.data', 8)
            );

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index', [
                'mode' => 'v3',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.0.value', 6)
                ->where('metrics.1.value', 3)
                ->where('metrics.2.value', 3)
                ->has('purchases.data', 6)
            );

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index', [
                'mode' => 'legacy',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.0.value', 1)
                ->where('metrics.1.value', 0)
                ->where('metrics.2.value', 0)
                ->where('purchases.data.0.id', $legacy->id)
            );

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index', [
                'mode' => 'pending',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.0.value', 1)
                ->where('metrics.6.value', 1)
                ->where('purchases.data.0.id', $pending->id)
            );

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index', [
                'brand' => LaboratoryBrand::JENNER->value,
                'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.0.value', 1)
                ->where('metrics.1.value', 1)
                ->where('metrics.2.value', 0)
                ->where('purchases.data.0.decision_status', LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED)
            );

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index', [
                'openai_status' => AiExecution::STATUS_FAILED,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.0.value', 1)
                ->where('metrics.5.value', 1)
                ->where('metrics.9.value', 1)
                ->where('purchases.data.0.id', $technical->id)
            );

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index', [
                'date_from' => $todayInMexicoCity,
                'date_to' => $todayInMexicoCity,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.0.value', 5)
                ->where('metrics.1.value', 0)
                ->where('metrics.2.value', 3)
                ->has('purchases.data', 5)
            );
    }

    #[Test]
    public function acceso_requiere_rol_administrador_exclusivo(): void
    {
        $adminWithoutRole = $this->seedAdminUser(withAdministratorRole: false);

        $this->actingAs($adminWithoutRole)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index'))
            ->assertForbidden();

        auth()->logout();
        $this->flushSession();

        $this->get(route('admin.laboratory-preparation.monitor.index'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function filtros_por_regla_estado_openai_modo_y_paginacion_funcionan(): void
    {
        $admin = $this->seedAdminUser(withAdministratorRole: true);

        for ($i = 0; $i < 26; $i++) {
            $purchase = $this->seedPurchase(['gda_order_id' => 'PAGE-'.$i]);
            $this->seedSummary($purchase, [
                'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED,
                'rules_applied' => ['R01'],
                'ai_execution_id' => $this->seedExecution($purchase, AiExecution::STATUS_SUCCEEDED)->id,
                'summary_text' => 'Texto consolidado '.$i,
            ]);
        }

        $match = $this->seedPurchase(['gda_order_id' => 'MATCH-R04']);
        $this->seedSummary($match, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'rules_applied' => ['R04'],
            'fallback_reason' => 'incompatible_urine_simple_protocols',
            'fallback_category' => LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT,
        ]);

        $pending = $this->seedPurchase(['gda_order_id' => 'MATCH-PENDING']);
        $this->seedExecution($pending, AiExecution::STATUS_QUEUED);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchases.data', 25)
                ->where('purchases.per_page', 25)
            );

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index', [
                'rule' => 'R04',
                'fallback_reason' => 'incompatible_urine_simple_protocols',
                'mode' => 'v3',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchases.data', 1)
                ->where('purchases.data.0.id', $match->id)
            );

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index', [
                'openai_status' => AiExecution::STATUS_QUEUED,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchases.data', 1)
                ->where('purchases.data.0.id', $pending->id)
                ->where('purchases.data.0.openai_status', AiExecution::STATUS_QUEUED)
            );
    }

    #[Test]
    public function detalle_muestra_indicaciones_sanitiza_secretos_y_explica_flag_apagado(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => false]);

        $admin = $this->seedAdminUser(withAdministratorRole: true);
        $purchase = $this->seedPurchase(['gda_order_id' => 'DETAIL-R04']);
        $execution = $this->seedExecution($purchase, AiExecution::STATUS_FAILED, [
            'error' => 'OpenAI rejected token sk-secret-monitor-1234567890',
        ]);

        $this->seedSummary($purchase, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'rules_applied' => ['R04'],
            'fallback_reason' => 'incompatible_urine_simple_protocols',
            'fallback_category' => LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT,
            'needs_provider_review' => true,
            'ai_execution_id' => $execution->id,
            'summary_json' => [
                'summary' => ['text' => 'No consolidado.'],
                'sections' => [],
                'special_instructions' => [],
                'individual_instructions' => [],
                'decision' => [
                    'consolidated_requirements' => [[
                        'study_id' => '101',
                        'sample' => 'orina',
                        'volume_ml' => 25,
                        'collection_time' => 'ultimo dia laboral',
                    ]],
                ],
                'prompt' => 'NO_DEBE_SALIR',
                'request_payload' => ['api_key' => 'sk-json-secret'],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.show', $purchase))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/LaboratoryPreparation/MonitorDetail')
                ->where('diagnostic.fallback_reason', 'incompatible_urine_simple_protocols')
                ->where('diagnostic.fallback_category', LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT)
                ->where('diagnostic.needs_provider_review', true)
                ->where('openAi.status', AiExecution::STATUS_FAILED)
                ->where('openAi.error', 'La ejecución no pudo completarse. Usa el ID de ejecución para una revisión técnica autorizada.')
                ->where('indications.original.0.study_id', '101')
                ->where('indications.consolidated_requirements.0.volume_ml', 25)
                ->where('indications.flag_notice', 'Existe decisión v3 persistida, pero el flag determinístico está apagado; el paciente ve las indicaciones originales.')
                ->missing('indications.summary_json.prompt')
                ->missing('indications.summary_json.request_payload')
            );

        $response
            ->assertDontSee('sk-secret-monitor-1234567890')
            ->assertDontSee('sk-json-secret')
            ->assertDontSee('NO_DEBE_SALIR');
    }

    #[Test]
    public function monitor_no_invoca_openai_real(): void
    {
        Http::preventStrayRequests();

        $this->app->bind(OpenAiClient::class, function () {
            throw new \RuntimeException('OpenAI no debe resolverse desde el monitor.');
        });

        $admin = $this->seedAdminUser(withAdministratorRole: true);
        $purchase = $this->seedPurchase(['gda_order_id' => 'NO-OPENAI']);
        $this->seedSummary($purchase, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'rules_applied' => ['R04'],
            'fallback_reason' => 'incompatible_urine_simple_protocols',
            'fallback_category' => LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.laboratory-preparation.monitor.index'))
            ->assertOk();
    }

    #[Test]
    public function ejecucion_relevante_es_consistente_y_no_arrastra_fallos_historicos(): void
    {
        $admin = $this->seedAdminUser(true);
        $withoutSummary = $this->seedPurchase();
        $this->seedExecution($withoutSummary, AiExecution::STATUS_FAILED);
        $latest = $this->seedExecution($withoutSummary, AiExecution::STATUS_SUCCEEDED);
        $this->seedExecution($withoutSummary, AiExecution::STATUS_FAILED, ['feature' => 'unrelated_result_feature']);

        $linked = $this->seedPurchase();
        $linkedExecution = $this->seedExecution($linked, AiExecution::STATUS_SUCCEEDED);
        $this->seedSummary($linked, ['ai_execution_id' => $linkedExecution->id]);
        $this->seedExecution($linked, AiExecution::STATUS_FAILED);

        $fallback = $this->seedPurchase();
        $this->seedExecution($fallback, AiExecution::STATUS_FAILED);
        $this->seedSummary($fallback);

        $service = app(LaboratoryPreparationMonitorService::class);
        $all = $service->indexPayload(Request::create('/'));
        $metrics = collect($all['metrics'])->keyBy('id');
        $rows = collect($all['purchases']->items())->keyBy('id');
        $this->assertSame(3, $metrics['total']['value']);
        $this->assertSame(0, $metrics['technical']['value']);
        $this->assertSame(0, $metrics['pending']['value']);
        $this->assertSame(2, $metrics['openai_ok']['value']);
        $this->assertSame('succeeded_without_summary', $rows[$withoutSummary->id]['technical_status']);
        $this->assertSame('not_run', $rows[$fallback->id]['openai_status']);
        $this->assertSame('succeeded', $rows[$linked->id]['openai_status']);
        $failed = $service->indexPayload(Request::create('/', 'GET', ['openai_status' => 'failed']));
        $this->assertSame(0, $failed['purchases']->total());
        $notRun = $service->indexPayload(Request::create('/', 'GET', ['openai_status' => 'not_run']));
        $this->assertSame($fallback->id, $notRun['purchases']->items()[0]['id']);
        $this->assertSame($latest->id, $service->detailPayload($withoutSummary)['openAi']['ai_execution_id']);
    }

    #[Test]
    public function fallo_sin_resumen_no_es_pendiente_y_cola_no_prueba_llamada(): void
    {
        $failed = $this->seedPurchase();
        $this->seedExecution($failed, AiExecution::STATUS_FAILED, ['feature' => 'laboratory_preparation_redactor']);
        $queued = $this->seedPurchase();
        $this->seedExecution($queued, AiExecution::STATUS_QUEUED);
        $processing = $this->seedPurchase();
        $this->seedExecution($processing, AiExecution::STATUS_PROCESSING);
        $service = app(LaboratoryPreparationMonitorService::class);
        $payload = $service->indexPayload(Request::create('/'));
        $metrics = collect($payload['metrics'])->keyBy('id');
        $rows = collect($payload['purchases']->items())->keyBy('id');
        $this->assertSame(1, $metrics['technical']['value']);
        $this->assertSame(1, $metrics['openai_failed']['value']);
        $this->assertSame(2, $metrics['pending']['value']);
        $this->assertSame('failed', $rows[$failed->id]['technical_status']);
        $this->assertSame('v3', $rows[$failed->id]['mode']);
        $this->assertFalse($service->detailPayload($queued)['openAi']['called']);
        $this->assertNull($service->detailPayload($processing)['openAi']['called']);
        $this->assertNull($service->detailPayload($failed)['openAi']['called']);
        $filtered = $service->indexPayload(Request::create('/', 'GET', ['technical_status' => 'failed']));
        $this->assertSame(1, $filtered['purchases']->total());
        $this->assertSame($failed->id, $filtered['purchases']->items()[0]['id']);
    }

    #[Test]
    public function consulta_del_listado_no_crece_con_el_numero_de_compras(): void
    {
        $first = $this->seedPurchase();
        $this->seedExecution($first, AiExecution::STATUS_QUEUED);
        $service = app(LaboratoryPreparationMonitorService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->indexPayload(Request::create('/'));
        $one = count(DB::getQueryLog());
        for ($i = 0; $i < 24; $i++) {
            $purchase = $this->seedPurchase();
            $this->seedExecution($purchase, AiExecution::STATUS_FAILED);
        }
        DB::flushQueryLog();
        $payload = $service->indexPayload(Request::create('/'));
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(25, $payload['purchases']->items());
        $this->assertSame($one, $many, 'Las consultas no deben crecer por compra.');
        $this->assertLessThanOrEqual(5, $many);
    }

    #[Test]
    public function compras_eliminadas_tienen_detalle_autorizado_y_retorno_local(): void
    {
        $admin = $this->seedAdminUser(true);
        $purchase = $this->seedPurchase();
        $this->seedSummary($purchase);
        $purchase->delete();
        $response = $this->actingAs($admin)->get(route('admin.laboratory-preparation.monitor.index', ['search' => $purchase->gda_order_id, 'page' => 1]))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('purchases.data.0.id', $purchase->id)
            ->where('purchases.data.0.admin_purchase_url', null));
        $this->get(route('admin.laboratory-preparation.monitor.show', ['laboratory_purchase' => $purchase->id, 'return' => ['search' => $purchase->gda_order_id, 'page' => 2, 'redirect' => 'https://example.test']]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('purchase.admin_url', null)
            ->where('back_url', route('admin.laboratory-preparation.monitor.index', ['search' => $purchase->gda_order_id, 'page' => 2], false)));
        $this->actingAs($this->seedAdminUser(false))->get(route('admin.laboratory-preparation.monitor.show', $purchase->id))->assertForbidden();
    }

    #[Test]
    public function historial_es_acotado_y_no_expone_payloads_ni_errores_sensibles(): void
    {
        $purchase = $this->seedPurchase();
        for ($i = 0; $i < 22; $i++) {
            $this->seedExecution($purchase, AiExecution::STATUS_FAILED, [
                'error' => 'AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML Paciente Privado patient@example.test Authorization: Bearer secret',
                'request_payload_redacted' => ['prompt' => 'PRIVATE_PROMPT'],
            ]);
        }
        $this->seedSummary($purchase, ['summary_json' => [
            'summary' => 'Seguro',
            'decision' => ['consolidated_requirements' => [['volume_ml' => 25, 'authorization' => 'Bearer secret', 'nested' => ['api_key' => 'secret']]]],
            'redaction' => ['patient_text' => 'Seguro', 'prompt' => 'PRIVATE_PROMPT'],
            'unexpected' => 'PRIVATE_UNKNOWN',
        ]]);
        $payload = app(LaboratoryPreparationMonitorService::class)->detailPayload($purchase);
        $this->assertCount(20, $payload['history']['executions']);
        $this->assertTrue($payload['history']['has_more']);
        $json = json_encode($payload);
        foreach (['AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML', 'patient@example.test', 'PRIVATE_PROMPT', 'PRIVATE_UNKNOWN', 'Bearer secret', 'api_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame(25, $payload['indications']['consolidated_requirements'][0]['volume_ml']);
    }

    #[Test]
    public function filtros_invalidos_son_rechazados_y_fecha_final_sola_es_valida(): void
    {
        $admin = $this->seedAdminUser(true);
        $this->actingAs($admin)->getJson(route('admin.laboratory-preparation.monitor.index', ['date_from' => 'not-a-date']))
            ->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson(route('admin.laboratory-preparation.monitor.index', ['search' => ['unexpected']]))
            ->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->get(route('admin.laboratory-preparation.monitor.index', ['date_to' => '2026-10-16']))->assertOk();
    }

    #[Test]
    public function detalle_muestra_auto_vigente_y_originales_tras_apagar_v3(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->seedPurchase();
        $source = app(\App\Services\LaboratoryPreparation\LaboratoryPreparationSource::class);
        $execution = $this->seedExecution($purchase, AiExecution::STATUS_SUCCEEDED, ['feature' => 'laboratory_preparation_redactor']);
        $this->seedSummary($purchase, [
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED,
            'source_hash' => $source->deterministicHash($purchase),
            'ai_execution_id' => $execution->id,
            'summary_text' => 'Indicaciones consolidadas vigentes.',
            'summary_json' => ['summary' => 'Indicaciones consolidadas vigentes.', 'sections' => [['title' => 'Preparación', 'content' => 'Indicaciones consolidadas vigentes.']], 'special_instructions' => [], 'individual_instructions' => []],
        ]);
        $service = app(LaboratoryPreparationMonitorService::class);
        $auto = $service->detailPayload($purchase->fresh());
        $this->assertTrue($auto['indications']['patient_presenter']['has_ai_summary']);
        $this->assertSame('AI_READY', $auto['indications']['patient_presenter']['ai_status']);
        config(['services.laboratory_preparation.deterministic_v3_enabled' => false]);
        $original = $service->detailPayload($purchase->fresh());
        $this->assertFalse($original['indications']['patient_presenter']['has_ai_summary']);
        $this->assertSame('Muestra de 25 mL, último día de la semana laboral.', $original['indications']['patient_presenter']['studies'][0]['instructions']);
        $this->assertSame('AUTO_CONSOLIDATED', $original['diagnostic']['decision_status']);
    }

    #[Test]
    public function ejecucion_de_otra_compra_no_se_expone_como_relevante(): void
    {
        $purchase = $this->seedPurchase();
        $other = $this->seedPurchase();
        $execution = $this->seedExecution($other, AiExecution::STATUS_FAILED, ['error' => 'Dato privado de otra compra']);
        $this->seedSummary($purchase, ['ai_execution_id' => $execution->id]);
        $service = app(LaboratoryPreparationMonitorService::class);
        $rows = collect($service->indexPayload(Request::create('/'))['purchases']->items())->keyBy('id');
        $this->assertSame('not_run', $rows[$purchase->id]['openai_status']);
        $detail = $service->detailPayload($purchase->fresh());
        $this->assertNull($detail['openAi']['ai_execution_id']);
        $this->assertSame('not_run', $detail['openAi']['status']);
        $this->assertCount(0, $detail['history']['executions']);
    }

    private function seedAdminUser(bool $withAdministratorRole): User
    {
        $user = User::query()->create([
            'name' => 'Admin Monitor',
            'paternal_lastname' => 'Indicaciones',
            'maternal_lastname' => 'IA',
            'email' => 'admin-monitor-'.uniqid().'@test.local',
            'email_verified_at' => now(),
            'password' => bcrypt('secret'),
            'documentation_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $administrator = Administrator::query()->create([
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withAdministratorRole) {
            $administrator->assignRole(Role::findByName('Administrador', 'web'));
        }

        return $user->fresh('administrator.roles');
    }

    private function seedPurchase(array $attributes = []): LaboratoryPurchase
    {
        $patient = User::query()->create([
            'name' => 'Paciente',
            'paternal_lastname' => 'Monitor',
            'maternal_lastname' => 'IA',
            'email' => 'patient-monitor-'.uniqid().'@test.local',
            'email_verified_at' => now(),
            'password' => bcrypt('secret'),
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $patient->id,
            'medical_attention_identifier' => random_int(100000, 999999),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchase = LaboratoryPurchase::query()->create(array_merge([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-'.uniqid(),
            'gda_consecutivo' => random_int(1000, 9999),
            'name' => 'Paciente',
            'paternal_lastname' => 'Monitor',
            'maternal_lastname' => 'IA',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        DB::table('laboratory_purchase_items')->insert([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => '101',
            'name' => 'Ácido metilhipúrico (xileno)',
            'indications' => 'Muestra de 25 mL, último día de la semana laboral.',
            'feature_list' => json_encode([]),
            'price_cents' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $purchase->fresh('laboratoryPurchaseItems');
    }

    private function seedExecution(LaboratoryPurchase $purchase, string $status, array $attributes = []): AiExecution
    {
        return AiExecution::query()->create(array_merge([
            'domain' => 'laboratory',
            'feature' => 'laboratory_preparation_summary',
            'subject_type' => $purchase->getMorphClass(),
            'subject_id' => $purchase->id,
            'prompt_version' => 3,
            'model' => 'gpt-test',
            'status' => $status,
            'input_hash' => hash('sha256', 'execution-'.$purchase->id),
            'duration_ms' => 1200,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function seedSummary(LaboratoryPurchase $purchase, array $attributes = []): LaboratoryPurchasePreparationSummary
    {
        return LaboratoryPurchasePreparationSummary::query()->create(array_merge([
            'laboratory_purchase_id' => $purchase->id,
            'source_hash' => hash('sha256', 'summary-'.$purchase->id),
            'status' => LaboratoryPurchasePreparationSummary::STATUS_GENERATED,
            'decision_status' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL,
            'rules_version' => LaboratoryPreparationRuleEngine::RULES_VERSION,
            'rules_applied' => ['R04'],
            'fallback_reason' => null,
            'fallback_category' => null,
            'needs_provider_review' => false,
            'summary_text' => null,
            'summary_json' => [
                'summary' => ['text' => null],
                'sections' => [],
                'special_instructions' => [],
                'individual_instructions' => [],
                'decision' => [
                    'consolidated_requirements' => [],
                ],
            ],
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function bootstrapSchema(): void
    {
        $this->dropSchema();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('paternal_lastname')->nullable();
            $table->string('maternal_lastname')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('phone_country')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->dateTime('documentation_accepted_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('medical_attention_identifier')->nullable();
            $table->nullableMorphs('customerable');
            $table->timestamp('medical_attention_subscription_expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('administrators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_concierges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('administrator_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('brand');
            $table->string('gda_order_id')->nullable();
            $table->unsignedInteger('gda_consecutivo')->nullable();
            $table->string('gda_status', 32)->nullable();
            $table->string('name');
            $table->string('paternal_lastname');
            $table->string('maternal_lastname');
            $table->string('phone')->nullable();
            $table->string('phone_country')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->string('street')->nullable();
            $table->string('number')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('zipcode')->nullable();
            $table->unsignedInteger('total_cents')->default(0);
            $table->foreignId('customer_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->constrained();
            $table->string('gda_id')->nullable();
            $table->string('name');
            $table->mediumText('indications')->nullable();
            $table->json('feature_list')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ai_executions', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 80);
            $table->string('feature', 120);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('prompt_id')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('model')->nullable();
            $table->string('status', 40);
            $table->string('input_hash', 64)->nullable();
            $table->json('request_payload_redacted')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('laboratory_purchase_preparation_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id');
            $table->foreignId('ai_execution_id')->nullable();
            $table->string('source_hash', 64);
            $table->string('status', 40);
            $table->string('decision_status')->nullable();
            $table->string('rules_version')->nullable();
            $table->json('rules_applied')->nullable();
            $table->string('fallback_reason')->nullable();
            $table->string('fallback_category')->nullable();
            $table->boolean('needs_provider_review')->default(false);
            $table->longText('summary_text')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('notification_email_queued_at')->nullable();
            $table->timestamps();

            $table->foreign('laboratory_purchase_id', 'prep_summary_purchase_fk')
                ->references('id')
                ->on('laboratory_purchases')
                ->cascadeOnDelete();
        });

        Schema::create('laboratory_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('laboratory_purchase_id')->nullable();
            $table->string('gda_order_id')->nullable();
            $table->unsignedInteger('gda_consecutivo')->nullable();
            $table->string('notification_type')->nullable();
            $table->string('lineanegocio')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        Role::query()->create(['name' => 'dummy', 'guard_name' => 'web']);
        Role::query()->create(['name' => 'Administrador', 'guard_name' => 'web']);
    }

    private function seedNavigationPermissions(): void
    {
        $names = [
            'administrators.manage',
            'laboratory-purchases.manage',
            'laboratory-tests.manage',
            'laboratory-purchases.manage.vendor-payments',
            'online-pharmacy-purchases.manage',
            'online-pharmacy-purchases.manage.vendor-payments',
            'medical-attention-subscriptions.manage',
            'customers.manage',
            'coupons.manage',
            'documentation.manage',
            'simulators.manage',
            'logs-general.manage',
            'users.manage',
            'view carts',
            'efevoo-tokens.manage',
            'tax-profiles.manage',
            'payment-attempts.manage',
            'laboratory-notifications.monitor',
            'view_config_monitor',
        ];

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'in_app_notifications',
            'notifications',
            'laboratory_notifications',
            'laboratory_purchase_preparation_summaries',
            'ai_executions',
            'laboratory_purchase_items',
            'laboratory_purchases',
            'laboratory_concierges',
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'permissions',
            'roles',
            'administrators',
            'customers',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
}
