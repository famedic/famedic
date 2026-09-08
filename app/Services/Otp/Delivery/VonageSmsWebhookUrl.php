<?php

namespace App\Services\Otp\Delivery;

final class VonageSmsWebhookUrl
{
    public static function deliveryReceiptCallback(): ?string
    {
        if (! (bool) config('vonage.sms_dlr.enabled', false)) {
            return null;
        }

        $token = trim((string) config('vonage.sms_dlr.webhook_token'));
        if ($token === '') {
            return null;
        }

        return url('/webhooks/vonage/sms/delivery/'.$token);
    }
}
