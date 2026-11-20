<?php

namespace App\Support\Api\V1;

use App\Services\Api\V1\Auth\ConsumerRequestScope;
use Illuminate\Http\Request;

/** True only when AuthorizeRegisteredConsumer bound a verified Leo context to this request. */
final class LeoConsumer
{
    public static function is(Request $request): bool
    {
        return $request->attributes->get(ConsumerRequestScope::ATTRIBUTE)?->consumerKind === 'leo';
    }
}
