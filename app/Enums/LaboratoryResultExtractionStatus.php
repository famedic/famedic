<?php

namespace App\Enums;

enum LaboratoryResultExtractionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Extracted = 'extracted';
    case Partial = 'partial';
    case Failed = 'failed';
    case ManualReview = 'manual_review';
}
