<?php

namespace App\Enums;

enum LaboratoryObservationSourcePriority: string
{
    case TextPrimary = 'text_primary';
    case VisionConfirmation = 'vision_confirmation';
    case VisionOnly = 'vision_only';
    case ConflictRequiresReview = 'conflict_requires_review';
}
