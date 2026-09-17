<?php

namespace App\Enums;

enum LaboratoryResultPdfClassification: string
{
    case PendingInterpretation = 'pending_interpretation';
    case Complete = 'complete';
    case Unknown = 'unknown';
}
