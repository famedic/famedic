<?php

namespace App\Enums;

enum LaboratoryResultExtractionComparisonOutcome: string
{
    case Match = 'match';
    case Conflict = 'conflict';
    case VisionOnly = 'vision_only';
    case TextOnly = 'text_only';
    case Unresolved = 'unresolved';
}
