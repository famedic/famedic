<?php

namespace App\Enums;

enum LaboratoryResultAbnormalSource: string
{
    case Computed = 'computed';
    case LabFlag = 'lab_flag';
    case None = 'none';
}
