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
        'api.v1.orders.index' => 'api:operation:api:v1:orders:index',
        // RC2 Batch 2 · A1 (rev7 L07, L13b): literal grants, same capability names as the native-only gate.
        'api.v1.orders.status' => 'api:operation:api:v1:orders:status',
        'api.v1.orders.products' => 'api:operation:api:v1:orders:products',
        'api.v1.user.tax-profiles' => 'api:operation:api:v1:user:tax-profiles',
        'api.v1.orders.results.index' => 'api:operation:api:v1:orders:results:index',
        'api.v1.orders.results' => 'api:operation:api:v1:orders:results',
        'api.v1.orders.invoices.index' => 'api:operation:api:v1:orders:invoices:index',
        'api.v1.orders.invoices' => 'api:operation:api:v1:orders:invoices',
        'api.v1.user.family' => 'api:operation:api:v1:user:family',
        // RC2 Batch 2 · A3 (rev7 L10, L13, L14): step-up, secure links and invoice request; same literal names as the
        // native-only gate, so native keeps its capability. Downloads stay out of this list (native-only, Leo 403).
        'api.v1.orders.results.step-up.request' => 'api:operation:api:v1:orders:results:step-up:request',
        'api.v1.orders.results.step-up.verify' => 'api:operation:api:v1:orders:results:step-up:verify',
        'api.v1.orders.results.secure-link' => 'api:operation:api:v1:orders:results:secure-link',
        'api.v1.orders.invoices.step-up.request' => 'api:operation:api:v1:orders:invoices:step-up:request',
        'api.v1.orders.invoices.step-up.verify' => 'api:operation:api:v1:orders:invoices:step-up:verify',
        'api.v1.orders.invoices.secure-link' => 'api:operation:api:v1:orders:invoices:secure-link',
        'api.v1.orders.invoice-request.status' => 'api:operation:api:v1:orders:invoice-request:status',
        'api.v1.orders.invoice-request.store' => 'api:operation:api:v1:orders:invoice-request:store',
        'api.v1.laboratory-appointments.requirements' => 'api:operation:api:v1:laboratory-appointments:requirements',
        'api.v1.laboratory-appointments.index' => 'api:operation:api:v1:laboratory-appointments:index',
        'api.v1.cart.medical-benefit.show' => 'legacy-benefits:read',
        'api.v1.cart.medical-benefit.apply' => 'legacy-benefits:write',
        'api.v1.cart.medical-benefit.remove' => 'legacy-benefits:write',
        'api.v1.cart.coupon.show' => 'legacy-benefits:read',
        'api.v1.cart.coupon.apply' => 'legacy-benefits:write',
        'api.v1.cart.coupon.remove' => 'legacy-benefits:write',
    ];

    /**
     * RC2 Batch 2 · A3: rev7 request bodies with additionalProperties=false, enforced for Leo before any effect
     * (empty step-up request, SecureLinkIssueRequest, LeoInvoiceRequestCreate). Native validation is unchanged.
     */
    public const LEO_A3_BODY_KEYS = [
        'api.v1.orders.results.step-up.request' => [],
        'api.v1.orders.invoices.step-up.request' => [],
        'api.v1.orders.results.secure-link' => ['grant_id'],
        'api.v1.orders.invoices.secure-link' => ['grant_id'],
        'api.v1.orders.invoice-request.store' => ['tax_profile_id', 'cfdi_use'],
    ];

    /** Request attribute set only after a Leo laboratory request passed capability, brand and ownership checks. */
    public const LEO_LABORATORY_BRAND = 'rc2.leo_laboratory_brand';

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
        // RC2 B22 (D4): pharmacy purchases are suspended; typed rejection before any capability or cart effect.
        if ($request->route()?->getName() === 'api.v1.cart.items.store' && \App\Support\Api\V1\PharmacySuspension::requested($request)) {
            return \App\Support\Api\V1\PharmacySuspension::response();
        }
        $identity = $this->identities->resolve($request, $user, $user->currentAccessToken());
        // RC2 Batch 2 · A2 (rev7 L06): Leo laboratory cart, bound to line laboratory and to a granted brand.
        $laboratory = $identity->context?->consumerKind === 'leo' ? self::leoLaboratoryRequest($request) : null;
        if ($laboratory !== null) {
            $denied = $this->leoLaboratoryDenial($request, $user, $identity, $laboratory);

            return $denied ?? $this->proceed($request, $next, $user, $identity);
        }
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
        if ($identity->context?->consumerKind === 'leo' && array_key_exists($request->route()?->getName(), self::LEO_A3_BODY_KEYS)) {
            $unknown = array_diff(array_keys($request->all()), self::LEO_A3_BODY_KEYS[$request->route()->getName()]);
            if ($unknown !== []) {
                return ApiResponse::error('VALIDATION_ERROR', 'Los datos enviados no son válidos.', 422,
                    array_fill_keys(array_values($unknown), ['El campo no está permitido.']));
            }
        }
        if ($operation === 'cart:membership:write' && $request->isMethod('DELETE')) {
            // RC2 B21 (D3): the membership-only grant is pinned to medical_services; a laboratory
            // discriminator (or any other line) never widens it, even when ids collide across tables.
            $line = $request->query('service_line');
            if ($request->query('brand') !== null || ($line !== null && $line !== 'medical_services')) {
                return ApiResponse::error('CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403);
            }
            $item = \App\Models\MedicalServiceCartItem::query()->with('cart')->find($request->route('cart_item_id'));
            if (! $item || (int) $item->cart->customer_id !== (int) $user->customer?->id) {
                return ApiResponse::error('CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403);
            }
            $request->query->set('service_line', 'medical_services');
        }

        return $this->proceed($request, $next, $user, $identity);
    }

    private function proceed(Request $request, Closure $next, \App\Models\User $user, mixed $identity): mixed
    {
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

    /**
     * Leo laboratory requests (rev7 L06). Native requests never reach this branch and keep their behaviour.
     *
     * @return array{brand: mixed, operations: list<string>}|null
     */
    private static function leoLaboratoryRequest(Request $request): ?array
    {
        $name = $request->route()?->getName();
        if ($request->isMethod('GET') && $request->query('service_line') === 'laboratory'
            && in_array($name, ['api.v1.cart.index', 'api.v1.cart.totals', 'api.v1.checkout.prepare'], true)) {
            return ['brand' => $request->query('brand'),
                'operations' => $name === 'api.v1.checkout.prepare' ? ['checkout:prepare', 'cart:laboratory:read'] : ['cart:laboratory:read']];
        }
        if ($name === 'api.v1.cart.items.store' && $request->isMethod('POST') && $request->input('item_type') === null
            && in_array($request->input('service_line'), [null, 'laboratory'], true)
            && ($request->has('brand') || $request->has('laboratory_test_id'))) {
            return ['brand' => $request->input('brand'), 'operations' => ['cart:laboratory:write']];
        }
        if ($name === 'api.v1.cart.items.destroy' && $request->isMethod('DELETE')
            && ($request->query('service_line') === 'laboratory' || ($request->query('service_line') === null && $request->query('brand') !== null))) {
            return ['brand' => $request->query('brand'), 'operations' => ['cart:laboratory:write']];
        }

        return null;
    }

    /** Authorization before validation; every refusal happens before any cart effect. */
    private function leoLaboratoryDenial(Request $request, \App\Models\User $user, mixed $identity, array $laboratory): ?\Illuminate\Http\JsonResponse
    {
        $forbidden = fn () => ApiResponse::error('CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403);
        foreach ($laboratory['operations'] as $operation) {
            if ($this->policy->denial($user, $user->currentAccessToken(), $identity, $operation, now()->timestamp) !== null) {
                return $forbidden();
            }
        }
        $brand = $laboratory['brand'];
        if (! is_string($brand) || \App\Enums\LaboratoryBrand::tryFrom($brand) === null) {
            return ApiResponse::error('VALIDATION_ERROR', 'Los datos enviados no son válidos.', 422, ['brand' => ['La marca de laboratorio no es válida.']]);
        }
        // The binding grants the laboratory capability only for the brands it lists (fail closed).
        if (! $identity->context->grantsLaboratoryBrand($brand)) {
            return $forbidden();
        }
        if ($request->isMethod('POST')) {
            // LaboratoryCartItemCreateLegacy: additionalProperties false; prices are always computed by the server.
            $unknown = array_diff(array_keys($request->all()), ['laboratory_test_id', 'brand']);
            if ($unknown !== []) {
                return ApiResponse::error('VALIDATION_ERROR', 'Los datos enviados no son válidos.', 422,
                    array_fill_keys(array_values($unknown), ['El campo no está permitido.']));
            }
        }
        if ($request->isMethod('DELETE')) {
            // Only an own laboratory item of the same brand; foreign, missing or other-brand items are refused alike.
            // An own item already removed still passes, so an idempotent retry replays and a plain retry gets 404.
            $item = \App\Models\LaboratoryCartItem::withTrashed()->with('laboratoryTest')->find($request->route('cart_item_id'));
            if (! $item || (int) $item->customer_id !== (int) $user->customer->id || $item->laboratoryTest?->brand?->value !== $brand) {
                return $forbidden();
            }
        }
        // The legacy runtime selects the laboratory cart by brand; the line discriminator was verified above.
        $request->query->remove('service_line');
        $request->attributes->set(self::LEO_LABORATORY_BRAND, $brand);

        return null;
    }
}
