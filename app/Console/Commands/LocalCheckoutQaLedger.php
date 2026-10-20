<?php

namespace App\Console\Commands;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseConfirmation;
use App\Support\LocalCheckoutQa;
use Illuminate\Console\Command;

class LocalCheckoutQaLedger extends Command
{
    protected $signature = 'local-qa:ledger {purchase}';
    protected $description = 'Read only QA confirmation delivery metadata and snapshot digests';
    public function handle(): int
    {
        if (! LocalCheckoutQa::enabled()) { return self::FAILURE; }
        $purchase = LaboratoryPurchase::findOrFail($this->argument('purchase'));
        if (! LocalCheckoutQa::owns($purchase)) { return self::FAILURE; }
        $rows = LaboratoryPurchaseConfirmation::where('laboratory_purchase_id', $purchase->id)->get()->map(function ($intent) {
            $payload = $intent->payload ?? [];
            return [...$intent->only(['id', 'laboratory_purchase_id', 'event_type', 'status', 'content_kind', 'snapshot_version',
                'attempts', 'transport_status', 'transport_message_id', 'error_code', 'accepted_at', 'deadline_at']),
                'html_sha256' => isset($payload['html']) ? hash('sha256', $payload['html']) : null,
                'text_sha256' => isset($payload['text']) ? hash('sha256', $payload['text']) : null];
        });
        $this->line($rows->toJson(JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
