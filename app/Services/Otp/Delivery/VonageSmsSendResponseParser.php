<?php

namespace App\Services\Otp\Delivery;

use Vonage\SMS\Collection;

/**
 * Parses message-id from Vonage SMS API send responses (client-core 4.x).
 *
 * @see vendor/vonage/client-core/src/SMS/Collection.php
 * @see vendor/vonage/client-core/src/SMS/SentSMS.php
 */
final class VonageSmsSendResponseParser
{
    public static function extractMessageId(mixed $response): ?string
    {
        if (! $response instanceof Collection) {
            return null;
        }

        if ($response->count() < 1) {
            return null;
        }

        $first = $response->current();
        $id = $first->getMessageId();

        return is_string($id) && $id !== '' ? $id : null;
    }
}
