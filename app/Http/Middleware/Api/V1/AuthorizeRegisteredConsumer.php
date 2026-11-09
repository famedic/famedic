<?php

namespace App\Http\Middleware\Api\V1;

use App\Contracts\Api\V1\Auth\ConsumerIdentityResolver;
use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\Auth\BearerPatAuthenticator;
use App\Services\Api\V1\Auth\ConsumerCapabilityPolicy;
use Closure;
use Illuminate\Http\Request;

/** Closed candidate pipeline. Operational rollout requires consumer migration. */
final class AuthorizeRegisteredConsumer
{
    public const ROUTES = [
        'api.v1.memberships.status.show' => 'memberships:read',
        'api.v1.cart.clear' => 'cart:write',
        'api.v1.cart.index' => 'cart:read', 'api.v1.cart.totals' => 'cart:read',
        'api.v1.cart.items.store' => 'cart:membership:write', 'api.v1.cart.items.destroy' => 'cart:membership:write',
        'api.v1.checkout.prepare' => 'checkout:prepare',
        'api.v1.checkout.draft' => 'payments:draft',
        'api.v1.cart.medical-benefit.show' => 'legacy-benefits:read',
        'api.v1.cart.medical-benefit.apply' => 'legacy-benefits:write',
        'api.v1.cart.medical-benefit.remove' => 'legacy-benefits:write',
        'api.v1.cart.coupon.show' => 'legacy-benefits:read',
        'api.v1.cart.coupon.apply' => 'legacy-benefits:write',
        'api.v1.cart.coupon.remove' => 'legacy-benefits:write',
    ];

    public function __construct(private BearerPatAuthenticator $authentication, private ConsumerIdentityResolver $identities, private ConsumerCapabilityPolicy $policy) {}

    public function handle(Request $request, Closure $next): mixed
    {
        \App\Services\Api\V1\Auth\ConsumerRequestScope::clear($request);
        $user = $this->authentication->authenticate($request);
        if (! $user) {
            return ApiResponse::error('UNAUTHENTICATED', 'No autenticado.', 401);
        }
        if (! $user->customer) {
            return ApiResponse::error('FORBIDDEN', 'El usuario no tiene perfil de cliente asociado.', 403);
        }
        $identity = $this->identities->resolve($request, $user, $user->currentAccessToken());
        $operation = self::ROUTES[$request->route()?->getName()] ?? null;
        // Generic native cart writes require their own literal grant; medical-only grants stay narrow.
        if ($operation === 'cart:membership:write' && $identity->context?->consumerKind === 'native' && in_array('cart:write', $user->currentAccessToken()->abilities ?? [], true)) {
            $operation = 'cart:write';
        }
        if (! $operation || $this->policy->denial($user, $user->currentAccessToken(), $identity, $operation, now()->timestamp) !== null) {
            return ApiResponse::error('CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403);
        }
        // This initial membership capability never authorizes laboratory/family operations.
        if ($operation === 'cart:membership:write' && $request->isMethod('POST')
            && ($request->input('service_line') !== 'medical_services' || $request->input('item_type') !== 'membership')) {
            return ApiResponse::error('CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403);
        }
        if ($identity->context?->consumerKind === 'leo' && (str_starts_with($operation, 'cart:') || $operation === 'checkout:prepare')) {
            $cart = \App\Models\MedicalServiceCart::query()->where('customer_id', $user->customer?->id)->where('status', 'active')->first();
            if (($request->isMethod('GET') && $request->query('service_line') !== 'medical_services') || ($cart?->benefits()->exists() && ! ($request->isMethod('GET') && in_array($request->route()?->getName(), ['api.v1.cart.index', 'api.v1.checkout.prepare'], true) && \App\Services\Api\V1\Benefits\OpaqueCartContinuity::enabled()))) {
                return ApiResponse::error('CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403);
            }
        }
        if ($operation === 'cart:membership:write' && $request->isMethod('DELETE')) {
            $item = \App\Models\MedicalServiceCartItem::query()->with('cart')->find($request->route('cart_item_id'));
            if (! $item || (int) $item->cart->customer_id !== (int) $user->customer?->id) {
                return ApiResponse::error('CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403);
            }
        }
        \App\Services\Api\V1\Auth\ConsumerRequestScope::bind($request, $identity->context);
        $previous = $request->getUserResolver();
        $request->setUserResolver(static fn ($guard = null) => $user);
        try {
            return $next($request);
        } finally {
            $request->setUserResolver($previous);
            \App\Services\Api\V1\Auth\ConsumerRequestScope::clear($request);
        }
    }
}
