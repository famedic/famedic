<?php

namespace App\Enums;

enum LaboratoryResultExtractionQaDocumentCategory: string
{
    case HighOverlap = 'HIGH_OVERLAP';
    case PartialOverlap = 'PARTIAL_OVERLAP';
    case Conflict = 'CONFLICT';
    case VisionOnly = 'VISION_ONLY';
    case TextOnly = 'TEXT_ONLY';
    case NoComparable = 'NO_COMPARABLE';
}
