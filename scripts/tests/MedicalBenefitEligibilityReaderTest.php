<?php
namespace Famedic\Integral;
use App\Models\{User,Coupon,CouponUser,CouponTransaction};
use App\Services\Api\V1\Benefits\MedicalBenefitEligibilityReader;
use Illuminate\Support\Facades\{Bus,Queue,Notification,Http};
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
final class MedicalBenefitEligibilityReaderTest extends TestCase
{
 protected function setUp():void{parent::setUp();$this->assertStringStartsWith(dirname(__DIR__,2).'/app/',(new \ReflectionClass(MedicalBenefitEligibilityReader::class))->getFileName());$this->assertStringStartsWith(dirname(__DIR__,2).'/app/',(new \ReflectionClass(\App\Services\CouponApplicationService::class))->getFileName());Bus::fake();Queue::fake();Notification::fake();Http::fake();}
 private function assign($user,array $attrs=[],$used=null):Coupon{$c=Coupon::factory()->create($attrs);CouponUser::create(['coupon_id'=>$c->id,'user_id'=>$user->id,'used_at'=>$used]);return $c;}
 #[Test]public function own_coupon_and_credit_are_calculated_with_real_rules_without_consumption():void{
  $u=User::factory()->withRegularCustomer()->create();$credit=$this->assign($u,['remaining_cents'=>1500]);$coupon=$this->assign($u,['type'=>\App\Enums\CouponType::Coupon,'remaining_cents'=>500]);$before=CouponTransaction::count();
  $v=app(MedicalBenefitEligibilityReader::class)->eligible($u,1000);$this->assertCount(2,$v);$this->assertSame('credit',$v[0]->kind());$this->assertSame(1000,$v[0]->maxApplicableCents());$this->assertSame('coupon',$v[1]->kind());$this->assertSame(500,$v[1]->maxApplicableCents());
  $this->assertSame(1500,(int)$credit->fresh()->remaining_cents);$this->assertSame(500,(int)$coupon->fresh()->remaining_cents);$this->assertSame($before,CouponTransaction::count());$this->assertSame(0,CouponUser::whereNotNull('used_at')->count());$this->assertSame('{}',json_encode($v[0]));$this->assertSame(0,Http::recorded()->count());
 }
 #[Test]public function eligibility_filters_foreign_expired_future_inactive_minimum_used_and_zero():void{
  $u=User::factory()->withRegularCustomer()->create();$other=User::factory()->withRegularCustomer()->create();$this->assign($other);
  foreach([['expires_at'=>now()->subMinute()],['valid_from'=>now()->addMinute()],['is_active'=>false],['min_purchase_cents'=>1001],['remaining_cents'=>0],['approval_status'=>\App\Enums\CouponApprovalStatus::PendingAuthorization],['approval_status'=>\App\Enums\CouponApprovalStatus::Rejected]] as $attrs)$this->assign($u,$attrs);
  $this->assign($u,[],now());$this->assertSame([],app(MedicalBenefitEligibilityReader::class)->eligible($u,1000));
 }
 #[Test]public function empty_cart_does_not_mutate_sources_or_issue_reference():void{
  $u=User::factory()->withRegularCustomer()->create();$source=$this->assign($u);$before=$source->fresh()->getAttributes();$this->assertSame([],app(MedicalBenefitEligibilityReader::class)->eligible($u,0));$this->assertSame($before,$source->fresh()->getAttributes());
 }
 #[Test]public function storage_error_is_not_reported_as_empty_eligible_success():void{
  $u=User::factory()->withRegularCustomer()->create();$this->assign($u);
  $table=(new CouponUser)->getTable();\Illuminate\Support\Facades\Schema::rename($table,'fixture_coupon_assignment');
  try{try{app(MedicalBenefitEligibilityReader::class)->eligible($u,1000);$this->fail('Expected database failure');}catch(\Illuminate\Database\QueryException){$this->assertTrue(true);}}
  finally{\Illuminate\Support\Facades\Schema::rename('fixture_coupon_assignment',$table);}
 }

}
