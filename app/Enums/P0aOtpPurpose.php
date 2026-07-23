<?php

namespace App\Enums;

enum P0aOtpPurpose: string
{
    case AkubicaLogin = 'akubica_login';
    case AkubicaRegister = 'akubica_register';
    case StepUpResults = 'step_up_results';
    case StepUpInvoices = 'step_up_invoices';
}
