<?php

namespace Famedic\Integration;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Models\AkubicaCheckoutLink;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\LocalMachineCredentialSource;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture as Fixture;
use Tests\TestCase;

final class IntegratedAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        $this->app->bind(BackendCredentialSource::class, LocalMachineCredentialSource::class);
    }

    private function actor(string $kind = 'leo', array $caps = ['legacy-benefits:read', 'legacy-benefits:write', 'cart:read', 'cart:membership:write', 'akubica:payment-link:create']): array
    {
        return Fixture::issue(User::factory()->withRegularCustomer()->create(), $kind, $caps);
    }

    private function headers(array $a): array
    {
        return ['Authorization' => 'Bearer '.$a['token']->plainTextToken, 'X-Synthetic-Machine-Credential' => $a['machine']->plainTextToken];
    }

    #[Test]
    public function leo_is_denied_all_six_legacy_routes_before_cart_or_audit_effect(): void
    {
        $a = $this->actor();
        $before = DB::table('medical_service_carts')->count();
        foreach (['coupon', 'medical-benefit'] as $resource) {
            $this->getJson('/api/v1/cart/'.$resource.'?service_line=medical_services&brand=olab', $this->headers($a))->assertForbidden();
            $this->postJson('/api/v1/cart/'.$resource, ['service_line' => 'medical_services', 'brand' => 'olab', 'code' => 'SYNTHETIC-ONLY'], $this->headers($a))->assertForbidden();
            $this->deleteJson('/api/v1/cart/'.$resource, ['service_line' => 'medical_services', 'brand' => 'olab'], $this->headers($a))->assertForbidden();
        }
        $this->assertSame($before, MedicalServiceCart::count());
        $this->assertSame(0, AkubicaCheckoutLink::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function session_and_forged_identity_headers_are_not_api_credentials(): void
    {
        $a = $this->actor('native');
        $this->actingAs($a['user'], 'web')->getJson('/api/v1/cart/medical-benefit?service_line=medical_services', ['X-Consumer' => 'native', 'X-Consumer-Id' => '1'])->assertUnauthorized();
        $this->getJson('/api/v1/cart/medical-benefit?service_line=medical_services', ['Authorization' => 'Bearer '.$a['token']->plainTextToken, 'X-Consumer' => 'native', 'X-Synthetic-Machine-Credential' => 'public-synthetic-invalid'])->assertForbidden();
        $this->assertSame(0, MedicalServiceCart::count());
    }

    #[Test]
    public function wildcard_and_revoked_delegation_are_denied_before_mutation(): void
    {
        $a = $this->actor('native');
        $a['token']->accessToken->forceFill(['abilities' => ['*']])->save();
        $this->getJson('/api/v1/cart/medical-benefit?service_line=medical_services', $this->headers($a))->assertForbidden();
        $a['token']->accessToken->forceFill(['abilities' => ['legacy-benefits:read']])->save();
        $a['delegation']->forceFill(['enabled' => false])->save();
        $this->getJson('/api/v1/cart/medical-benefit?service_line=medical_services', $this->headers($a))->assertForbidden();
        $this->assertSame(0, MedicalServiceCart::count());
    }

    #[Test]
    public function foreign_cart_item_is_not_removed_and_leo_payment_is_denied(): void
    {
        $a = $this->actor();
        $b = $this->actor('native');
        app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($b['user']->customer);
        $item = MedicalServiceCartItem::sole();
        $this->deleteJson('/api/v1/cart/items/'.$item->id, ['service_line' => 'medical_services'], $this->headers($a))->assertForbidden();
        $this->assertNotNull($item->fresh());
        $this->postJson('/api/v1/checkout/payment-link', ['service_line' => 'medical_services'], $this->headers($a))->assertForbidden();
        $this->assertSame(0, AkubicaCheckoutLink::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function real_route_tree_contains_registered_guard_before_customer_and_idempotency(): void
    {
        foreach (\App\Http\Middleware\Api\V1\AuthorizeRegisteredConsumer::ROUTES as $name => $cap) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $list = app('router')->gatherRouteMiddleware($route);
            $guard = array_search(\App\Http\Middleware\Api\V1\AuthorizeRegisteredConsumer::class, $list, true);
            $this->assertNotFalse($guard);
            foreach ([\App\Http\Middleware\EnsureApiCustomer::class, \App\Http\Middleware\Api\V1\EnforceIdempotencyKey::class, \App\Http\Middleware\Api\V1\InitializeApiV1AuditContext::class] as $class) {
                $at = array_search($class, $list, true);
                if ($at !== false) {
                    $this->assertLessThan($at,$guard);
                }
            }
        }
    }

 #[Test]public function generic_cart_write_is_native_only_and_cannot_clear_leo_selected_benefits():void{
  $a=$this->actor('leo',['cart:write']);app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);$this->deleteJson('/api/v1/cart',['service_line'=>'medical_services'],$this->headers($a))->assertForbidden();$this->assertSame(1,MedicalServiceCartItem::count());
 }

 #[Test]public function medical_only_native_capability_does_not_grant_laboratory_cart_writes():void{
  $a=$this->actor('native',['cart:membership:write']);$this->postJson('/api/v1/cart/items',['brand'=>'olab','laboratory_test_id'=>1],$this->headers($a))->assertForbidden();$this->assertSame(0,MedicalServiceCartItem::count());Http::assertNothingSent();
 }
}
