<?php

namespace App\Support;

class LabResultsOtp
{
    public static function required(): bool
    {
        return (bool) config('laboratory-results.otp_required', false);
    }
}
