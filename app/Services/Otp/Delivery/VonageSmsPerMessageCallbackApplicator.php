<?php

namespace App\Services\Otp\Delivery;

use Vonage\SMS\Message\SMS;

/**
 * Applies per-message DLR callback using Vonage SDK 4.11.2 OutboundMessage API.
 *
 * @see vendor/vonage/client-core/src/SMS/Message/OutboundMessage.php
 *      setDeliveryReceiptCallback() → appendUniversalOptions() → key `callback`
 */
final class VonageSmsPerMessageCallbackApplicator
{
    public static function apply(SMS $sms): VonageSmsCallbackApplyResult
    {
        if (! (bool) config('vonage.sms_dlr.enabled', false)) {
            return new VonageSmsCallbackApplyResult(applied: false, skipReason: 'dlr_disabled');
        }

        if (config('vonage.sms_dlr.callback_mode', 'per_message') !== 'per_message') {
            return new VonageSmsCallbackApplyResult(applied: false, skipReason: 'callback_mode_global');
        }

        $url = VonageSmsWebhookUrl::deliveryReceiptCallback();
        if ($url === null) {
            return new VonageSmsCallbackApplyResult(applied: false, skipReason: 'callback_url_unavailable');
        }

        // SDK 4.11.2: mutates $sms, returns $this, serializes as `callback` in toArray().
        $sms->setDeliveryReceiptCallback($url);

        return new VonageSmsCallbackApplyResult(
            applied: true,
            callbackHost: parse_url($url, PHP_URL_HOST) ?: null,
            callbackUrlLength: strlen($url),
        );
    }
}
