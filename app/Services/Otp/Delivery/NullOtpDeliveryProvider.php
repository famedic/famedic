<?php

namespace App\Services\Otp\Delivery;

use App\Contracts\Otp\OtpDeliveryProvider;

final class NullOtpDeliveryProvider implements OtpDeliveryProvider
{
    public function send(OtpDeliveryRequest $request): OtpDeliveryResult
    {
        return new OtpDeliveryResult(
            OtpDeliveryResultClass::Suppressed,
            null,
            $request->attemptNumber,
            0,
            $this->alias(),
        );
    }

    public function alias(): string
    {
        return 'null';
    }
}
