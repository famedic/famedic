<?php

namespace App\Enums;

enum InvoiceRequestWorkflowStatus: string
{
    case AwaitingSampleCollection = 'awaiting_sample_collection';
    case SubmittedToBilling = 'submitted_to_billing';
    case Cancelled = 'cancelled';
}
