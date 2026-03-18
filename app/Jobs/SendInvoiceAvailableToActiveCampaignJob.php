<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendInvoiceAvailableToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Invoice $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        $invoiceable = $this->invoice->invoiceable;

        if (! $invoiceable || ! method_exists($invoiceable, 'customer')) {
            return;
        }

        $email = $invoiceable->customer->user->email ?? null;

        if (! $email) {
            return;
        }

        $activeCampaignService->invoiceAvailable($email);
    }
}

