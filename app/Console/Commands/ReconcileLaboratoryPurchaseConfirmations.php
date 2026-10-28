<?php

namespace App\Console\Commands;

use App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator;
use Illuminate\Console\Command;

class ReconcileLaboratoryPurchaseConfirmations extends Command
{
    protected $signature = 'laboratory-confirmations:reconcile {--review : List ambiguous/failed deliveries without dispatching}';

    protected $description = 'Recover new-purchase confirmation intents without replaying fulfillment';

    public function handle(LaboratoryConfirmationCoordinator $coordinator): int
    {
        if ($this->option('review')) {
            $rows = \App\Models\LaboratoryPurchaseConfirmation::whereIn('status', ['ambiguous', 'failed'])->latest('id')->limit(100)
                ->get(['id', 'laboratory_purchase_id', 'event_type', 'requested_by', 'status', 'attempts', 'attempt_id', 'error_code'])
                ->map(fn ($row) => $row->getAttributes())->all();
            $this->table(['ID', 'Purchase', 'Event', 'Actor', 'Status', 'Attempts', 'Attempt ID', 'Error code'], $rows);

            return self::SUCCESS;
        }
        $result = $coordinator->reconcile();
        $this->info('Registered: '.$result['registered'].'; dispatched: '.$result['dispatched']);

        return self::SUCCESS;
    }
}
