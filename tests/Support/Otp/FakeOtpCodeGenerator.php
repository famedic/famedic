<?php

namespace Tests\Support\Otp;

use App\Contracts\Otp\OtpCodeGenerator;

class FakeOtpCodeGenerator implements OtpCodeGenerator
{
    public function __construct(
        private string $code = '001234',
    ) {
    }

    public function generate(int $length = 6): string
    {
        return str_pad(substr($this->code, -$length), $length, '0', STR_PAD_LEFT);
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }
}
