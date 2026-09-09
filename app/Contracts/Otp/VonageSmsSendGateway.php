<?php

namespace App\Contracts\Otp;

use Vonage\SMS\Collection;
use Vonage\SMS\Message\SMS;

interface VonageSmsSendGateway
{
    public function send(SMS $sms, string $apiKey, string $apiSecret): Collection;
}
