<?php

namespace App\Enums;

enum LaboratoryAnalyteValueKind: string
{
    case Numeric = 'numeric';
    case Qualitative = 'qualitative';
    case Either = 'either';
}
