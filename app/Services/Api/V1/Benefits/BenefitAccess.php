<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

use App\Contracts\Api\V1\Auth\ConsumerIdentityResolver;
use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Domain\Akubica\Benefits\BenefitsAuthorizer;
use App\Services\Api\V1\Auth\BearerPatAuthenticator;
use App\Services\Api\V1\Auth\ConsumerCapabilityPolicy;
use Illuminate\Http\Request;

final class BenefitAccess
{
    public function __construct(private BenefitApprovals $approvals, private BearerPatAuthenticator $pat, private ConsumerIdentityResolver $identity, private ConsumerCapabilityPolicy $policy) {}

    public function actor(Request $request, string $capability): BenefitActor
    {
        if (! $this->approvals->api()) {
            throw new BenefitFailure('BENEFITS_NOT_ENABLED');
        }
        $user = $this->pat->authenticate($request);
        if (! $user) {
            throw new BenefitFailure('UNAUTHENTICATED');
        }
        if (! $user->customer) {
            throw new BenefitFailure('FORBIDDEN');
        }
        $identity = $this->identity->resolve($request, $user, $user->currentAccessToken());
        if ($this->policy->denial($user, $user->currentAccessToken(), $identity, $capability, now()->timestamp) !== null) {
            throw new BenefitFailure('CONSUMER_NOT_AUTHORIZED');
        }
        $c = $identity->context;

        return new BenefitActor((int) $user->id, $c->consumerId, $c->delegationId, $c->channelId);
    }

    public function authorizer(Request $request): BenefitsAuthorizer
    {
        return new class($this, $request) implements BenefitsAuthorizer
        {
            public function __construct(private BenefitAccess $access, private Request $request) {}

            public function authorize(BenefitActor $actor, string $operation, int $now): void
            {
                if (! in_array($operation, ['benefits:read', 'benefits:apply', 'benefits:remove'], true)) {
                    throw new BenefitFailure('FINANCIAL_WRITES_PENDING_APPROVAL');
                }
                if ($actor->namespace() !== $this->access->actor($this->request, $operation)->namespace()) {
                    throw new BenefitFailure('CONSUMER_NOT_AUTHORIZED');
                }
            }
        };
    }
}
