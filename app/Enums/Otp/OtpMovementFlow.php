<?php

namespace App\Enums\Otp;

enum OtpMovementFlow: string
{
    case AkubicaLogin = 'akubica_login';
    case AkubicaRegister = 'akubica_register';
    case StepUpResults = 'step_up_results';
    case StepUpInvoices = 'step_up_invoices';

    public function label(): string
    {
        return match ($this) {
            self::AkubicaLogin => 'Login Akúbica',
            self::AkubicaRegister => 'Registro Akúbica',
            self::StepUpResults => 'Step-up resultados',
            self::StepUpInvoices => 'Step-up facturas',
        };
    }

    public static function fromPurpose(string $purpose): ?self
    {
        return self::tryFrom($purpose);
    }
}
