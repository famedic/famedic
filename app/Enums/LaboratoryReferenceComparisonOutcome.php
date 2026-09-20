<?php

namespace App\Enums;

enum LaboratoryReferenceComparisonOutcome: string
{
    case Match = 'match';
    case Conflict = 'conflict';
    case Unknown = 'unknown';
}
