<?php

namespace App\Enums;

enum LaboratoryStructuredResultPromotionStatus: string
{
    case Shadow = 'shadow';
    case Validated = 'validated';
    case NeedsReview = 'needs_review';
    case Rejected = 'rejected';
}
