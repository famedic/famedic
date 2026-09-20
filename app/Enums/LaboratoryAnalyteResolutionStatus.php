<?php

namespace App\Enums;

enum LaboratoryAnalyteResolutionStatus: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
    case Ambiguous = 'ambiguous';
}
