<?php

namespace App\Console\Commands;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseConfirmation as Confirmation;
use App\Support\LocalCheckoutQa;
use App\Support\LocalQaPreflight;
use App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator;
use Illuminate\Console\Command;

class LocalCheckoutQaReconcile extends Command
{
    protected $signature = 'local-qa:reconcile {purchase} {--fingerprint=}';
    protected $description = 'Recover only one authorized new QA purchase; never replay fulfillment';
    public function handle(LaboratoryConfirmationCoordinator $coordinator): int
    {
        $state = LocalQaPreflight::inspect(ready: true);
        if (! $state['ok'] || ! hash_equals(LocalCheckoutQa::fingerprint(), (string) $this->option('fingerprint'))) { return self::FAILURE; }
        $purchase = LaboratoryPurchase::findOrFail($this->argument('purchase'));
        if (! LocalCheckoutQa::owns($purchase) || ! $coordinator->appliesTo($purchase)) { return self::FAILURE; }
        // Recover the selected existing intent BEFORE register() dispatches its wake-up job.
        $selected = fn () => Confirmation::where('laboratory_purchase_id', $purchase->id)->where('event_type', 'purchase_confirmation');
        $selected()->where('status', 'sending')->where('lease_until', '<=', now())
            ->update(['status' => 'ambiguous', 'transport_status' => 'unknown', 'error_code' => 'worker_interrupted', 'lease_until' => null]);
        $selected()->where('status', 'preparing')->where('lease_until', '<=', now())
            ->update(['status' => 'waiting', 'lease_until' => null, 'next_attempt_at' => now(), 'error_code' => 'preparation_interrupted']);
        $intent = $coordinator->register($purchase, recovery: true);
        if (! $intent) { return self::FAILURE; }
        $this->info('Intent '.$intent->id.'; '.$intent->fresh()->status.' (ambiguous is never automatically resent)');
        return self::SUCCESS;
    }
}
