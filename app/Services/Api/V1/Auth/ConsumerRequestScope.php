<?php

namespace App\Services\Api\V1\Auth;

use Illuminate\Http\Request;

final class ConsumerRequestScope
{
    public const ATTRIBUTE = self::class;

    public static function bind(Request $request, ConsumerAuthorizationContext $context): void
    {
        $request->attributes->set(self::ATTRIBUTE, $context);
    }

    public static function clear(Request $request): void
    {
        $request->attributes->remove(self::ATTRIBUTE);
    }

    public static function actorKey(int $customerId, ConsumerAuthorizationContext $context): string
    {
        return 'consumer:'.hash('sha256', json_encode([$customerId, $context->userId, $context->consumerId, $context->channelId, $context->delegationId], JSON_THROW_ON_ERROR));
    }
}
