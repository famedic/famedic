<?php
declare(strict_types=1);
namespace Famedic\Phase2C1;
use App\Enums\MedicalSubscriptionType;
use App\Exceptions\{MedicalServiceCartConflictException,MedicalServiceMembershipActiveException};
use App\Models\{User,MedicalAttentionSubscription};
use App\Services\Api\V1\MedicalServiceCartService;
use Illuminate\Support\Facades\{Bus,Queue,Notification};
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
/** Business-only; no HTTP membership mutation and no authenticated ATIX simulation. */
final class MembershipBusinessTest extends TestCase
{
    protected function setUp(): void {parent::setUp();Bus::fake();Queue::fake();Notification::fake();config()->set('famedic.medical_attention_subscription_price_cents',30000);}
    #[Test]
    public function remove_membership_persists_empty_totals_without_activating_subscription(): void {
        $user=User::factory()->withRegularCustomer()->create();$s=app(MedicalServiceCartService::class);$cart=$s->addBasicPlan($user->customer);$item=$cart->items->sole();
        $removed=$s->removeItem($user->customer,$item->id);$removed=$removed->fresh();
        $this->assertSame(0,$removed->items()->count());$this->assertSame(0,$removed->subtotal_cents);$this->assertSame(0,$removed->total_cents);
        $this->assertSame(0,MedicalAttentionSubscription::count());
        try {$s->removeItem($user->customer,$item->id);$this->fail('A removed item must not silently remove another item');}
        catch (MedicalServiceCartConflictException $e) {$this->assertSame('CART_ITEM_NOT_FOUND',$e->errorCode);}
    }
    #[Test]
    public function empty_cart_remove_unknown_item_has_no_effect(): void {
        $user=User::factory()->withRegularCustomer()->create();$s=app(MedicalServiceCartService::class);$cart=$s->activeCart($user->customer);
        try {$s->removeItem($user->customer,999999);$this->fail('Unknown item');}
        catch (MedicalServiceCartConflictException $e) {$this->assertSame('CART_ITEM_NOT_FOUND',$e->errorCode);}
        $this->assertSame(0,$cart->fresh()->total_cents);
    }
    #[Test]
    public function existing_active_individual_and_sponsored_memberships_are_not_eligible(): void {
        foreach ([MedicalSubscriptionType::REGULAR,MedicalSubscriptionType::INSTITUTIONAL] as $type) {
            $user=User::factory()->withRegularCustomer()->create();MedicalAttentionSubscription::withoutEvents(fn()=>MedicalAttentionSubscription::create([
                'customer_id'=>$user->customer->id,'type'=>$type,'price_cents'=>30000,'start_date'=>now()->subDay(),'end_date'=>now()->addYear()]));
            try {app(MedicalServiceCartService::class)->addBasicPlan($user->customer);$this->fail('Membership already active');}
            catch (MedicalServiceMembershipActiveException $e) {$this->assertSame($type===MedicalSubscriptionType::REGULAR?'membership_already_active':'sponsored_membership_active',$e->reason);}
        }
    }
    #[Test]
    public function another_customer_cannot_remove_membership_item(): void {
        $owner=User::factory()->withRegularCustomer()->create();$other=User::factory()->withRegularCustomer()->create();$s=app(MedicalServiceCartService::class);
        $cart=$s->addBasicPlan($owner->customer);$item=$cart->items->sole();
        try {$s->removeItem($other->customer,$item->id);$this->fail('Cross-user removal');}
        catch (MedicalServiceCartConflictException $e) {$this->assertSame('FORBIDDEN',$e->errorCode);}
        $this->assertSame(30000,$cart->fresh()->total_cents);$this->assertSame(1,$cart->items()->count());
    }
}
