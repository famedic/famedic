<?php

namespace Tests\Support\Otp;

use App\Contracts\Otp\OtpCodeGenerator;

class FakeOtpCodeGenerator implements OtpCodeGenerator
{
    /** @var list<string> */
    private array $codes;

    private int $index = 0;

    /**
     * @param  string|list<string>  $code
     */
    public function __construct(string|array $code = '001234')
    {
        $this->codes = is_array($code) ? array_values($code) : [$code];
    }

    public function generate(int $length = 6): string
    {
        $code = $this->codes[$this->index] ?? $this->codes[array_key_last($this->codes)];
        $this->index++;

        return str_pad(substr($code, -$length), $length, '0', STR_PAD_LEFT);
    }

    public function setCode(string $code): void
    {
        $this->codes = [$code];
        $this->index = 0;
    }
}
