<?php
/** Existing V1 service, synthetic configuration, ephemeral schema. No HTTP/business action. */
declare(strict_types=1);
namespace Famedic\LocalPrototype\Tests;
use App\Models\AtixSandboxNonce;
use App\Services\Api\V1\Integrations\AtixHmacService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class H04RealV1Test extends TestCase
{
    private int $epoch = 1760000000;
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    private function configure(int $tolerance, int $ttl): void
    {
        config()->set('atix.sandbox.keys', ['h04-synthetic' => 'public-synthetic-H04-key-only']);
        config()->set('atix.sandbox.algorithm', 'sha256');
        config()->set('atix.sandbox.timestamp_tolerance_seconds', $tolerance);
        config()->set('atix.sandbox.nonce_ttl_seconds', $ttl);
        config()->set('api_v1.audit.enabled', false);
    }
    private function signed(int $timestamp, string $nonce): Request
    {
        $body = '{"synthetic":true}';
        $text = implode("\n", ['POST','/api/v1/integrations/atix/phone-lookups',(string)$timestamp,$nonce,hash('sha256',$body)]);
        return Request::create('/api/v1/integrations/atix/phone-lookups','POST',[],[],[],[
            'CONTENT_TYPE'=>'application/json','HTTP_X_CORRELATION_ID'=>'h04-synthetic-correlation',
            'HTTP_X_INTEGRATION_KEY_ID'=>'h04-synthetic','HTTP_X_TIMESTAMP'=>(string)$timestamp,
            'HTTP_X_NONCE'=>$nonce,'HTTP_X_SIGNATURE'=>hash_hmac('sha256',$text,'public-synthetic-H04-key-only'),
        ],$body);
    }
    private function observe(string $case, array $values): void
    {
        $path = getenv('FAMEDIC_V2_H04_REPORT');
        if (!$path || !getenv('FAMEDIC_V2_EPHEMERAL')) { throw new \RuntimeException('Missing local harness'); }
        file_put_contents($path,json_encode(['case'=>$case,'values'=>$values])."\n",FILE_APPEND|LOCK_EX);
    }
    #[Test]
    public function actual_v1_rejects_h04_replay_after_old_nonce_expiry_while_signature_valid(): void
    {
        $this->configure(300,300);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch));
        $request=$this->signed($this->epoch+300,'h04-original-nonce');
        $service=app(AtixHmacService::class);
        $this->assertNull($service->authenticate($request));
        $row=AtixSandboxNonce::query()->where('nonce_hash',hash('sha256','h04-original-nonce'))->sole();
        $originalExpiry=$row->expires_at->timestamp;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch+299));
        $this->assertSame(409,$service->authenticate($request)->getStatusCode());
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch+301));
        $second=$service->authenticate($request);
        $this->assertNotNull($second, 'H04: the original signature must never be accepted twice');
        $this->assertSame(409,$second->getStatusCode());
        $this->assertGreaterThan($this->epoch+600,$originalExpiry);
        $this->observe('H04_REAL_V1_FIXED',['t0'=>$this->epoch,'timestamp'=>$this->epoch+300,
            'stored_nonce_expiry'=>$originalExpiry,'second_denial'=>$this->epoch+301,'second_http'=>409,
            'signature_last_acceptable'=>$this->epoch+600,'business_action_executed'=>false]);
    }
    #[Test]
    public function actual_v1_with_test_only_sufficient_retention_rejects_full_remaining_window(): void
    {
        $service=app(AtixHmacService::class);
        foreach ([1,300,900] as $tolerance) {
            $ttl=2*$tolerance+5;
            $this->configure($tolerance,$ttl);
            Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch));
            $r=$this->signed($this->epoch+$tolerance,'h04-fixed-'.$tolerance);
            $this->assertNull($service->authenticate($r));
            foreach ([$tolerance+1,2*$tolerance] as $offset) {
                Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch+$offset));
                $this->assertSame(409,$service->authenticate($r)->getStatusCode());
            }
            Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch+2*$tolerance+1));
            $this->assertSame(401,$service->authenticate($r)->getStatusCode());
            $this->observe('V1_TEST_ONLY_RETENTION_VERIFIED',['tolerance'=>$tolerance,'ttl'=>$ttl,
                'production_config_changed'=>false]);
        }
    }
    #[Test]
    public function actual_v1_does_not_accept_v2_signature_material_or_v2_secret(): void
    {
        $this->configure(300,300);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch));
        $request=$this->signed($this->epoch,'v1-v2-isolation-nonce');
        $body=$request->getContent();
        $v2=implode("\n",['ATIX-API-V2','local-stage','h04-synthetic','POST',
            '/api/v1/integrations/atix/phone-lookups',hash('sha256',''),(string)$this->epoch,
            'v1-v2-isolation-nonce','whatsapp','-','-',hash('sha256',$body)]);
        foreach (['public-synthetic-H04-key-only','synthetic-only-atix-v2-key-0000001'] as $secret) {
            $request->headers->set('X-Signature',hash_hmac('sha256',$v2,$secret));
            $denied=app(AtixHmacService::class)->authenticate($request);
            $this->assertSame(401,$denied->getStatusCode());
            $this->assertSame('ATIX_SIGNATURE_INVALID',$denied->getData(true)['error']['code']);
        }
        $this->observe('REAL_V1_REJECTS_V2_DOMAIN_AND_SECRET',['business_action_executed'=>false]);
    }

}
