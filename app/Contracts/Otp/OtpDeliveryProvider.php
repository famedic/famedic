<?php

namespace App\Contracts\Otp;

use App\Services\Otp\Delivery\OtpDeliveryRequest;
use App\Services\Otp\Delivery\OtpDeliveryResult;

interface OtpDeliveryProvider
{
    public function send(OtpDeliveryRequest $request): OtpDeliveryResult;

    public function alias(): string;
}
