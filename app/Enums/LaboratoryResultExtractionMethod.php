<?php

namespace App\Enums;

enum LaboratoryResultExtractionMethod: string
{
    case PdfText = 'pdf_text';
    case Vision = 'vision';
    case Hybrid = 'hybrid';
    case ManualEntry = 'manual_entry';
}
