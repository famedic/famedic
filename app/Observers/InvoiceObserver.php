<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Jobs\SendInvoiceAvailableToActiveCampaignJob;

class InvoiceObserver
{
    public function created(Invoice $invoice): void
    {
        SendInvoiceAvailableToActiveCampaignJob::dispatch($invoice);
    }
}

