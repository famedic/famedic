<?php

namespace App\Enums;

enum LaboratoryResultObservationValueType: string
{
    case Numeric = 'numeric';
    case Qualitative = 'qualitative';
    case Comment = 'comment';
    case Unknown = 'unknown';
}
