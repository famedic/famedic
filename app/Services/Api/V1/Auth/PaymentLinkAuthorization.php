<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\ConsumerIdentityResolver;
use App\Exceptions\PaymentLinkAuthorizationDenied;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Support\Api\V1\AkubicaCorrelationId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class PaymentLinkAuthorization
{
    public function __construct(
        private readonly BearerPatAuthenticator $authentication,
        private readonly ConsumerIdentityResolver $identities,
        private readonly PaymentLinkAccessPolicy $policy,
    ) {}

    public function assertAllowed(Request $request, ?Customer $target = null): void
    {
        $user = $this->authentication->authenticate($request);
        if ($user === null) {
            $this->deny($request, 'UNAUTHENTICATED', 401);
        }
        if ($user->customer === null || ($target !== null && (int) $target->id !== (int) $user->customer->id)) {
            $this->deny($request, 'FORBIDDEN', 403);
        }
        $token = $user->currentAccessToken();
        $identity = $this->identities->resolve($request, $user, $token);
        $denial = $this->policy->denial($user, $token, $identity, now()->timestamp);
        if ($denial !== null) {
            $this->deny($request, $denial, 403);
        }
    }

    private function deny(Request $request, string $code, int $status): never
    {
        Log::notice('api_v1_payment_link_denied', [
            'operation' => 'checkoutPaymentLink', 'error_code' => $code,
            'correlation_id' => AkubicaCorrelationId::fromRequest($request),
        ]);
        throw new PaymentLinkAuthorizationDenied(ApiResponse::error(
            $code, $status === 401 ? 'No autenticado.' : 'Operación no autorizada.', $status,
        ));
    }
}
