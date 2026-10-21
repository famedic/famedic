<?php

namespace App\Services\Api\V1\Auth;

use InvalidArgumentException;

/**
 * Context supplied by the conversational channel. Raw values never leave
 * this request context and are not persisted or logged.
 */
final readonly class ContactRecognitionContext
{
    public const AUDIENCE = 'akubica_conversation';

    public function __construct(
        public string $conversationId,
        public string $channelEventId,
        public string $channel,
        public bool $personalizationAllowed,
        public string $audience = self::AUDIENCE,
    ) {
        if ($this->conversationId === '' || $this->channelEventId === '' || $this->channel === '') {
            throw new InvalidArgumentException('Contact recognition context is incomplete.');
        }
    }

    public function isPersonalizationAllowed(): bool
    {
        return $this->personalizationAllowed;
    }

    public function binding(string $appKey): ContactRecognitionBinding
    {
        return new ContactRecognitionBinding(
            conversationHash: hash_hmac('sha256', $this->conversationId, $appKey),
            channelEventHash: hash_hmac('sha256', $this->channelEventId, $appKey),
            channel: $this->channel,
            personalizationAllowed: $this->personalizationAllowed,
            audience: $this->audience,
        );
    }
}
