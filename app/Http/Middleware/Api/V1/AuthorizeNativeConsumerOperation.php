<?php

namespace App\Http\Middleware\Api\V1;

use App\Contracts\Api\V1\Auth\ConsumerIdentityResolver;
use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\Auth\BearerPatAuthenticator;
use App\Services\Api\V1\Auth\ConsumerCapabilityPolicy;
use App\Services\Api\V1\Auth\ConsumerRequestScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Opt-in protection for native-only legacy operations outside the medical allowlist.
 * No new Leo permission. Transport certification requires migration of these explicit route capabilities.
 */
final class AuthorizeNativeConsumerOperation
{
    public function __construct(private BearerPatAuthenticator $pat, private ConsumerIdentityResolver $identity, private ConsumerCapabilityPolicy $policy) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (config('akubica_connectors.transport.enabled') !== true || config('akubica_connectors.transport.certified') !== true) {
            return $next($request);
        }
        ConsumerRequestScope::clear($request);
        $user = $this->pat->authenticate($request);
        if (! $user) {
            return ApiResponse::error('UNAUTHENTICATED', 'No autenticado.', 401);
        }
        $identity = $this->identity->resolve($request, $user, $user->currentAccessToken());
        $name = $request->route()?->getName();
        $operation = 'api:operation:'.str_replace('.', ':', $name ?? '');
        if (! $name || ! $user->customer || $identity->context?->consumerKind !== 'native' || $this->policy->denial($user, $user->currentAccessToken(), $identity, $operation, now()->timestamp) !== null) {
            return ApiResponse::error('CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403);
        }
        ConsumerRequestScope::bind($request, $identity->context);
        $previousResolver = $request->getUserResolver();
        $guard = Auth::guard('sanctum');
        $previousUser = $guard->user();
        $driver = Auth::getDefaultDriver();
        $sessionGuards = config('sanctum.guard');
        $guard->setUser($user);
        $request->setUserResolver(static fn ($guard = null) => $user);
        try {
            return $next($request);
        } finally {
            $guard->forgetUser();
            if ($previousUser) {
                $guard->setUser($previousUser);
            }Auth::shouldUse($driver);
            config(['sanctum.guard' => $sessionGuards]);
            $request->setUserResolver($previousResolver);
            ConsumerRequestScope::clear($request);
        }
    }
}
