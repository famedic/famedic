<?php
class PaymentRetryService
{
    public static function shouldRetry(string $code): bool
    {
        return in_array($code, [
            '91',
            '96',
            'timeout'
        ]);
    }
}