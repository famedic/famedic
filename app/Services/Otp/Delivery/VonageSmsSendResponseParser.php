<?php

namespace App\Services\Otp\Delivery;

use Vonage\SMS\Collection;

/**
 * Parses Vonage SMS API send responses (client-core 4.x).
 *
 * @see vendor/vonage/client-core/src/SMS/Collection.php
 * @see vendor/vonage/client-core/src/SMS/SentSMS.php
 */
final class VonageSmsSendResponseParser
{
    public static function extractMessageId(mixed $response): ?string
    {
        return self::parse($response)->messageId;
    }

    public static function parse(mixed $response): VonageSmsSendParseResult
    {
        if (! $response instanceof Collection || $response->count() < 1) {
            return new VonageSmsSendParseResult(
                accepted: false,
                messageId: null,
                vonageStatus: null,
                errorText: 'empty_or_invalid_response',
                interpretable: false,
            );
        }

        $first = $response->current();
        $status = $first->getStatus();
        $messageId = $first->getMessageId();
        $messageId = is_string($messageId) && $messageId !== '' ? $messageId : null;

        $raw = $response->getAllMessagesRaw();
        $errorText = null;
        if (isset($raw['messages'][0]) && is_array($raw['messages'][0])) {
            $errorText = isset($raw['messages'][0]['error-text'])
                ? (string) $raw['messages'][0]['error-text']
                : null;
        }

        return new VonageSmsSendParseResult(
            accepted: $status === 0,
            messageId: $messageId,
            vonageStatus: $status,
            errorText: $errorText,
        );
    }
}
