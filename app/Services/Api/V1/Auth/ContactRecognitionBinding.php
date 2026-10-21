<?php

namespace App\Services\Api\V1\Auth;

final readonly class ContactRecognitionBinding
{
    public function __construct(
        public string $conversationHash,
        public string $channelEventHash,
        public string $channel,
        public bool $personalizationAllowed,
        public string $audience,
    ) {}
}
