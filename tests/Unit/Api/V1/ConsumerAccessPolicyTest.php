<?php

namespace Tests\Unit\Api\V1;

use App\Models\User;
use App\Services\Api\V1\Auth\ConsumerAuthorizationContext;
use App\Services\Api\V1\Auth\ConsumerIdentityResolution;
use App\Services\Api\V1\Auth\LegacyBenefitAccessPolicy;
use App\Services\Api\V1\Auth\PaymentLinkAccessPolicy;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Pure decision inputs, NOT registered consumers, backend proofs or P01/P12 integration. */
final class ConsumerAccessPolicyTest extends TestCase
{
    public static function paymentCases(): array
    {
        return [
            'Leo wildcard' => ['leo', ['*'], [], 'LEO_PAYMENT_LINK_FORBIDDEN'],
            'Leo accidental literal' => ['leo', [PaymentLinkAccessPolicy::CAPABILITY], [], 'LEO_PAYMENT_LINK_FORBIDDEN'],
            'native literal valid policy input' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], [], null],
            'native wildcard' => ['native', ['*'], [], 'CONSUMER_NOT_AUTHORIZED'],
            'native literal plus wildcard' => ['native', ['*', PaymentLinkAccessPolicy::CAPABILITY], [], 'CONSUMER_NOT_AUTHORIZED'],
            'native read only' => ['native', ['akubica:read'], [], 'CONSUMER_NOT_AUTHORIZED'],
            'wrong user' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], ['userId' => 2], 'CONSUMER_NOT_AUTHORIZED'],
            'wrong PAT' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], ['tokenId' => 8], 'CONSUMER_NOT_AUTHORIZED'],
            'expired at boundary' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], ['expiresAt' => 100], 'CONSUMER_NOT_AUTHORIZED'],
            'revoked binding' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], ['bindingActive' => false], 'CONSUMER_NOT_AUTHORIZED'],
            'revoked consumer' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], ['consumerActive' => false], 'CONSUMER_NOT_AUTHORIZED'],
            'revoked channel' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], ['channelActive' => false], 'CONSUMER_NOT_AUTHORIZED'],
            'revoked delegation' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], ['delegationActive' => false], 'CONSUMER_NOT_AUTHORIZED'],
            'no consumer grant' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], ['consumerCapabilities' => []], 'CONSUMER_NOT_AUTHORIZED'],
            'no delegated grant' => ['native', [PaymentLinkAccessPolicy::CAPABILITY], ['delegationCapabilities' => []], 'CONSUMER_NOT_AUTHORIZED'],
            'unregistered kind' => ['other', [PaymentLinkAccessPolicy::CAPABILITY], [], 'CONSUMER_NOT_AUTHORIZED'],
        ];
    }

    private function inputs(string $kind, array $abilities, array $changes = []): array
    {
        $user = new User; $user->id = 1;
        $token = new PersonalAccessToken(['abilities' => $abilities]); $token->id = 7;
        $arguments = array_replace([
            'consumerId' => 'pure-input', 'consumerKind' => $kind,
            'channelId' => 'pure-input', 'delegationId' => 'pure-input',
            'userId' => 1, 'tokenId' => 7,
            'consumerCapabilities' => [PaymentLinkAccessPolicy::CAPABILITY],
            'delegationCapabilities' => [PaymentLinkAccessPolicy::CAPABILITY],
            'expiresAt' => 101, 'consumerActive' => true, 'channelActive' => true,
            'delegationActive' => true, 'bindingActive' => true,
        ], $changes);
        return [$user, $token, ConsumerIdentityResolution::verified(new ConsumerAuthorizationContext(...$arguments))];
    }

    #[Test]
    #[DataProvider('paymentCases')]
    public function payment_policy_has_explicit_deny_and_no_wildcard_elevation(string $kind, array $abilities, array $changes, ?string $expected): void
    {
        [$user, $token, $identity] = $this->inputs($kind, $abilities, $changes);
        $this->assertSame($expected, (new PaymentLinkAccessPolicy)->denial($user, $token, $identity, 100));
    }

    #[Test]
    public function unavailable_or_rejected_identity_never_authorizes_payments(): void
    {
        [$user, $token] = $this->inputs('native', [PaymentLinkAccessPolicy::CAPABILITY]);
        foreach ([ConsumerIdentityResolution::unavailable(), ConsumerIdentityResolution::unclassified(), ConsumerIdentityResolution::rejected()] as $identity) {
            $this->assertSame('CONSUMER_NOT_AUTHORIZED', (new PaymentLinkAccessPolicy)->denial($user, $token, $identity, 100));
        }
    }

    #[Test]
    public function legacy_policy_denies_identified_leo_and_rejected_proofs_without_blocking_unclassified_clients(): void
    {
        [$user, $token, $leo] = $this->inputs('leo', ['*']);
        $policy = new LegacyBenefitAccessPolicy;
        $this->assertSame('CONSUMER_NOT_AUTHORIZED', $policy->denial($user, $token, $leo, 100));
        $this->assertSame('CONSUMER_NOT_AUTHORIZED', $policy->denial($user, $token, ConsumerIdentityResolution::rejected(), 100));
        $this->assertNull($policy->denial($user, $token, ConsumerIdentityResolution::unavailable(), 100));
        $this->assertNull($policy->denial($user, $token, ConsumerIdentityResolution::unclassified(), 100));
    }
}
