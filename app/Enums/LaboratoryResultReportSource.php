<?php

namespace App\Enums;

enum LaboratoryResultReportSource: string
{
    case Gda = 'gda';
    case ManualAdmin = 'manual_admin';
    case PatientUpload = 'patient_upload';
}
