<?php

namespace App\Enums;

enum LaboratoryAnalyteAliasSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case AiSuggested = 'ai_suggested';
}
