<?php

namespace App\Enums;

enum LaboratoryResultReferenceStatus: string
{
    case Normal = 'normal';
    case Low = 'low';
    case High = 'high';
    case Abnormal = 'abnormal';
    case Unknown = 'unknown';
    case NotApplicable = 'not_applicable';
}
