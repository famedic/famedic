<?php

namespace App\Enums;

enum InvoiceRequestStatusLogTrigger: string
{
    case Created = 'created';
    case SampleWebhook = 'sample_webhook';
    case ResultAvailable = 'result_available';
    case AdminManual = 'admin_manual';
    case Reconciliation = 'reconciliation';

    public function isActivationTrigger(): bool
    {
        return $this !== self::Created;
    }
}
