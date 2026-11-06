<?php

namespace Tests\Acceptance\Api\V1;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Phase2B1EphemeralTestCase;

/** Acceptance IDs preserved. Blocked identities MUST NOT be invented by mocks. */
final class LeoAuthorizationP01P12Test extends Phase2B1EphemeralTestCase
{
    public static function scenarios(): array
    {
        return [
            'P01' => ['P01', 'Verified Leo, ready cart: 403 LEO_PAYMENT_LINK_FORBIDDEN and zero effects'],
            'P02' => ['P02', 'Every brand and service_line manipulation: same Leo denial'],
            'P03' => ['P03', 'Leo wildcard/payment capability cannot override consumer denial'],
            'P04' => ['P04', 'Forged native headers/body cannot change verified Leo identity'],
            'P05' => ['P05', 'Deny Leo before historical or cross-consumer idempotency replay'],
            'P06' => ['P06', 'Native PAT on Leo channel: 403 CONSUMER_NOT_AUTHORIZED'],
            'P07' => ['P07', 'Unclassified old/common PAT: 403 CONSUMER_NOT_AUTHORIZED'],
            'P08' => ['P08', 'Missing/invalid/revoked/expired bearer or session only: 401 UNAUTHENTICATED'],
            'P09' => ['P09', 'Legacy benefit routes, aliases and direct action cannot bypass policy'],
            'P10' => ['P10', 'Revoked consumer/delegation cannot replay a previous success'],
            'P11' => ['P11', 'Leo administrator and empty/not-ready cart still receives Leo 403'],
            'P12' => ['P12', 'Verified native with explicit permission: 200 and one effect/replay; missing permission: 403'],
        ];
    }

    #[Test]
    #[DataProvider('scenarios')]
    public function acceptance(string $id, string $criterion): void
    {
        if ($id === 'P07') {
            // Approved policy: unclassified read-only Bearer must be denied.
            [, $token] = $this->readyUnclassifiedActor(['akubica:read']);
            $before = $this->businessSnapshot();
            $response = $this->requestLink($token, 'phase2b1-p07-new-key');
            $after = $this->businessSnapshot();
            $this->observe($id, $response, $before, $after);
            $this->assertDeniedWithoutEffects($response, $before, $after, 403, 'CONSUMER_NOT_AUTHORIZED');
            return;
        }
        if ($id === 'P08') {
            $this->unauthenticatedVariants();
            return;
        }
        // No implemented issuer/binding/transport adapter or consumer registry
        // exists. A header or mocked resolver here would fabricate the premise.
        // These cases remain incomplete, visible SKIPPED/BLOCKED, never PASS.
        $this->markTestSkipped('BLOCKED_BACKEND_IDENTITY_AND_BINDING_NOT_IMPLEMENTED: '.$id.' '.$criterion);
    }

    private function unauthenticatedVariants(): void
    {
        $user = User::factory()->withRegularCustomer()->create();
        $revoked = $user->createToken('synthetic-revoked');
        $revoked->accessToken->delete();
        $expired = $user->createToken('synthetic-expired', ['*'], now()->subMinute());
        foreach (['missing' => null, 'invalid' => '999999|synthetic-invalid',
            'revoked' => $revoked->plainTextToken, 'expired' => $expired->plainTextToken] as $name => $token) {
            $before = $this->businessSnapshot();
            $response = $this->requestLink($token, 'phase2b1-p08-'.$name);
            $after = $this->businessSnapshot();
            $this->observe('P08/'.$name, $response, $before, $after);
            $this->assertDeniedWithoutEffects($response, $before, $after, 401, 'UNAUTHENTICATED');
        }
        Auth::forgetGuards();
        $this->flushHeaders();
        // Establish a real first-party login instead of actingAs(), which
        // injects a user and can bypass the actual authentication pipeline.
        config()->set('auth.defaults.guard', 'web');
        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertSame(302, $login->getStatusCode(), 'Real synthetic web login');
        Auth::forgetGuards();
        $this->flushHeaders();
        $this->withCookie(config('session.cookie'), session()->getId());
        $before = $this->businessSnapshot();
        $response = $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab']);
        $after = $this->businessSnapshot();
        $this->observe('P08/session-only', $response, $before, $after);
        $this->assertDeniedWithoutEffects($response, $before, $after, 401, 'UNAUTHENTICATED');
    }
}
