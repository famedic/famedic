<?php
declare(strict_types=1);
namespace Famedic\FunctionalClosure;
use App\Enums\{Gender,LaboratoryBrand};
use App\Models\{User,Contact,Address,LaboratoryCheckoutDraft,AkubicaCheckoutLink};
use App\Services\Api\V1\MedicalServiceCartService;
use Illuminate\Support\Facades\{Auth,Bus,Queue,Notification,Http};
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Synthetic patients, never a verified ATIX/native identity or payment provider. */
final class CartContinuityAndPaymentTest extends TestCase
{
    protected function setUp():void {parent::setUp();Bus::fake();Queue::fake();Notification::fake();Http::fake();}
    private function actor(array $abilities=['akubica:read']):array {
        $user=User::factory()->withRegularCustomer()->create();
        $token=$user->createToken('synthetic-functional-payment',$abilities)->plainTextToken;
        return [$user,$token];
    }
    private function readyLaboratory($user):void {
        addOlabCartItemReadyForPaymentLink($user);
        $contact=Contact::factory()->for($user->customer)->create(['birth_date'=>'1990-01-01','gender'=>Gender::MALE]);
        $address=Address::factory()->for($user->customer)->create(['city'=>'Sintetica','state'=>'Sintetico']);
        LaboratoryCheckoutDraft::query()->create(['customer_id'=>$user->customer->id,'laboratory_brand'=>LaboratoryBrand::OLAB,
            'contact_id'=>$contact->id,'address_id'=>$address->id,'checkout_step'=>'confirmation']);
    }
    private function observe(string $id,$response,array $extra=[]):void {
        $path=getenv('FAMEDIC_FUNCTIONAL_PAYMENT_OBSERVATIONS');if(!$path)throw new \RuntimeException('Missing isolated observation path');
        $data=['case'=>$id,'http'=>$response->getStatusCode(),'error_code'=>$response->json('error.code'),
            'provider_HTTP_calls'=>Http::recorded()->count()]+$extra;
        file_put_contents($path,json_encode($data,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    }
    #[Test]
    public function medical_prepare_has_explicit_bot_continuity_and_legacy_field_without_consumption():void {
        [$user,$token]=$this->actor();$cart=app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
        $before=$cart->fresh()->only(['subtotal_cents','discount_cents','credit_applied_cents','total_cents']);
        $this->switchApiBearerToken($token);$response=$this->getJson('/api/v1/checkout/prepare?service_line=medical_services');
        $this->observe('B5-medical-continuity',$response,['assistant_can_pay'=>$response->json('data.assistant_can_pay'),
            'can_continue_on_platform'=>$response->json('data.can_continue_on_platform'),'legacy_field'=>$response->json('data.can_continue_to_payment_platform')]);
        $response->assertOk();$this->assertFalse($response->json('data.assistant_can_pay'));
        $this->assertTrue($response->json('data.can_continue_on_platform'));$this->assertFalse($response->json('data.can_continue_to_payment_platform'));
        $this->assertSame($before,$cart->fresh()->only(array_keys($before)));
        $this->assertSame(0,AkubicaCheckoutLink::count());$this->assertSame(0,Http::recorded()->count());
    }
    #[Test]
    public function laboratory_legacy_continuity_is_retained_without_inventing_normalized_contract_fields():void {
        [$user,$token]=$this->actor();$this->readyLaboratory($user);$this->switchApiBearerToken($token);
        $response=$this->getJson('/api/v1/checkout/prepare?brand=olab');
        $this->observe('B5-laboratory-legacy-continuity',$response,['legacy_field'=>$response->json('data.can_continue_to_payment_platform'),
            'assistant_field_present'=>array_key_exists('assistant_can_pay',$response->json('data')??[]),
            'platform_field_present'=>array_key_exists('can_continue_on_platform',$response->json('data')??[])]);
        $response->assertOk();$this->assertIsBool($response->json('data.can_continue_to_payment_platform'));
        $this->assertSame(0,AkubicaCheckoutLink::count());$this->assertSame(0,Http::recorded()->count());
    }
    #[Test]
    public function customer_carts_are_isolated_before_and_after_foreign_removal_attempt():void {
        [$a,$ta]=$this->actor();[$b,$tb]=$this->actor();$this->readyLaboratory($a);$this->readyLaboratory($b);
        $aItem=$a->customer->laboratoryCartItems()->sole();$bItem=$b->customer->laboratoryCartItems()->sole();
        $this->switchApiBearerToken($ta);$beforeA=$this->getJson('/api/v1/cart?brand=olab')->assertOk();
        $this->switchApiBearerToken($tb);$beforeB=$this->getJson('/api/v1/cart?brand=olab')->assertOk();
        $this->assertSame($aItem->id,$beforeA->json('data.items.0.id'));
        $this->assertSame($bItem->id,$beforeB->json('data.items.0.id'));
        $denied=$this->deleteJson('/api/v1/cart/items/'.$aItem->id);$this->observe('B5-cart-ownership',$denied);
        $denied->assertStatus(403)->assertJsonPath('error.code','FORBIDDEN');
        $afterB=$this->getJson('/api/v1/cart?brand=olab')->assertOk();$this->switchApiBearerToken($ta);$afterA=$this->getJson('/api/v1/cart?brand=olab')->assertOk();
        $this->assertSame($beforeA->json('data'),$afterA->json('data'));$this->assertSame($beforeB->json('data'),$afterB->json('data'));
    }
    #[Test]
    public function read_only_PAT_must_not_generate_payment_link():void {
        [$user,$token]=$this->actor(['akubica:read']);$this->readyLaboratory($user);
        config()->set('api_v1.idempotency.enabled',true);$this->switchApiBearerToken($token);$before=AkubicaCheckoutLink::count();
        $response=$this->postJson('/api/v1/checkout/payment-link',['brand'=>'olab'],['Idempotency-Key'=>'synthetic-payment-guard-check']);
        $this->observe('P07-readonly-PAT',$response,['links_created'=>AkubicaCheckoutLink::count()-$before]);
        $this->assertSame(403,$response->getStatusCode(),'Read-only PAT must be denied');
        $this->assertSame(0,AkubicaCheckoutLink::count()-$before);$this->assertSame(0,Http::recorded()->count());
    }
    #[Test]
    public function unclassified_wildcard_PAT_is_not_verified_consumer_identity():void {
        [$user,$token]=$this->actor(['*']);$this->readyLaboratory($user);
        $this->switchApiBearerToken($token);$before=AkubicaCheckoutLink::count();
        $response=$this->postJson('/api/v1/checkout/payment-link',['brand'=>'olab'],['Idempotency-Key'=>'synthetic-wildcard-guard-check']);
        $this->observe('A04-unclassified-wildcard-PAT',$response,['links_created'=>AkubicaCheckoutLink::count()-$before]);
        $response->assertStatus(403);$this->assertSame(0,AkubicaCheckoutLink::count()-$before);
        $this->assertSame(0,Http::recorded()->count());
    }
    #[Test]
    public function web_session_must_not_substitute_for_payment_PAT():void {
        [$user]=$this->actor();$this->readyLaboratory($user);config()->set('auth.defaults.guard','web');
        $this->post('/login',['email'=>$user->email,'password'=>'password'])->assertRedirect();
        $this->flushHeaders();config()->set('sanctum.guard',['web']);$this->withCookie(config('session.cookie'),session()->getId());
        $before=AkubicaCheckoutLink::count();
        $response=$this->postJson('/api/v1/checkout/payment-link',['brand'=>'olab'],['Idempotency-Key'=>'synthetic-session-guard-check']);
        $this->observe('P08-web-session',$response,['links_created'=>AkubicaCheckoutLink::count()-$before]);
        $this->assertSame(401,$response->getStatusCode(),'Session is not a payment PAT');
        $this->assertSame(0,AkubicaCheckoutLink::count()-$before);$this->assertSame(0,Http::recorded()->count());
    }
}
