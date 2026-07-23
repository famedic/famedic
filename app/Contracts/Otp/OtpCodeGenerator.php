<?php

namespace App\Contracts\Otp;

interface OtpCodeGenerator
{
    /**
     * Generate a zero-padded numeric OTP code.
     */
    public function generate(int $length = 6): string;
}
