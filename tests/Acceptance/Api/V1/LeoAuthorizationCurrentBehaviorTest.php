<?php

namespace Tests\Acceptance\Api\V1;

use App\Models\AkubicaCheckoutLink;
use App\Models\Api\V1\IdempotencyRecord;
use App\Enums\LaboratoryBrand;
use App\Actions\Api\V1\GenerateAkubicaCheckoutPaymentLinkAction;
use App\Services\Api\V1\Idempotency\IdempotencyRequestHasher;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Services\Api\V1\Auth\BearerPatAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Phase2B1EphemeralTestCase;

/** Unclassified Bearer denials. These are NOT verified native/Leo identities. */
final class LeoAuthorizationCurrentBehaviorTest extends Phase2B1EphemeralTestCase
{
    public static function abilities(): array
    {
        return ['empty' => [[]], 'read-only' => [['akubica:read']], 'wildcard' => [['*']],
            'literal-without-binding' => [['akubica:payment-link:create']]];
    }

    #[Test]
    #[DataProvider('abilities')]
    public function unclassified_bearer_is_denied_even_with_spoofed_headers(array $abilities): void
    {
        [, $token] = $this->readyUnclassifiedActor($abilities);
        $before = $this->businessSnapshot();
        $response = $this->requestLink($token, 'phase2b1-characterize', [
            'X-Consumer' => 'native', 'X-Channel' => 'trusted', 'X-Integration' => 'leo',
        ]);
        $after = $this->businessSnapshot();
        $this->observe('C01/'.($abilities[0] ?? 'empty'), $response, $before, $after);
        $this->assertDeniedWithoutEffects($response, $before, $after, 403, 'CONSUMER_NOT_AUTHORIZED');
    }

    #[Test]
    public function previous_success_receipt_and_new_key_cannot_bypass_authorization(): void
    {
        [$user, $token] = $this->readyUnclassifiedActor(['akubica:read']);
        $original = Request::create('/api/v1/checkout/payment-link', 'POST', ['brand' => 'olab']);
        $route = Route::getRoutes()->match($original);
        $original->setRouteResolver(static fn () => $route);
        $hasher = app(IdempotencyRequestHasher::class);
        $body = json_encode(['success' => true, 'data' => ['payment_link' => ['url' => 'https://synthetic.invalid/fixture']]]);
        // Historical receipt fixture, not a grant or a mocked authorization.
        $record = IdempotencyRecord::query()->create([
            'actor_key' => 'customer:'.$user->customer->id, 'method' => 'POST',
            'path' => $hasher->normalizedPath($original), 'key_hash' => hash('sha256', 'phase2b1-replay-one'),
            'request_hash' => $hasher->hash($original), 'status' => 'completed',
            'http_status' => 200, 'response_body' => $body, 'response_headers' => [],
            'correlation_id' => '11111111-1111-4111-8111-111111111111',
            'lease_expires_at' => null, 'expires_at' => now()->addDay(),
        ]);
        $before = $this->businessSnapshot();
        $second = $this->requestLink($token, 'phase2b1-replay-one', ['X-Consumer' => 'leo']);
        $after = $this->businessSnapshot();
        $this->observe('C02/persisted-replay', $second, $before, $after);
        $this->assertDeniedWithoutEffects($second, $before, $after, 403, 'CONSUMER_NOT_AUTHORIZED');
        $this->assertNull($second->headers->get('Idempotency-Replayed'));
        $this->assertSame($body, $record->fresh()->response_body);
        $third = $this->requestLink($token, 'phase2b1-replay-two');
        $this->assertDeniedWithoutEffects($third, $before, $this->businessSnapshot(), 403, 'CONSUMER_NOT_AUTHORIZED');
        $this->assertSame(0, AkubicaCheckoutLink::query()->count());
    }

    #[Test]
    public function direct_action_is_denied_before_readiness_or_business_effects(): void
    {
        [$user, $token] = $this->readyUnclassifiedActor(['akubica:read']);
        request()->headers->set('Authorization', 'Bearer '.$token);
        $before = $this->businessSnapshot();
        try {
            app(GenerateAkubicaCheckoutPaymentLinkAction::class)($user->customer, LaboratoryBrand::OLAB, 60);
            $this->fail('Direct action was not denied');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('CONSUMER_NOT_AUTHORIZED', json_decode($exception->getResponse()->getContent(), true)['error']['code']);
        }
        $this->assertSame($before['business_hash'], $this->businessSnapshot()['business_hash']);
        $this->assertSame($before['transactions_and_purchases'], $this->businessSnapshot()['transactions_and_purchases']);
        $this->assertSame($before['http_provider_calls'], $this->businessSnapshot()['http_provider_calls']);
    }

