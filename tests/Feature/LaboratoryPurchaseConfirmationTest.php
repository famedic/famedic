<?php

namespace Tests\Feature;

use App\Enums\GdaOrderStatus;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Jobs\CoordinateLaboratoryPurchaseConfirmationJob;
use App\Models\Administrator;
use App\Models\AiExecution;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseConfirmation as Confirmation;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Models\User;
use App\Services\LaboratoryConfirmation\DeliveryNotAccepted;
use App\Services\LaboratoryConfirmation\LaboratoryConfirmationContent;
use App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator;
use App\Services\LaboratoryConfirmation\LaboratoryConfirmationTransport;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSource;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LaboratoryPurchaseConfirmationTest extends TestCase
{
    private array $deliveries = [];

    private $sendCallback = null;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(18, 0));
        config(['laboratory-confirmation.enabled' => true,
            'laboratory-confirmation.activated_at' => now()->subMinute()->toIso8601String(),
            'laboratory-confirmation.connection' => 'database',
            'services.laboratory_preparation.deterministic_v3_enabled' => false,
            'famedic.email_public_url' => 'https://example.test']);
        Queue::fake();
        Notification::fake();
        $this->bootstrapSchema();
        foreach (['transactions', 'laboratory_stores', 'laboratory_tests', 'laboratory_appointments', 'transactionables'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                if ($table === 'transactions') {
                    $t->string('payment_method')->nullable();
                    $t->string('gateway')->nullable();
                } elseif ($table === 'transactionables') {
                    $t->unsignedBigInteger('transaction_id');
                    $t->unsignedBigInteger('transactionable_id');
                    $t->string('transactionable_type');
                } elseif ($table === 'laboratory_appointments') {
                    $t->unsignedBigInteger('laboratory_purchase_id')->nullable();
                    $t->unsignedBigInteger('laboratory_store_id')->nullable();
                    $t->timestamp('appointment_date')->nullable();
                } elseif ($table === 'laboratory_tests') {
                    $t->string('brand');
                    $t->string('gda_id');
                    $t->json('feature_list')->nullable();
                } else {
                    $t->string('name')->nullable();
                    $t->string('address')->nullable();
                }
                $t->timestamps();
                $t->softDeletes();
            });
        }
        (require base_path('database/migrations/2026_10_16_200000_create_laboratory_purchase_confirmations_table.php'))->up();
        (require base_path('database/migrations/2026_10_16_210000_add_snapshot_version_to_laboratory_purchase_confirmations.php'))->up();
        $fake = \Mockery::mock(LaboratoryConfirmationTransport::class);
        $fake->shouldReceive('send')->andReturnUsing(function ($payload, $attemptId) {
            $this->deliveries[] = ['payload' => $payload, 'attempt_id' => $attemptId];
            if ($this->sendCallback) {
                return ($this->sendCallback)($payload, $attemptId);
            }

            return 'test-message-id';
        });
        app()->instance(LaboratoryConfirmationTransport::class, $fake);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        $this->travelBack();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    private function coordinator(): LaboratoryConfirmationCoordinator
    {
        return app(LaboratoryConfirmationCoordinator::class);
    }

    private function purchase(array $attributes = []): LaboratoryPurchase
    {
        return $this->seedPurchase(['gda_status' => GdaOrderStatus::Confirmed, ...$attributes]);
    }

    private function autoSummary(LaboratoryPurchase $purchase): LaboratoryPurchasePreparationSummary
    {
        return $this->seedSummary($purchase, [
            'source_hash' => app(LaboratoryPreparationSource::class)->deterministicHash($purchase),
            'decision_status' => 'AUTO_CONSOLIDATED',
            'ai_execution_id' => $this->seedExecution($purchase, 'succeeded', ['feature' => 'laboratory_preparation_redactor'])->id,
            'summary_text' => 'RESUMEN_VALIDADO',
            'summary_json' => ['summary' => 'RESUMEN_VALIDADO', 'sections' => [['title' => 'Preparación', 'content' => 'RESUMEN_VALIDADO']], 'special_instructions' => [], 'individual_instructions' => []],
        ]);
    }

    #[Test]
    public function rollout_esta_apagado_por_defecto_y_exige_fecha_explicita(): void
    {
        $defaults = require base_path('config/laboratory-confirmation.php');
        $this->assertFalse($defaults['enabled']);
        $purchase = $this->purchase();
        config(['laboratory-confirmation.activated_at' => null]);
        $this->assertFalse($this->coordinator()->enrollAfterCommit($purchase));
        $this->assertSame(0, Confirmation::count());
    }

    #[Test]
    public function registra_solo_despues_del_commit_final_y_no_renueva_el_plazo(): void
    {
        $purchase = $this->purchase();
        DB::beginTransaction();
        DB::beginTransaction();
        $this->assertTrue($this->coordinator()->enrollAfterCommit($purchase));
        DB::commit();
        $this->assertSame(0, Confirmation::count());
        DB::commit();
        $this->assertSame(1, Confirmation::count());
        $deadline = Confirmation::first()->deadline_at->toIso8601String();
        $this->travel(30)->seconds();
        $this->coordinator()->register($purchase);
        $this->assertSame(1, Confirmation::count());
        $this->assertSame($deadline, Confirmation::first()->deadline_at->toIso8601String());
    }

    #[Test]
    public function rollback_no_registra_confirmacion(): void
    {
        $purchase = $this->purchase();
        DB::beginTransaction();
        $this->coordinator()->enrollAfterCommit($purchase);
        DB::rollBack();
        $this->assertSame(0, Confirmation::count());
    }

    #[Test]
    public function ia_off_envia_originales_completos_por_estudio_y_no_duplica(): void
    {
        $purchase = $this->purchase();
        DB::table('laboratory_purchase_items')->insert(['laboratory_purchase_id' => $purchase->id, 'name' => 'Segundo estudio', 'gda_id' => '102', 'indications' => 'ORIGINAL_COMPLETO_'.str_repeat('fuente ', 100), 'price_cents' => 50]);
        $intent = $this->coordinator()->register($purchase);
        $this->coordinator()->coordinate($intent->id);
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(1, $this->deliveries);
        $html = $this->deliveries[0]['payload']['html'];
        $this->assertStringContainsString('25 mL', $html);
        $this->assertStringContainsString('Segundo estudio', $html);
        $this->assertStringContainsString(str_repeat('fuente ', 99), $html);
        $this->assertSame('accepted', $intent->fresh()->status);
        $this->assertSame('original', $intent->fresh()->content_kind);
        $this->assertStringNotContainsString('25 mL', DB::table('laboratory_purchase_confirmations')->value('payload'));
        $this->assertArrayNotHasKey('payload', $intent->fresh()->toArray());
    }

    #[Test]
    public function auto_antes_del_plazo_envia_resumen_sin_duplicar_originales(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase();
        $this->autoSummary($purchase);
        $intent = $this->coordinator()->register($purchase);
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(1, $this->deliveries);
        $this->assertStringContainsString('RESUMEN_VALIDADO', $this->deliveries[0]['payload']['html']);
        $this->assertStringNotContainsString('25 mL', $this->deliveries[0]['payload']['html']);
        $this->assertSame('auto', $intent->fresh()->content_kind);
    }

    #[Test]
    public function pendiente_espera_sin_dormir_y_al_vencer_usa_originales(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase();
        $this->seedExecution($purchase, 'queued');
        $intent = $this->coordinator()->register($purchase);
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(0, $this->deliveries);
        $this->assertSame('waiting', $intent->fresh()->status);
        $this->travel(15)->seconds();
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('original', $intent->fresh()->content_kind);
        $this->assertCount(1, $this->deliveries);
        $this->assertStringNotContainsString('Estamos preparando', $this->deliveries[0]['payload']['html']);
    }

    #[Test]
    public function fallback_y_fallo_conocidos_no_esperan_el_plazo(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        foreach (['fallback', 'failed'] as $case) {
            $purchase = $this->purchase();
            if ($case === 'fallback') {
                $this->seedSummary($purchase);
            } else {
                $this->seedExecution($purchase, 'failed', ['feature' => 'laboratory_preparation_redactor']);
            }
            $intent = $this->coordinator()->register($purchase);
            $this->coordinator()->coordinate($intent->id);
            $this->assertSame('accepted', $intent->fresh()->status);
            $this->assertSame('original', $intent->fresh()->content_kind);
        }
        $this->assertCount(2, $this->deliveries);
    }

    #[Test]
    public function resultado_tardio_no_cambia_correo_y_conserva_aviso_interno(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase();
        $intent = $this->coordinator()->register($purchase);
        $this->travel(16)->seconds();
        $this->coordinator()->coordinate($intent->id);
        $summary = $this->autoSummary($purchase);
        $this->assertTrue(app(LaboratoryPreparationSummaryNotificationService::class)->notifyIfNeeded($summary));
        $this->assertFalse(app(LaboratoryPreparationSummaryNotificationService::class)->notifyIfNeeded($summary->fresh()));
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(1, $this->deliveries);
        $this->assertSame(1, DB::table('notifications')->where('type', 'laboratory_preparation_summary_ready')->count());
        Notification::assertNothingSent();
        $this->assertNull($summary->fresh()->notification_email_queued_at);
    }

    #[Test]
    public function auto_generado_despues_del_deadline_no_gana_si_worker_llega_tarde(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase();
        $intent = $this->coordinator()->register($purchase);
        $this->travel(16)->seconds();
        $this->autoSummary($purchase);
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('original', $intent->fresh()->content_kind);
    }

    #[Test]
    public function rechazo_inequivoco_reintenta_el_mismo_contenido_con_otro_intento(): void
    {
        $purchase = $this->purchase();
        $intent = $this->coordinator()->register($purchase);
        $this->sendCallback = fn () => throw new DeliveryNotAccepted('simulated_rejection');
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('retry_wait', $intent->fresh()->status);
        DB::table('laboratory_purchase_items')->where('laboratory_purchase_id', $purchase->id)->update(['indications' => 'NUEVO_TEXTO']);
        $version = $intent->fresh()->snapshot_version;
        $this->sendCallback = null;
        $this->travel(30)->seconds();
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(2, $this->deliveries);
        $this->assertSame($this->deliveries[0]['payload'], $this->deliveries[1]['payload']);
        $this->assertNotSame($this->deliveries[0]['attempt_id'], $this->deliveries[1]['attempt_id']);
        $this->assertSame(2, $intent->fresh()->attempts);
        $this->assertSame($version, $intent->fresh()->snapshot_version);
    }

    #[Test]
    public function timeout_ambiguo_no_reintenta_ni_reconciliando(): void
    {
        $purchase = $this->purchase();
        $intent = $this->coordinator()->register($purchase);
        $this->sendCallback = fn () => throw new \RuntimeException('PRIVATE_PROVIDER_PAYLOAD timeout');
        $this->coordinator()->coordinate($intent->id);
        $this->coordinator()->reconcile();
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(1, $this->deliveries);
        $this->assertSame('ambiguous', $intent->fresh()->status);
        $this->assertSame('delivery_ambiguous', $intent->fresh()->error_code);
    }

    #[Test]
    public function dos_coordinadores_solapados_no_reclaman_dos_envios(): void
    {
        $purchase = $this->purchase();
        $intent = $this->coordinator()->register($purchase);
        $this->sendCallback = function () use ($intent) {
            $this->coordinator()->coordinate($intent->id);

            return 'accepted';
        };
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(1, $this->deliveries);
        $this->assertSame(1, $intent->fresh()->attempts);
    }

    #[Test]
    public function worker_interrumpido_en_envio_es_ambiguo_y_preparacion_es_recuperable(): void
    {
        $a = $this->coordinator()->register($this->purchase());
        $b = $this->coordinator()->register($this->purchase());
        $a->update(['status' => 'sending', 'lease_until' => now()->subSecond(), 'transport_status' => 'in_flight']);
        $b->update(['status' => 'preparing', 'lease_until' => now()->subSecond()]);
        $this->coordinator()->reconcile();
        $this->assertSame('ambiguous', $a->fresh()->status);
        $this->assertSame('waiting', $b->fresh()->status);
    }

    #[Test]
    public function reconciliador_recupera_omisiones_sin_compras_historicas(): void
    {
        $old = $this->purchase(['created_at' => now()->subDays(2)]);
        $new = $this->purchase();
        $this->travel(30)->seconds();
        $this->coordinator()->reconcile();
        $this->assertSame(1, Confirmation::count());
        $intent = Confirmation::first();
        $this->assertSame($new->id, $intent->laboratory_purchase_id);
        $this->assertTrue($intent->deadline_at->isPast());
        $this->assertNull($this->coordinator()->register($old));
    }

    #[Test]
    public function fallo_de_despacho_preserva_intencion_y_se_recupera(): void
    {
        $purchase = $this->purchase();
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('dispatch unavailable'));
        $intent = $this->coordinator()->register($purchase);
        $this->assertSame('waiting', $intent->fresh()->status);
        Queue::fake();
        $this->coordinator()->reconcile();
        Queue::assertPushed(CoordinateLaboratoryPurchaseConfirmationJob::class);
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('accepted', $intent->fresh()->status);
    }

    #[Test]
    public function reenvio_explicito_tiene_auditoria_independiente(): void
    {
        $purchase = $this->purchase(['created_at' => now()->subDays(2)]);
        $actor = $this->seedAdminUser(true);
        $intent = $this->coordinator()->requestResend($purchase, $actor->id);
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame($actor->id, (int) $intent->fresh()->requested_by);
        $this->assertSame('administrative_resend', $intent->event_type);
        $this->assertCount(1, $this->deliveries);
    }

    #[Test]
    public function compra_con_cita_incluye_preparacion_y_datos_de_cita(): void
    {
        $purchase = $this->purchase();
        $store = DB::table('laboratory_stores')->insertGetId(['name' => 'Sucursal QA', 'address' => 'Domicilio QA']);
        DB::table('laboratory_appointments')->insert(['laboratory_purchase_id' => $purchase->id, 'laboratory_store_id' => $store, 'appointment_date' => now()->addDay()]);
        $intent = $this->coordinator()->register($purchase);
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(1, $this->deliveries);
        $this->assertStringContainsString('Sucursal QA', $this->deliveries[0]['payload']['html']);
        $this->assertStringContainsString('25 mL', $this->deliveries[0]['payload']['html']);
        $this->assertStringNotContainsString('En  breve', $this->deliveries[0]['payload']['html']);
    }

    #[Test]
    public function configuracion_sync_no_ejecuta_envios_inline(): void
    {
        config(['laboratory-confirmation.connection' => 'sync']);
        $intent = $this->coordinator()->register($this->purchase());
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('waiting', $intent->fresh()->status);
        $this->assertCount(0, $this->deliveries);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function dos_procesos_con_sqlite_descartable_compiten_por_un_unico_envio(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the real process race.');
        }
        $purchase = $this->purchase();
        $intent = $this->coordinator()->register($purchase);
        $intent->update(['snapshot_version' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'ready', 'payload' => ['recipient' => 'qa@example.test', 'subject' => 'QA', 'html' => 'QA']]);
        $dbFile = tempnam(sys_get_temp_dir(), 'confirmation-race-');
        $countFile = tempnam(sys_get_temp_dir(), 'confirmation-count-');
        DB::connection()->getPdo()->exec("VACUUM INTO '".$dbFile."'");
        $original = config('database.connections.sqlite.database');
        config(['database.connections.sqlite.database' => $dbFile]);
        DB::purge();
        app()->instance(LaboratoryConfirmationTransport::class, new class($countFile) extends LaboratoryConfirmationTransport
        {
            public function __construct(private string $path) {}

            public function send(array $payload, string $attemptId): string
            {
                file_put_contents($this->path, $attemptId."\n", FILE_APPEND | LOCK_EX);

                return 'fake-accepted';
            }
        });
        $children = [];
        $gates = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $pid = pcntl_fork();
                if ($pid === 0) {
                    fclose($pair[0]);
                    fread($pair[1], 1);
                    fclose($pair[1]);
                    try {
                        DB::purge();
                        DB::statement('PRAGMA busy_timeout = 5000');
                        app(LaboratoryConfirmationCoordinator::class)->coordinate($intent->id);
                        exit(0);
                    } catch (\Throwable) {
                        exit(1);
                    }
                }
                fclose($pair[1]);
                $children[] = $pid;
                $gates[] = $pair[0];
            }
            foreach ($gates as $gate) {
                fwrite($gate, 'g');
                fclose($gate);
            }
            foreach ($children as $child) {
                pcntl_waitpid($child, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $this->assertCount(1, file($countFile, FILE_IGNORE_NEW_LINES));
            $this->assertSame('accepted', $intent->fresh()->status);
            $this->assertSame(1, $intent->fresh()->attempts);
        } finally {
            DB::purge();
            config(['database.connections.sqlite.database' => $original]);
            unlink($dbFile);
            unlink($countFile);
        }
    }

    #[Test]
    public function rechazo_repetido_agota_intentos_sin_perder_la_auditoria(): void
    {
        $intent = $this->coordinator()->register($this->purchase());
        $this->sendCallback = fn () => throw new DeliveryNotAccepted('rejected');
        $this->coordinator()->coordinate($intent->id);
        $this->travel(30)->seconds();
        $this->coordinator()->coordinate($intent->id);
        $this->travel(120)->seconds();
        $this->coordinator()->coordinate($intent->id);
        $this->coordinator()->reconcile();
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(3, $this->deliveries);
        $this->assertSame('failed', $intent->fresh()->status);
        $this->assertSame('not_accepted', $intent->fresh()->transport_status);
    }

    #[Test]
    public function auto_justo_en_el_limite_es_valido_pero_fuente_obsoleta_no(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase();
        $intent = $this->coordinator()->register($purchase);
        $this->travel(15)->seconds();
        $this->autoSummary($purchase);
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('auto', $intent->fresh()->content_kind);
        $other = $this->purchase();
        $this->autoSummary($other);
        $next = $this->coordinator()->register($other);
        DB::table('laboratory_purchase_items')->where('laboratory_purchase_id', $other->id)->update(['indications' => 'ORIGINAL_NUEVO_VIGENTE']);
        $this->travel(15)->seconds();
        $this->coordinator()->coordinate($next->id);
        $this->assertSame('original', $next->fresh()->content_kind);
        $this->assertStringContainsString('ORIGINAL_NUEVO_VIGENTE', $this->deliveries[1]['payload']['html']);
    }

    #[Test]
    public function adaptador_real_con_transporte_array_conserva_remitente_y_id(): void
    {
        config(['mail.safe_mode.enabled' => false, 'mail.default' => 'array', 'mail.from' => ['address' => 'sender@example.test', 'name' => 'QA']]);
        $transport = new LaboratoryConfirmationTransport;
        $id = (string) \Illuminate\Support\Str::uuid();
        $messageId = $transport->send(['recipient' => 'recipient@example.test', 'subject' => 'QA', 'html' => '<p>QA</p>'], $id);
        $this->assertStringContainsString($id, $messageId);
    }

    #[Test]
    public function pausa_del_rollout_no_reactiva_el_correo_adicional_de_compras_enroladas(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase();
        $intent = $this->coordinator()->register($purchase);
        config(['laboratory-confirmation.enabled' => false]);
        $summary = $this->autoSummary($purchase);
        $this->assertTrue(app(LaboratoryPreparationSummaryNotificationService::class)->notifyIfNeeded($summary));
        $this->coordinator()->coordinate($intent->id);
        Notification::assertNothingSent();
        $this->assertCount(0, $this->deliveries);
        $this->assertSame('waiting', $intent->fresh()->status);
    }

    #[Test]
    public function compras_historicas_conservan_el_aviso_legacy(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase(['created_at' => now()->subDays(2)]);
        $summary = $this->autoSummary($purchase);
        $this->assertTrue(app(LaboratoryPreparationSummaryNotificationService::class)->notifyIfNeeded($summary));
        Notification::assertSentTo($purchase->customer->user, \App\Notifications\LaboratoryPreparationSummaryAvailable::class);
        $this->assertSame(0, Confirmation::count());
    }

    #[Test]
    public function fallo_de_generacion_conocido_acelera_los_originales(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase();
        $intent = $this->coordinator()->register($purchase);
        $this->coordinator()->wake($purchase, failed: true);
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('original', $intent->fresh()->content_kind);
        $this->assertSame('accepted', $intent->fresh()->status);
        $this->assertTrue($intent->deadline_at->isFuture());
    }

    #[Test]
    public function fecha_relativa_o_futura_no_activa_envios(): void
    {
        config(['laboratory-confirmation.activated_at' => 'now']);
        $this->assertNull($this->coordinator()->activation());
        config(['laboratory-confirmation.activated_at' => now()->addDay()->toIso8601String()]);
        $this->assertNull($this->coordinator()->activation());
        $this->assertNull($this->coordinator()->register($this->purchase()));
    }

    #[Test]
    public function ruta_de_reenvio_registra_actor_y_no_envia_inline(): void
    {
        $this->seedNavigationPermissions();
        $admin = $this->seedAdminUser(true);
        $admin->administrator->givePermissionTo('laboratory-purchases.manage');
        $purchase = $this->purchase();
        $this->actingAs($admin)->post(route('admin.laboratory-purchases.resend-confirmation-email', $purchase))->assertRedirect();
        $this->assertSame($admin->id, (int) Confirmation::first()->requested_by);
        $this->assertSame('administrative_resend', Confirmation::first()->event_type);
        $this->assertCount(0, $this->deliveries);
        Notification::assertNothingSent();
    }

    #[Test]
    public function snapshot_preparado_se_revalida_antes_del_primer_envio_si_apagan_ia(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase();
        $this->autoSummary($purchase);
        $intent = $this->coordinator()->register($purchase);
        $presentation = app(LaboratoryConfirmationContent::class)->presentation($purchase->fresh());
        $intent->update(['snapshot_version' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'ready', 'content_kind' => 'auto', 'source_hash' => $presentation['source_hash'],
            'payload' => ['recipient' => 'qa@example.test', 'subject' => 'QA', 'html' => 'RESUMEN_VALIDADO']]);
        config(['services.laboratory_preparation.deterministic_v3_enabled' => false]);
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('waiting', $intent->fresh()->status);
        $this->assertCount(0, $this->deliveries);
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('original', $intent->fresh()->content_kind);
        $this->assertCount(1, $this->deliveries);
    }

    #[Test]
    public function pausa_y_reanudacion_no_envian_legacy_a_compras_de_la_misma_cohorte(): void
    {
        $first = $this->purchase();
        $this->coordinator()->register($first);
        $boundary = DB::table('laboratory_confirmation_rollouts')->value('activated_at');
        config(['laboratory-confirmation.enabled' => false]);
        $duringPause = $this->purchase();
        $this->assertTrue($this->coordinator()->enrollAfterCommit($duringPause));
        $this->assertTrue($this->coordinator()->suppressesSummaryMail($duringPause));
        $this->assertSame(2, Confirmation::count());
        $this->coordinator()->coordinate(Confirmation::latest('id')->first()->id);
        $this->assertCount(0, $this->deliveries);
        config(['laboratory-confirmation.enabled' => true, 'laboratory-confirmation.activated_at' => now()->subDays(3)->toIso8601String()]);
        $this->coordinator()->reconcile();
        $this->assertSame($boundary, DB::table('laboratory_confirmation_rollouts')->value('activated_at'));
        $this->assertSame(2, Confirmation::count());
    }

    private function readyAutoSnapshot(): Confirmation
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => true]);
        $purchase = $this->purchase();
        $this->autoSummary($purchase);
        $intent = $this->coordinator()->register($purchase);
        $presentation = app(LaboratoryConfirmationContent::class)->presentation($purchase->fresh());
        $intent->update(['status' => 'ready', 'content_kind' => 'auto',
            'snapshot_version' => (string) \Illuminate\Support\Str::uuid(), 'source_hash' => $presentation['source_hash'],
            'payload' => ['recipient' => 'qa@example.test', 'subject' => 'QA', 'html' => 'OLD_AUTO', 'text' => 'OLD_AUTO']]);

        return $intent;
    }

    private function replaceSnapshot(Confirmation $intent): void
    {
        $encrypted = new Confirmation(['payload' => ['recipient' => 'qa@example.test', 'subject' => 'QA',
            'html' => 'NEW_ORIGINAL', 'text' => 'NEW_ORIGINAL']]);
        DB::table('laboratory_purchase_confirmations')->where('id', $intent->id)->update([
            'snapshot_version' => (string) \Illuminate\Support\Str::uuid(), 'content_kind' => 'original',
            'payload' => $encrypted->getAttributes()['payload']]);
    }

    private function coordinateWithPresentation(Confirmation $intent, callable $callback): void
    {
        $real = app(LaboratoryConfirmationContent::class);
        $content = \Mockery::mock(LaboratoryConfirmationContent::class);
        $content->shouldReceive('presentation')->once()->andReturnUsing(function ($purchase) use ($callback, $real) {
            return $callback($real->presentation($purchase));
        });
        (new LaboratoryConfirmationCoordinator($content, app(LaboratoryConfirmationTransport::class)))->coordinate($intent->id);
    }

    #[Test]
    public function lector_antiguo_no_reclama_snapshot_reemplazado_por_cambio_de_fuente(): void
    {
        $intent = $this->readyAutoSnapshot();
        $this->coordinateWithPresentation($intent, function ($presentation) use ($intent) {
            DB::table('laboratory_purchase_items')->where('laboratory_purchase_id', $intent->laboratory_purchase_id)
                ->update(['indications' => 'NEW_SOURCE']);
            $this->replaceSnapshot($intent);

            return $presentation;
        });
        $this->assertCount(0, $this->deliveries);
        $this->assertSame('ready', $intent->fresh()->status);
        $this->coordinator()->coordinate($intent->id);
        $this->assertCount(1, $this->deliveries);
        $this->assertSame($intent->fresh()->payload, $this->deliveries[0]['payload']);
        $this->assertSame('original', $intent->fresh()->content_kind);
        $this->assertSame('NEW_ORIGINAL', $this->deliveries[0]['payload']['html']);
    }

    #[Test]
    public function invalidacion_tardia_no_borra_otro_snapshot(): void
    {
        $intent = $this->readyAutoSnapshot();
        $this->coordinateWithPresentation($intent, function ($presentation) use ($intent) {
            $this->replaceSnapshot($intent);
            $presentation['has_ai_summary'] = false;

            return $presentation;
        });
        $this->assertSame('ready', $intent->fresh()->status);
        $this->assertSame('NEW_ORIGINAL', $intent->fresh()->payload['html']);
        $this->assertCount(0, $this->deliveries);
    }

    #[Test]
    public function cambio_de_flag_concurrente_no_invalida_version_nueva(): void
    {
        $intent = $this->readyAutoSnapshot();
        $this->coordinateWithPresentation($intent, function ($presentation) use ($intent) {
            $this->replaceSnapshot($intent);
            config(['services.laboratory_preparation.deterministic_v3_enabled' => false]);

            return $presentation;
        });
        $this->assertSame('ready', $intent->fresh()->status);
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('NEW_ORIGINAL', $this->deliveries[0]['payload']['html']);
        $this->assertSame($intent->fresh()->payload, $this->deliveries[0]['payload']);
    }

    #[Test]
    public function fallo_de_descifrado_de_version_antigua_no_falla_snapshot_nuevo(): void
    {
        $intent = $this->readyAutoSnapshot();
        DB::table('laboratory_purchase_confirmations')->where('id', $intent->id)->update(['payload' => 'INVALID_OLD_CIPHERTEXT']);
        $this->coordinateWithPresentation($intent, function ($presentation) use ($intent) {
            $this->replaceSnapshot($intent);

            return $presentation;
        });
        $this->assertSame('ready', $intent->fresh()->status);
        $this->assertNull($intent->fresh()->error_code);
        $this->coordinator()->coordinate($intent->id);
        $this->assertSame('accepted', $intent->fresh()->status);
        $this->assertSame($intent->fresh()->payload, $this->deliveries[0]['payload']);
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
            'laboratory_purchase_confirmations', 'laboratory_confirmation_rollouts', 'transactionables', 'transactions', 'laboratory_appointments', 'laboratory_stores', 'laboratory_tests',
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
