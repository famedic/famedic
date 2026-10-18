<?php

namespace Tests\Feature\Laboratory;

use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GdaP0ConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        if (! self::$migrated) {
            Artisan::call('migrate', ['--force' => true]);
            Schema::create('gda_p0_process_audit', function (Blueprint $table) {
                $table->id(); $table->string('run', 36)->index(); $table->string('event'); $table->timestamp('created_at');
            });
            self::$migrated = true;
        }
    }

    public static function scenarios(): array
    {
        return [
            'two finalizers' => ['finalize', 'finalize', 'success'],
            'capture response before persistence failure' => ['callback', 'callback', 'capture_persistence'],
            'two captures' => ['callback', 'callback', 'success'],
            'two cart charges' => ['checkout', 'checkout', 'success'],
            'callback and webhook' => ['callback', 'webhook', 'success'],
            'process exits after GDA submission' => ['finalize', 'finalize', 'crash'],
            'timeout and retry' => ['finalize', 'finalize', 'timeout'],
            'response then persistence failure' => ['finalize', 'finalize', 'persistence'],
        ];
    }

    #[Test]
    #[DataProvider('scenarios')]
    public function independent_processes_do_not_repeat_gda_or_refund(string $first, string $second, string $scenario): void
    {
        $user = User::factory()->withCompleteProfile()->withRegularCustomer()->create(['documentation_accepted_at' => now()]);
        $customer = $user->customer;
        $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value,
            'name' => 'QA proceso', 'gda_id' => 'QA-'.Str::uuid(), 'indications' => 'Ayuno de 8 horas.',
            'requires_appointment' => false, 'public_price_cents' => 39900, 'famedic_price_cents' => 39900]);
        LaboratoryCartItem::factory()->create(['customer_id' => $customer->id, 'laboratory_test_id' => $test->id]);
        $address = Address::factory()->create(['customer_id' => $customer->id]);
        $contact = Contact::factory()->create(['customer_id' => $customer->id]);
        $monitor = app(SyncMonitoringCartService::class);
        $monitor->syncLaboratory($customer);
        $cart = $monitor->activeLaboratoryCart($customer, LaboratoryBrand::OLAB);
        $run = (string) Str::uuid();
        $transaction = Transaction::query()->create(['transaction_amount_cents' => 39900, 'payment_method' => 'paypal',
            'payment_status' => 'pending', 'provider_order_id' => 'ORDER-'.$run, 'reference_id' => 'ORDER-'.$run,
            'details' => ['customer_id' => $customer->id, 'address_id' => $address->id, 'contact_id' => $contact->id,
                'laboratory_brand' => LaboratoryBrand::OLAB->value, 'total_cents' => 39900, 'cart_id' => $cart->id]]);
        $processes = [];
        try {
            foreach ([$first, $second] as $operation) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/GdaP0Process.php'), $operation, (string) $transaction->id, $run, $scenario], base_path());
                $process->setTimeout(30); $process->start(); $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            while (DB::table('gda_p0_process_audit')->where('run', $run)->where('event', 'ready')->count() < 2) {
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning(), $process->getErrorOutput().$process->getOutput());
                }
                $this->assertLessThan($deadline, microtime(true), 'Independent process boot barrier');
                usleep(20000);
            }
            DB::table('gda_p0_process_audit')->insert(['run' => $run, 'event' => 'start', 'created_at' => now()]);
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
                $this->assertStringNotContainsString('AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML', $process->getErrorOutput().$process->getOutput());
            }
            $retry = new Process([PHP_BINARY, base_path('tests/Support/GdaP0Process.php'), 'callback', (string) $transaction->id, $run, $scenario], base_path());
            $retry->setTimeout(20); $retry->run();
            $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput().$retry->getOutput());
            $events = DB::table('gda_p0_process_audit')->where('run', $run)->pluck('event');
            if ($scenario === 'capture_persistence') {
                $this->assertSame(1, $events->filter(fn ($event) => $event === 'capture')->count());
                foreach (['gda', 'refund', 'conversion', 'automation', 'notification'] as $event) {
                    $this->assertSame(0, $events->filter(fn ($item) => $item === $event)->count());
                }
                $this->assertSame(0, $transaction->laboratoryPurchases()->count());
                $this->assertArrayHasKey('laboratory_capture_started_at', $transaction->fresh()->details);
                $blocked = false;
                try { app(\App\Services\Laboratory\UncertainGdaPaymentGuard::class)->withCartLock($cart, fn () => $this->fail('A new charge must be blocked')); }
                catch (\App\Exceptions\LaboratoryPaymentInProgressException) { $blocked = true; }
                $this->assertTrue($blocked);
                return;
            }
            $this->assertSame(1, $events->filter(fn ($event) => $event === 'gda')->count());
            $this->assertSame(0, $events->filter(fn ($event) => $event === 'refund')->count());
            $this->assertLessThanOrEqual(1, $events->filter(fn ($event) => $event === 'capture')->count());
            $this->assertSame(1, $transaction->laboratoryPurchases()->count());
            $purchase = $transaction->laboratoryPurchases()->sole();
            $this->assertSame($scenario === 'success' ? 'confirmed' : 'uncertain', $purchase->gda_status->value);
            $this->assertSame($first === 'checkout' ? 'completed' : 'captured', $transaction->fresh()->payment_status);
            $this->assertSame($first === 'checkout' ? 1 : 0, $events->filter(fn ($event) => $event === 'charge')->count());
            $this->assertSame($scenario === 'success' ? 1 : 0, $events->filter(fn ($event) => $event === 'notification')->count());
            $this->assertSame($scenario === 'success' ? 0 : 1, $customer->laboratoryCartItems()->count());
            foreach (['conversion', 'automation'] as $event) {
                $this->assertSame($scenario === 'success' ? 1 : 0, $events->filter(fn ($item) => $item === $event)->count(), $event.': '.json_encode($events));
            }
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(); } }
        }
    }
}
