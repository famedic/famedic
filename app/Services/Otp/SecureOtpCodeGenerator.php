<?php

namespace App\Services\Otp;

use App\Contracts\Otp\OtpCodeGenerator;
use InvalidArgumentException;

class SecureOtpCodeGenerator implements OtpCodeGenerator
{
    public function generate(int $length = 6): string
    {
        if ($length < 4 || $length > 10) {
            throw new InvalidArgumentException('OTP length must be between 4 and 10.');
        }

        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
