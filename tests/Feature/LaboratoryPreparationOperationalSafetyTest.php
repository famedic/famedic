<?php

namespace Tests\Feature;

use App\Jobs\GenerateLaboratoryPurchasePreparationSummaryJob;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LaboratoryPreparation\LaboratoryPreparationFidelityValidationException;
use App\Services\LaboratoryPreparation\LaboratoryPreparationOperationalException;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryPreparationOperationalSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const CANARY = 'AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML';

    public static function failures(): array
    {
        return [['before_generation'], ['generation'], ['fallback'], ['transport']];
    }

    #[Test]
    #[DataProvider('failures')]
    public function worker_retries_and_failed_jobs_only_expose_safe_diagnostics(string $stage): void
    {
        $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();
        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $user->customer->id, 'brand' => 'olab',
            'gda_order_id' => 'P2-ISOLATED', 'gda_consecutivo' => 123456,
            'name' => 'Paciente', 'paternal_lastname' => 'QA', 'maternal_lastname' => 'Aislado',
            'phone' => '8111111111', 'phone_country' => 'MX', 'birth_date' => '1990-01-01',
            'street' => 'QA', 'number' => '1', 'neighborhood' => 'QA', 'state' => 'NL',
            'city' => 'Monterrey', 'zipcode' => '64000', 'total_cents' => 10000,
        ]);
        LaboratoryPurchaseItem::factory()->create(['laboratory_purchase_id' => $purchase->id, 'indications' => 'Ayuno de 8 horas.']);

        // The original branch has no persisted gda_status column. Supply confirmed
        // evidence only to this test's retrieved instance, without relaxing eligibility
        // or changing the application schema, payment records, or GDA behavior.
        LaboratoryPurchase::retrieved(function (LaboratoryPurchase $loaded) use ($purchase) {
            if ($loaded->id === $purchase->id) {
                $loaded->setAttribute('gda_status', 'confirmed');
                $loaded->setRelation('transactions', collect([(new Transaction)->forceFill([
                    'payment_method' => 'odessa', 'reference_id' => 'P2-ISOLATED-CONFIRMED',
                ])]));
            }
        });

        $service = Mockery::mock(LaboratoryPreparationSummaryService::class);
        if ($stage === 'transport') {
            $mail = Mockery::mock(\Illuminate\Notifications\Channels\MailChannel::class);
            $mail->shouldReceive('send')->times(3)->andThrow(new \RuntimeException(self::CANARY));
            app()->instance(\Illuminate\Notifications\Channels\MailChannel::class, $mail);
        } elseif ($stage === 'before_generation') {
            $service->shouldReceive('deterministicV3Enabled')->times(3)->andThrow(new \RuntimeException(self::CANARY));
        } else {
            $service->shouldReceive('deterministicV3Enabled')->andReturnTrue();
            $service->shouldReceive('deterministicV3ShadowEnabled')->andReturnFalse();
            $service->shouldReceive('hasCurrentGeneratedSummary')->andReturnFalse();
            $service->shouldReceive('deterministicV3ShadowEffectiveEnabled')->andReturnFalse();
            $service->shouldReceive('generate')->times(3)->andThrow($stage === 'fallback'
                ? new LaboratoryPreparationFidelityValidationException(self::CANARY, ['provider' => self::CANARY])
                : new \RuntimeException(self::CANARY));
            if ($stage === 'fallback') {
                $service->shouldReceive('generateFallbackFromSource')->times(3)->andThrow(new \RuntimeException(self::CANARY));
            }
        }
        app()->instance(LaboratoryPreparationSummaryService::class, $service);

        $logPath = storage_path('logs/p2-isolated-'.\Illuminate\Support\Str::uuid().'.log');
        config(['logging.default' => 'single', 'logging.channels.single.path' => $logPath,
            'queue.default' => 'database', 'queue.connections.database.connection' => config('database.default'),
            'queue.failed.driver' => 'database-uuids', 'queue.failed.database' => config('database.default')]);
        Log::forgetChannel('single');
        app()->forgetInstance('queue.failer');
        $job = new GenerateLaboratoryPurchasePreparationSummaryJob($purchase->id);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff);
        // Opt out of afterCommit only for the disposable test transaction,
        // so the real database worker can consume the queued job.
        if ($stage === 'transport') {
            $summary = \App\Models\LaboratoryPurchasePreparationSummary::query()->create([
                'laboratory_purchase_id' => $purchase->id, 'source_hash' => str_repeat('a', 64),
                'status' => 'generated', 'summary_text' => 'Indicaciones QA.',
                'summary_json' => ['summary' => 'Indicaciones QA.', 'sections' => []],
            ]);
            $notification = (new \App\Notifications\LaboratoryPreparationSummaryAvailable($purchase, $summary))
                ->onConnection('database')->onQueue('p2-isolated')->beforeCommit();
            $user->notify($notification);
        } else {
            Queue::connection('database')->push($job->beforeCommit(), '', 'p2-isolated');
        }
        $outputs = [];
        try {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'p2-isolated',
                    '--once' => true, '--sleep' => 0, '--timeout' => 20, '--tries' => 3])->assertExitCode(0);
                $outputs[] = app(\Illuminate\Contracts\Console\Kernel::class)->output();
                if ($attempt < 3) {
                    $this->assertSame($attempt, (int) DB::table('jobs')->sole()->attempts);
                    $this->assertSame(0, DB::table('failed_jobs')->count());
                    DB::table('jobs')->update(['available_at' => time() - 1]);
                }
            }
            $this->assertSame(0, DB::table('jobs')->count());
            $failed = DB::table('failed_jobs')->sole();
            $this->assertStringContainsString('LaboratoryPreparationOperationalException', $failed->exception);
            $this->assertStringContainsString('correlation=', $failed->exception);
            $this->assertStringContainsString('category=', $failed->exception);
            $channels = [json_encode($outputs), $failed->exception, $failed->payload, file_get_contents($logPath)];
            foreach ($channels as $channel) {
                $this->assertStringNotContainsString(self::CANARY, $channel);
            }
            $this->assertStringContainsString($stage === 'transport' ? 'laboratory_preparation_summary_notification_job_failed' : 'laboratory_preparation_summary_job_failed', end($channels));
            $this->assertStringContainsString('correlation_id', end($channels));
        } finally {
            Log::forgetChannel('single');
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }
    }

    #[Test]
    public function successful_notification_middleware_preserves_result_and_invocation(): void
    {
        $job = new \stdClass;
        $calls = 0;
        $result = (new \App\Services\LaboratoryPreparation\LaboratoryPreparationSafeNotificationMiddleware)->handle(
            $job,
            function (object $received) use ($job, &$calls): string {
                $this->assertSame($job, $received);
                $calls++;
                return 'sent-with-fake-transport';
            },
        );
        $this->assertSame(1, $calls);
        $this->assertSame('sent-with-fake-transport', $result);
    }

    #[Test]
    public function operational_exception_whitelists_context_and_never_chains_free_text(): void
    {
        $safe = new LaboratoryPreparationOperationalException(self::CANARY, self::CANARY);
        $this->assertSame('job', $safe->stage);
        $this->assertSame('unexpected', $safe->causeCategory);
        $this->assertNull($safe->getPrevious());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $safe->correlationId);
        $this->assertStringNotContainsString(self::CANARY, $safe->getMessage());
        $this->assertStringNotContainsString(self::CANARY, json_encode($safe->context()));
    }
}
