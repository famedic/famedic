<?php
declare(strict_types=1);
namespace Famedic\Phase2C1;
require_once __DIR__.'/RecordsResponses.php';
use App\Contracts\Otp\OtpCodeGenerator;
use App\Models\{User,OtpChallenge,AccountContext};
use Illuminate\Support\Facades\{Bus,Notification,Queue};
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Otp\FakeOtpCodeGenerator;
use Tests\TestCase;
final class PhoneContinuityTest extends TestCase
{
    use RecordsResponses;
    protected function setUp(): void {
        parent::setUp();Bus::fake();Queue::fake();Notification::fake();disableAllAkubicaOtpFeatures();enableLoginOtpWithFakeDelivery();
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00','UTC'));
        config()->set('atix.sandbox.keys',['functional-synthetic'=>'public-synthetic-functional-only']);
        $this->app->instance(OtpCodeGenerator::class,new FakeOtpCodeGenerator('123456'));
    }
    protected function tearDown(): void {Carbon::setTestNow();disableAllAkubicaOtpFeatures();parent::tearDown();}
    private function payload(string $phone): array {return ['phone'=>$phone,'conversation_id'=>'synthetic-conversation','channel_event_id'=>'synthetic-event',
        'channel'=>'whatsapp','audience'=>'leo','personalization'=>['allowed'=>false]];}
    private function lookup(array $data,string $nonce,string $idem='synthetic-functional-idem') {
        $this->flushHeaders();$body=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$ts=(string)now()->timestamp;
        $text=implode("\n",['POST','/api/v1/integrations/atix/phone-lookups',$ts,$nonce,hash('sha256',$body)]);
        return $this->withHeaders(['X-Integration-Key-Id'=>'functional-synthetic','X-Timestamp'=>$ts,'X-Nonce'=>$nonce,
            'X-Signature'=>hash_hmac('sha256',$text,'public-synthetic-functional-only'),'X-Correlation-ID'=>'synthetic-functional-correlation','Idempotency-Key'=>$idem])
            ->postJson('/api/v1/integrations/atix/phone-lookups',$data);
    }
    #[Test]
    public function eligible_phone_continues_from_signed_lookup_to_otp_and_patient_pat_only_after_verify(): void {
        $user=User::factory()->withRegularCustomer()->create(['name'=>'Paciente Sintetico','email'=>'existing@example.test','phone'=>'5512349001','phone_country'=>'MX','phone_verified_at'=>now()]);
        $data=$this->payload('+525512349001');$lookup=$this->lookup($data,'synthetic-existing-nonce')->assertStatus(202)->assertJsonPath('data.recognition_status','recognized_unverified');
        $this->assertSame(0,PersonalAccessToken::count());$this->assertSame(0,OtpChallenge::count());
        $this->record('RF02-recognized','POST','/integrations/atix/phone-lookups',$data,$lookup);
        $this->flushHeaders();$start=$this->postJson('/api/v1/auth/login/request-code',$data)->assertStatus(202);$id=$start->json('data.challenge_id');
        $this->assertSame(0,PersonalAccessToken::count());$this->record('RF02-login-start','POST','/auth/login/request-code',$data,$start);
        $wrong=$this->postJson('/api/v1/auth/login/verify-code',['challenge_id'=>$id,'code'=>'000000'])->assertStatus(422)->assertJsonPath('error.code','INVALID_CODE');
        $this->assertSame(0,PersonalAccessToken::count());$this->record('RF02-login-wrong','POST','/auth/login/verify-code',['challenge_id'=>$id,'code'=>'000000'],$wrong);
        $valid=$this->postJson('/api/v1/auth/login/verify-code',['challenge_id'=>$id,'code'=>'123456'])->assertOk()->assertJsonPath('data.user.id',$user->id)->assertJsonPath('data.otp_verified',true);
        $this->assertSame(1,PersonalAccessToken::count());$this->assertNotNull(AccountContext::where('user_id',$user->id)->sole()->verified_at);
        $this->record('RF02-login-verified','POST','/auth/login/verify-code',['challenge_id'=>$id,'code'=>'123456'],$valid);
        $this->switchApiBearerToken($valid->json('data.token'));$this->getJson('/api/v1/memberships/status')->assertOk();
    }
    #[Test]
    public function unknown_phone_can_register_with_fake_sms_and_get_pat_only_after_valid_registration_otp(): void {
        $data=$this->payload('+525512349002');$lookup=$this->lookup($data,'synthetic-new-phone-nonce')->assertStatus(202)->assertJsonPath('data.recognition_status','unknown');
        $this->record('RF02-unknown','POST','/integrations/atix/phone-lookups',$data,$lookup);$this->assertSame(0,PersonalAccessToken::count());
        $this->flushHeaders();enableRegisterOtpWithFakeDelivery();$this->app->instance(OtpCodeGenerator::class,new FakeOtpCodeGenerator('123456'));
        $input=['full_name'=>'Paciente Nuevo Sintetico','email'=>'new@example.test','phone'=>'+525512349002'];
        $start=$this->postJson('/api/v1/auth/register',$input)->assertStatus(202);$id=$start->json('data.challenge_id');
        $this->assertSame(0,PersonalAccessToken::count());$this->assertFalse(User::where('email','new@example.test')->exists());
        $this->record('RF02-register-start','POST','/auth/register',$input,$start);
        $bad=$this->postJson('/api/v1/auth/register/verify-code',['challenge_id'=>$id,'code'=>'000000'])->assertStatus(422)->assertJsonPath('error.code','INVALID_CODE');
        $this->assertSame(0,PersonalAccessToken::count());$this->record('RF02-register-wrong','POST','/auth/register/verify-code',['challenge_id'=>$id,'code'=>'000000'],$bad);
        $valid=$this->postJson('/api/v1/auth/register/verify-code',['challenge_id'=>$id,'code'=>'123456'])->assertOk();
        $this->assertSame(1,PersonalAccessToken::count());$this->assertNotNull(User::where('email','new@example.test')->sole()->phone_verified_at);
        $this->record('RF02-register-verified','POST','/auth/register/verify-code',['challenge_id'=>$id,'code'=>'123456'],$valid);
        $this->switchApiBearerToken($valid->json('data.token'));$this->getJson('/api/v1/memberships/status')->assertOk();
    }
    #[Test]
    public function fresh_nonce_preserves_lookup_idempotency_and_duplicate_nonce_is_rejected_without_otp(): void {
        $data=$this->payload('+525512349003');$a=$this->lookup($data,'synthetic-first-lookup')->assertStatus(202);
        $b=$this->lookup($data,'synthetic-second-lookup')->assertStatus(202)->assertHeader('Idempotency-Replayed','true');
        $this->assertSame($a->json(),$b->json());$this->record('RF02-lookup-replay-new-nonce','POST','/integrations/atix/phone-lookups',$data,$b);
        $c=$this->lookup($data,'synthetic-second-lookup')->assertStatus(409)->assertJsonPath('error.code','ATIX_NONCE_REPLAY');
        $this->record('RF02-lookup-replay-same-nonce','POST','/integrations/atix/phone-lookups',$data,$c);
        $this->assertSame(0,PersonalAccessToken::count());$this->assertSame(0,OtpChallenge::count());
    }
}
