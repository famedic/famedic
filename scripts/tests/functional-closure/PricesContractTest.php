<?php
declare(strict_types=1);
namespace Famedic\Phase2C1;
require_once __DIR__.'/RecordsResponses.php';
use App\Enums\LaboratoryBrand;
use App\Enums\MedicalSubscriptionType;
use App\Exceptions\Api\V1\PricingSourceUnavailableException;
use App\Http\Resources\Api\V1\LaboratoryTestResource;
use App\Models\{LaboratoryTest,LaboratoryStore,MedicalAttentionSubscription,PromoCode,User};
use App\Services\Api\V1\LaboratoryPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Bus,Notification,Queue};
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
final class PricesContractTest extends TestCase
{
    use RecordsResponses;
    protected function setUp(): void {
        parent::setUp();Bus::fake();Queue::fake();Notification::fake();
        config()->set('api_v1.pricing',['source_available'=>true,'tax_included'=>null,'effective_at'=>null,'valid_until'=>null]);
    }
    private function study(array $fields=[]): LaboratoryTest {
        return LaboratoryTest::factory()->create(array_merge(['brand'=>LaboratoryBrand::OLAB,'name'=>'Estudio Sintetico',
            'gda_id'=>'SYNTHETIC-'.\Illuminate\Support\Str::uuid(),'public_price_cents'=>40000,'famedic_price_cents'=>35000,'requires_appointment'=>false],$fields));
    }
    #[Test]
    public function list_search_and_detail_preserve_alias_and_identical_explicit_pricing(): void {
        $study=$this->study();$list=$this->getJson('/api/v1/catalog/laboratory-tests?brand=olab&search=Sintetico')->assertOk();
        $detail=$this->getJson('/api/v1/catalog/laboratory-tests/'.$study->id)->assertOk();
        $a=$list->json('data.laboratory_tests.0');$b=$detail->json('data');
        $this->assertSame(40000,$a['pricing']['public_price_cents']);$this->assertSame(35000,$a['price_cents']);
        $this->assertSame(35000,$a['pricing']['famedic_price_cents']);$this->assertSame(5000,$a['pricing']['savings_cents']);
        $this->assertEquals(12.5,$a['pricing']['savings_percentage']);$this->assertSame($a['pricing'],$b['pricing']);
        foreach (['cost','cost_cents','internal_cost_cents','margin','profit','purchase_price_cents'] as $key) {
            $this->assertArrayNotHasKey($key,$a);$this->assertArrayNotHasKey($key,$b);$this->assertArrayNotHasKey($key,$a['pricing']);
        }
        $this->record('RF01-list-search','GET','/catalog/laboratory-tests',['brand'=>'olab','search'=>'Sintetico'],$list);
        $this->record('RF01-detail','GET','/catalog/laboratory-tests/{laboratory_test_id}',['laboratory_test_id'=>$study->id],$detail);
    }
    #[Test]
    public function equal_lower_and_zero_public_prices_do_not_invent_savings(): void {
        foreach ([[35000,35000,'no_savings',0,0],[20000,35000,'data_inconsistent',null,null],
            [0,35000,'public_price_unavailable',null,null],[300,200,'available',100,33.33],[100,0,'available',100,100]] as $i=>$case) {
            [$public,$famedic,$status,$savings,$percentage]=$case;$s=$this->study(['public_price_cents'=>$public,'famedic_price_cents'=>$famedic]);
            $response=$this->getJson('/api/v1/catalog/laboratory-tests/'.$s->id)->assertOk();$pricing=$response->json('data.pricing');
            $this->assertSame($status,$pricing['comparison_status']);$this->assertSame($savings,$pricing['savings_cents']);
            $percentage===null?$this->assertNull($pricing['savings_percentage']):$this->assertEquals($percentage,$pricing['savings_percentage']);
            $this->record('RF01-boundary-'.$i,'GET','/catalog/laboratory-tests/{laboratory_test_id}',['laboratory_test_id'=>$s->id],$response);
        }
    }
    #[Test]
    public function missing_public_price_and_internal_cost_do_not_become_fake_discounts_or_leaks(): void {
        // Historical DB prices are NOT NULL: exercise the defensive resource path without changing schema.
        $study=new LaboratoryTest(['id'=>999,'brand'=>LaboratoryBrand::OLAB,'name'=>'Fuente sin comparativo','gda_id'=>'SYNTHETIC',
            'public_price_cents'=>null,'famedic_price_cents'=>35000,'internal_cost_cents'=>123,'requires_appointment'=>false]);
        $study->setRelation('laboratoryTestCategory',null);
        $out=(new LaboratoryTestResource($study))->resolve(Request::create('/api/v1/catalog/laboratory-tests/999'));
        $this->assertNull($out['pricing']['public_price_cents']);$this->assertNull($out['pricing']['savings_cents']);
        $this->assertNull($out['pricing']['savings_percentage']);$this->assertSame('public_price_unavailable',$out['pricing']['comparison_status']);
        $this->assertArrayNotHasKey('internal_cost_cents',$out);
    }
    #[Test]
    public function missing_or_negative_famedic_price_raises_source_error_instead_of_zero_price(): void {
        foreach ([null,-1] as $price) {
            try {app(LaboratoryPricingService::class)->for(new LaboratoryTest(['public_price_cents'=>40000,'famedic_price_cents'=>$price]));
                $this->fail('Unavailable FAMEDIC price cannot be advertised as zero');}
            catch (PricingSourceUnavailableException) {$this->addToAssertionCount(1);}
        }
    }
    #[Test]
    public function unavailable_source_returns_exact_controlled_error_for_list_and_detail(): void {
        $study=$this->study();config()->set('api_v1.pricing.source_available',false);
        foreach (['/api/v1/catalog/laboratory-tests','/api/v1/catalog/laboratory-tests/'.$study->id] as $i=>$url) {
            $response=$this->getJson($url)->assertStatus(503)->assertJsonPath('error.code','PRICING_SOURCE_UNAVAILABLE')->assertJsonPath('error.retryable',true);
            $this->record('RF01-source-unavailable-'.$i,'GET',$i?'/catalog/laboratory-tests/{laboratory_test_id}':'/catalog/laboratory-tests',[],$response);
        }
    }
    #[Test]
    public function brand_prices_are_per_product_and_store_filter_is_not_a_tariff_feature(): void {
        $this->study(['name'=>'Mismo Sintetico','brand'=>LaboratoryBrand::OLAB]);
        $other=$this->study(['name'=>'Mismo Sintetico','brand'=>LaboratoryBrand::SWISSLAB,'public_price_cents'=>60000,'famedic_price_cents'=>45000]);
        foreach (['Sucursal A','Sucursal B'] as $name) {LaboratoryStore::create(['name'=>$name,'brand'=>LaboratoryBrand::SWISSLAB,'state'=>'Sintetico','address'=>'Ubicacion sintetica','weekly_hours'=>'Sintetico','saturday_hours'=>'Cerrado','sunday_hours'=>'Cerrado','google_maps_url'=>'https://example.invalid']);}
        $response=$this->getJson('/api/v1/catalog/laboratory-tests?brand=swisslab&search=Sintetico')->assertOk()->assertJsonCount(1,'data.laboratory_tests');
        $this->assertSame($other->id,$response->json('data.laboratory_tests.0.id'));
        $this->assertSame(15000,$response->json('data.laboratory_tests.0.pricing.savings_cents'));
        $this->record('RF01-brand-swisslab','GET','/catalog/laboratory-tests',['brand'=>'swisslab','search'=>'Sintetico'],$response);
        $this->assertArrayNotHasKey('store_id',(new \App\Http\Requests\Api\V1\Catalog\ListLaboratoryTestsRequest)->rules());
    }
    #[Test]
    public function catalog_base_price_does_not_apply_coupon_promo_or_membership_checkout_adjustments(): void {
        $study=$this->study();$before=$this->getJson('/api/v1/catalog/laboratory-tests/'.$study->id)->assertOk()->json('data.pricing');
        $user=User::factory()->withRegularCustomer()->create();
        MedicalAttentionSubscription::withoutEvents(fn()=>MedicalAttentionSubscription::create(['customer_id'=>$user->customer->id,
            'type'=>MedicalSubscriptionType::REGULAR,'price_cents'=>30000,'start_date'=>now()->subDay(),'end_date'=>now()->addYear()]));
        PromoCode::factory()->create(['code'=>'SYNTHETIC-CATALOG-PROMO','is_active'=>true]);
        createBalanceCouponForUser($user,'SYNTHETIC-CATALOG-CREDIT',5000);
        $token=$user->createToken('synthetic-pricing-readonly',['akubica:read'])->plainTextToken;
        $response=$this->getJson('/api/v1/catalog/laboratory-tests/'.$study->id,['Authorization'=>'Bearer '.$token])->assertOk();
        $this->assertSame($before,$response->json('data.pricing'));
        $this->record('RF01-base-with-membership-promo','GET','/catalog/laboratory-tests/{laboratory_test_id}',['laboratory_test_id'=>$study->id],$response);
    }
}