    #[Test]
    public function missing_or_invalid_idempotency_key_cannot_bypass_authorization(): void
    {
        [, $token] = $this->readyUnclassifiedActor(['akubica:read']);
        foreach ([null, 'x'] as $key) {
            Auth::forgetGuards();
            $this->flushHeaders();
            $headers = ['Authorization' => 'Bearer '.$token];
            if ($key !== null) {
                $headers['Idempotency-Key'] = $key;
            }
            $before = $this->businessSnapshot();
            $response = $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], $headers);
            $after = $this->businessSnapshot();
            $this->observe('C03/idempotency-'.($key === null ? 'missing' : 'invalid'), $response, $before, $after);
            $this->assertDeniedWithoutEffects($response, $before, $after, 403, 'CONSUMER_NOT_AUTHORIZED');
        }
    }

    #[Test]
    public function web_session_is_preserved_and_never_replaces_a_different_or_invalid_bearer(): void
    {
        [$bearerUser, $token] = $this->readyUnclassifiedActor(['akubica:read']);
        $webUser = User::factory()->create();
        config()->set('auth.defaults.guard', 'web');
        $this->post('/login', ['email' => $webUser->email, 'password' => 'password'])->assertRedirect();
        $configuration = config('sanctum.guard');
        $this->withCookie(config('session.cookie'), session()->getId());
        $before = $this->businessSnapshot();
        $invalid = $this->requestLink('999999|synthetic-invalid', 'phase2b1b-invalid-cookie');
        $this->assertDeniedWithoutEffects($invalid, $before, $this->businessSnapshot(), 401, 'UNAUTHENTICATED');
        $valid = $this->requestLink($token, 'phase2b1b-other-cookie');
        $this->assertDeniedWithoutEffects($valid, $before, $this->businessSnapshot(), 403, 'CONSUMER_NOT_AUTHORIZED');
        $probe = Request::create('/api/v1/checkout/payment-link', 'POST');
        $probe->headers->set('Authorization', 'Bearer '.$token);
        $this->assertSame($bearerUser->id, app(BearerPatAuthenticator::class)->authenticate($probe)->id);
        $this->assertSame($configuration, config('sanctum.guard'));
        $this->assertSame($webUser->id, Auth::guard('web')->id());
        $this->flushHeaders();
        $this->post('/logout')->assertRedirect();
        $this->assertFalse(Auth::guard('web')->check());
    }

    #[Test]
    public function route_scope_and_order_put_authorization_before_replay_without_changing_web_routes(): void
    {
        $router = app('router');
        $route = Route::getRoutes()->getByName('api.v1.checkout.payment-link');
        $middleware = $router->gatherRouteMiddleware($route);
        $auth = \App\Http\Middleware\Api\V1\AuthenticatePaymentLinkPat::class;
        $gate = \App\Http\Middleware\Api\V1\AuthorizePaymentLink::class;
        $replay = \App\Http\Middleware\Api\V1\EnforceIdempotencyKey::class;
        $this->assertContains($auth, $middleware);
        $this->assertContains($gate, $middleware);
        $this->assertContains($replay, $middleware);
        $this->assertLessThan(array_search($gate, $middleware, true), array_search($auth, $middleware, true));
        $this->assertLessThan(array_search($replay, $middleware, true), array_search($gate, $middleware, true));
        $this->assertNotContains(\Illuminate\Auth\Middleware\Authenticate::class.':sanctum', $middleware);
        $this->assertNotContains(\App\Http\Middleware\UseApiTokenGuard::class, $middleware);
        foreach (Route::getRoutes() as $entry) {
            if (! str_starts_with($entry->uri(), 'api/v1/')) {
                $this->assertNotContains($auth, $router->gatherRouteMiddleware($entry));
                $this->assertNotContains($gate, $router->gatherRouteMiddleware($entry));
            }
        }
        foreach (['cart.coupon.show', 'cart.coupon.apply', 'cart.coupon.remove',
            'cart.medical-benefit.show', 'cart.medical-benefit.apply', 'cart.medical-benefit.remove'] as $name) {
            $chain = $router->gatherRouteMiddleware(Route::getRoutes()->getByName('api.v1.'.$name));
            $legacy = \App\Http\Middleware\Api\V1\RestrictLeoLegacyBenefits::class;
            $this->assertContains($legacy, $chain);
            if (in_array($replay, $chain, true)) {
                $this->assertLessThan(array_search($replay, $chain, true), array_search($legacy, $chain, true));
            }
        }
    }

    #[Test]
    public function a_test_alias_without_the_route_gate_still_cannot_use_a_web_session_to_create_links(): void
    {
        [$user] = $this->readyUnclassifiedActor();
        config()->set('auth.defaults.guard', 'web');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        Auth::forgetGuards();
        $this->flushHeaders();
        config()->set('sanctum.guard', ['web']);
        // Test-only alternate entry deliberately omits the new gate to exercise
        // the real controller and action defense, never a mocked authorization.
        Route::post('/api/v1/phase2b1b-test-alias', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'paymentLink'])
            ->middleware(['auth:sanctum', 'api.customer']);
        $this->withCookie(config('session.cookie'), session()->getId());
        $before = $this->businessSnapshot();
        $response = $this->postJson('/api/v1/phase2b1b-test-alias', ['brand' => 'olab']);
        $after = $this->businessSnapshot();
        $this->observe('C04/alias-session', $response, $before, $after);
        $this->assertDeniedWithoutEffects($response, $before, $after, 401, 'UNAUTHENTICATED');
    }
}
