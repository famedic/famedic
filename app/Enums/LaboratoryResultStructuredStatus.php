<?php

namespace App\Enums;

enum LaboratoryResultStructuredStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Published = 'published';
    case Superseded = 'superseded';
}
