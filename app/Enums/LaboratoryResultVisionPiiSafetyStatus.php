<?php

namespace App\Enums;

enum LaboratoryResultVisionPiiSafetyStatus: string
{
    case SafeCrop = 'SAFE_CROP';
    case SafeFullPage = 'SAFE_FULL_PAGE';
    case Unsafe = 'UNSAFE';
}
