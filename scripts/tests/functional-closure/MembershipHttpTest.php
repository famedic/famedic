<?php

namespace Famedic\Integral;

use App\Enums\MedicalSubscriptionType;
use App\Models\MedicalAttentionSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Existing customer PAT compatibility, NOT a verified ATIX/native client identity. */
final class MembershipHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        config()->set('famedic.medical_attention_subscription_price_cents', 30000);
    }

    private function actor(): array
    {
        $u = User::factory()->withRegularCustomer()->create();

        return [$u, $u->createToken('synthetic-membership')->plainTextToken];
    }

    private function payload(): array
    {
        return ['service_line' => 'medical_services', 'item_type' => 'membership', 'membership_plan_code' => 'basic_annual', 'quantity' => 1];
    }

    #[Test]
    public function existing_customer_pat_can_add_and_remove_basic_without_subscription_or_payment(): void
    {
        [$u,$t] = $this->actor();
        $this->switchApiBearerToken($t);
        $this->postJson('/api/v1/cart/items', $this->payload())->assertCreated()->assertJsonPath('data.totals.total_cents', 30000);
        $item = \App\Models\MedicalServiceCartItem::sole();
        $this->deleteJson('/api/v1/cart/items/'.$item->id)->assertOk()->assertJsonPath('data.totals.total_cents', 0);
        $this->assertSame(0, MedicalAttentionSubscription::count());
        $this->assertSame(0, \App\Models\AkubicaCheckoutLink::count());
        $this->assertSame(0, Http::recorded()->count());
    }

    #[Test]
    public function duplicate_membership_returns_conflict_without_duplicate_item(): void
    {
        [$u,$t] = $this->actor();
        $this->switchApiBearerToken($t);
        $this->postJson('/api/v1/cart/items', $this->payload())->assertCreated();
        $this->postJson('/api/v1/cart/items', $this->payload())->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_ALREADY_IN_CART');
        $this->assertSame(1, \App\Models\MedicalServiceCartItem::count());
    }

    #[Test]
    public function individual_and_sponsored_active_reject_new_membership(): void
    {
        foreach ([MedicalSubscriptionType::REGULAR, MedicalSubscriptionType::INSTITUTIONAL] as $type) {
            [$u,$t] = $this->actor();
            MedicalAttentionSubscription::withoutEvents(fn () => MedicalAttentionSubscription::create(['customer_id' => $u->customer->id, 'type' => $type, 'price_cents' => 30000, 'start_date' => now()->subDay(), 'end_date' => now()->addYear()]));
            $this->switchApiBearerToken($t);
            $this->postJson('/api/v1/cart/items', $this->payload())->assertStatus(409)->assertJsonPath('error.code', $type === MedicalSubscriptionType::REGULAR ? 'MEMBERSHIP_ALREADY_ACTIVE' : 'SPONSORED_MEMBERSHIP_ACTIVE');
        }
        $this->assertSame(0, \App\Models\MedicalServiceCartItem::count());
    }

    #[Test]
    public function another_user_cannot_read_or_remove_membership_in_owner_cart(): void
    {
        [$a,$ta] = $this->actor();
        [$b,$tb] = $this->actor();
        $this->switchApiBearerToken($ta);
        $before = $this->postJson('/api/v1/cart/items', $this->payload())->assertCreated()->json('data');
        $item = \App\Models\MedicalServiceCartItem::sole();
        $this->switchApiBearerToken($tb);
        $this->getJson('/api/v1/cart?service_line=medical_services')->assertOk()->assertJsonCount(0, 'data.items');
        $this->deleteJson('/api/v1/cart/items/'.$item->id)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->switchApiBearerToken($ta);
        $this->getJson('/api/v1/cart?service_line=medical_services')->assertOk()->assertJsonPath('data.items.0.id', $item->id)->assertJsonPath('data.totals.total_cents', 30000);
    }

    #[Test]
    public function no_bearer_cannot_add_membership(): void
    {
        $this->postJson('/api/v1/cart/items', $this->payload())->assertUnauthorized();
        $this->assertSame(0,\App\Models\MedicalServiceCartItem::count());
    }
}
