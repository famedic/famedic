<?php

namespace App\Enums;

enum LaboratoryResultStatus: string
{
    case NotAvailable = 'not_available';
    case AvailableUnchecked = 'available_unchecked';
    case PendingInterpretation = 'pending_interpretation';
    case Complete = 'complete';
    case Error = 'error';
    case ManualReview = 'manual_review';
}
