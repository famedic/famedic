<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\CreateGDAQuotationAction;
use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchaseConfirmation as Confirmation;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LaboratoryConfirmationWorkersTest extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$migrated) {
            Artisan::call('migrate', ['--force' => true]);
            Schema::create('confirmation_worker_audit', function (Blueprint $table) {
                $table->id(); $table->string('run', 36)->index(); $table->string('event');
                $table->unsignedBigInteger('intent_id')->nullable(); $table->string('digest', 64)->nullable(); $table->timestamp('created_at');
            });
            self::$migrated = true;
        }
        DB::table('jobs')->delete(); // Disposable test queue only.
        config(['queue.default' => 'database', 'laboratory-confirmation.enabled' => true,
            'laboratory-confirmation.activated_at' => now()->subDay()->utc()->toIso8601String(),
            'services.laboratory_preparation.deterministic_v3_enabled' => true]);
        Notification::fake();
        $marketing = \Mockery::mock(\App\Actions\Marketing\RecordMarketingCampaignConversionAction::class);
        $marketing->shouldReceive('__invoke')->andReturn(new \App\DataTransferObjects\Marketing\RecordMarketingCampaignConversionResult(\App\Enums\MarketingCampaignConversionStatus::NotAttributed));
        app()->instance(\App\Actions\Marketing\RecordMarketingCampaignConversionAction::class, $marketing);
        $automation = \Mockery::mock(\App\Services\Orders\OrderAutomationService::class)->makePartial();
        $automation->shouldReceive('contextForLaboratory')->andThrow(new \RuntimeException('QA drivers disabled'));
        app()->instance(\App\Services\Orders\OrderAutomationService::class, $automation);
    }

    private function purchase(bool $appointment = false, bool $uncertain = false): \App\Models\LaboratoryPurchase
    {
        $user = User::factory()->withCompleteProfile()->withRegularCustomer()->create(['documentation_accepted_at' => now()]);
        $customer = $user->customer;
        $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value, 'gda_id' => 'QA-'.Str::uuid(),
            'name' => 'QA Estudio', 'indications' => 'Ayuno de 8 horas.', 'requires_appointment' => $appointment,
            'public_price_cents' => 39900, 'famedic_price_cents' => 39900]);
        LaboratoryCartItem::factory()->create(['customer_id' => $customer->id, 'laboratory_test_id' => $test->id]);
        $address = Address::factory()->create(['customer_id' => $customer->id]);
        $contact = Contact::factory()->create(['customer_id' => $customer->id]);
        $transaction = Transaction::create(['transaction_amount_cents' => 39900, 'payment_method' => 'paypal',
            'reference_id' => 'QA-'.Str::uuid(), 'gateway_status' => 'COMPLETED', 'payment_status' => 'captured', 'gateway_transaction_id' => 'QA-'.Str::uuid()]);
        app(SyncMonitoringCartService::class)->syncLaboratory($customer);
        $cart = app(SyncMonitoringCartService::class)->activeLaboratoryCart($customer, LaboratoryBrand::OLAB);
        $gda = \Mockery::mock(CreateGDAQuotationAction::class);
        if ($uncertain) { $gda->shouldReceive('__invoke')->once()->andThrow(new \App\Exceptions\GdaOrderResultUncertainException); }
        else { $gda->shouldReceive('__invoke')->once()->andReturn(['id' => 'QA-'.Str::uuid(), 'infogda_consecutivo' => random_int(100000, 999999)]); }
        app()->instance(CreateGDAQuotationAction::class, $gda);
        $date = $appointment ? \App\Models\LaboratoryAppointment::factory()->create(['customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB->value, 'appointment_date' => now()->addDay(), 'confirmed_at' => now()]) : null;
        try {
            $purchase = app(FulfillLaboratoryCartOrderAction::class)($customer, LaboratoryBrand::OLAB, $address, $contact, $transaction, $date,
                $customer->laboratoryCartItems()->with('laboratoryTest')->get(), LaboratoryBrand::OLAB->value, cart: $cart);
        } catch (\App\Exceptions\GdaOrderResultUncertainException $safe) { $purchase = $safe->purchase(); }
        $this->assertSame(1, $purchase->transactions()->count());
        $this->assertSame('captured', $transaction->fresh()->payment_status);
        $this->assertNull($transaction->fresh()->refunded_at);
        if ($date && ! $uncertain) {
            Notification::assertNotSentTo($user, \App\Notifications\LaboratoryAppointmentUpdatedByConcierge::class);
            $user->notify(new \App\Notifications\LaboratoryAppointmentUpdatedByConcierge($date->fresh()));
            Notification::assertSentTo($user, \App\Notifications\LaboratoryAppointmentUpdatedByConcierge::class);
        }
        return $purchase;
    }

    private function process(string $queue, string $scenario, string $run, bool $start = false): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/LaboratoryConfirmationWorker.php'), $queue, $scenario, $run], base_path());
        $process->setTimeout(30);
        if ($start) { $process->start(); } else { $process->run(); $this->successful($process); }
        return $process;
    }

    private function successful(Process $process): void
    {
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringNotContainsString('AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML', $process->getErrorOutput().$process->getOutput());
    }

    private function waitFor(string $run, string $event, int $count = 1): void
    {
        $until = microtime(true) + 20;
        while (DB::table('confirmation_worker_audit')->where(compact('run', 'event'))->count() < $count) {
            if (microtime(true) > $until) { $this->fail('Independent worker barrier timed out'); }
            usleep(20000);
        }
    }

    #[Test]
    public function auto_uses_independent_preparation_and_confirmation_workers(): void
    {
        $purchase = $this->purchase(true);
        Notification::assertNotSentTo($purchase->customer->user, \App\Notifications\LaboratoryPurchaseCreated::class);
        $intent = Confirmation::where('laboratory_purchase_id', $purchase->id)->sole();
        $this->assertSame(15, (int) $intent->deadline_at->diffInSeconds($intent->confirmed_at, true));
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laboratory-preparation')->count());
        $run = (string) Str::uuid();
        $this->process('laboratory-confirmations', 'normal', $run);
        $this->assertSame('waiting', $intent->fresh()->status);
        $this->process('laboratory-preparation', 'normal', $run);
        $this->process('laboratory-confirmations', 'normal', $run);
        $this->assertSame('accepted', $intent->fresh()->status);
        $this->assertSame('auto', $intent->fresh()->content_kind);
        $this->assertStringContainsString('QA_AUTO_VALIDADO', $intent->fresh()->payload['html']);
        $this->assertSame(1, DB::table('confirmation_worker_audit')->where('intent_id', $intent->id)->where('event', 'send')->count());
        $this->assertSame(1, \App\Models\InAppNotification::where('user_id', $purchase->customer->user_id)->count());
    }

    #[Test]
    public function blocked_generation_does_not_block_originals_and_late_auto_sends_no_second_mail(): void
    {
        $purchase = $this->purchase(); $intent = Confirmation::where('laboratory_purchase_id', $purchase->id)->sole();
        $run = (string) Str::uuid(); $generator = $this->process('laboratory-preparation', 'blocked', $run, true);
        try {
            $this->waitFor($run, 'generation_started');
            $this->assertTrue($generator->isRunning());
            $intent->update(['deadline_at' => now()->subSecond()]);
            $this->process('laboratory-confirmations', 'normal', $run);
            $this->assertSame('accepted', $intent->fresh()->status);
            $this->assertSame('original', $intent->fresh()->content_kind);
            $fixed = $intent->fresh()->payload;
            DB::table('confirmation_worker_audit')->insert(['run' => $run, 'event' => 'release_generation', 'created_at' => now()]);
            $generator->wait(); $this->successful($generator);
            $this->process('laboratory-confirmations', 'normal', $run);
            $this->assertSame($fixed, $intent->fresh()->payload);
            $this->assertSame(1, DB::table('confirmation_worker_audit')->where('intent_id', $intent->id)->where('event', 'send')->count());
        } finally { if ($generator->isRunning()) { $generator->stop(); } }
    }

    #[Test]
    public function concurrent_workers_claim_a_single_snapshot_delivery(): void
    {
        config(['services.laboratory_preparation.deterministic_v3_enabled' => false]);
        $purchase = $this->purchase(); $intent = Confirmation::where('laboratory_purchase_id', $purchase->id)->sole();
        $intent->update(['deadline_at' => now()->subSecond()]);
        app(LaboratoryConfirmationCoordinator::class)->dispatch($intent);
        $run = (string) Str::uuid();
        $workers = [$this->process('laboratory-confirmations', 'race', $run, true), $this->process('laboratory-confirmations', 'race', $run, true)];
        try {
            $this->waitFor($run, 'ready', 2);
            DB::table('confirmation_worker_audit')->insert(['run' => $run, 'event' => 'start', 'created_at' => now()]);
            foreach ($workers as $worker) { $worker->wait(); $this->successful($worker); }
            $this->assertSame('accepted', $intent->fresh()->status);
            $this->assertSame(1, $intent->fresh()->attempts);
            $this->assertSame(1, DB::table('confirmation_worker_audit')->where('intent_id', $intent->id)->where('event', 'send')->count());
        } finally { foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(); } } }
    }

    #[Test]
    public function interrupted_transport_is_ambiguous_and_reconciler_does_not_send_again(): void
    {
        $purchase = $this->purchase(); $intent = Confirmation::where('laboratory_purchase_id', $purchase->id)->sole();
        $intent->update(['deadline_at' => now()->subSecond()]); $run = (string) Str::uuid();
        $this->process('laboratory-confirmations', 'crash', $run);
        $this->assertSame('sending', $intent->fresh()->status);
        $intent->fresh()->update(['lease_until' => now()->subSecond()]);
        app(LaboratoryConfirmationCoordinator::class)->reconcile();
        $this->assertSame('ambiguous', $intent->fresh()->status);
        $this->process('laboratory-confirmations', 'normal', $run);
        $this->assertSame(1, DB::table('confirmation_worker_audit')->where('intent_id', $intent->id)->where('event', 'send')->count());
    }

    #[Test]
    public function pause_and_reconciler_recovery_keep_the_same_intent(): void
    {
        $purchase = $this->purchase(); $intent = Confirmation::where('laboratory_purchase_id', $purchase->id)->sole();
        $intent->update(['deadline_at' => now()->subSecond()]); $run = (string) Str::uuid();
        $this->process('laboratory-confirmations', 'paused', $run);
        $this->assertSame('waiting', $intent->fresh()->status);
        DB::table('jobs')->delete(); // Simulate lost scheduler/dispatch, solely in this private test database.
        app(LaboratoryConfirmationCoordinator::class)->reconcile();
        $this->process('laboratory-confirmations', 'normal', $run);
        $this->assertSame('accepted', $intent->fresh()->status);
        $this->assertSame(1, Confirmation::where('laboratory_purchase_id', $purchase->id)->count());
    }

    #[Test]
    public function uncertain_gda_creates_no_confirmation_or_generation_jobs(): void
    {
        $purchase = $this->purchase(uncertain: true);
        $this->assertSame('uncertain', $purchase->fresh()->gda_status->value);
        $this->assertSame(0, Confirmation::where('laboratory_purchase_id', $purchase->id)->count());
        $this->assertSame(0, DB::table('jobs')->whereIn('queue', ['laboratory-confirmations', 'laboratory-preparation'])->count());
        Notification::assertNothingSent();
    }
    #[Test]
    public function coordinator_resolution_failure_cannot_fail_or_refund_confirmed_purchase(): void
    {
        app()->bind(LaboratoryConfirmationCoordinator::class, fn () => throw new \RuntimeException('AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML'));
        $purchase = $this->purchase();
        $this->assertSame('confirmed', $purchase->fresh()->gda_status->value);
        $this->assertSame(1, $purchase->transactions()->count());
        $this->assertSame(0, Confirmation::where('laboratory_purchase_id', $purchase->id)->count());
        Notification::assertNotSentTo($purchase->customer->user, \App\Notifications\LaboratoryPurchaseCreated::class);
    }

}
