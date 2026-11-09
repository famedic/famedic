<?php
declare(strict_types=1);
namespace Famedic\H04Fix\Tests;
use App\Models\AtixSandboxNonce;
use App\Services\Api\V1\Integrations\AtixHmacService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtixH04RetentionTest extends TestCase
{
    private int $epoch=1760000000;
    protected function setUp(): void {
        parent::setUp();Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch));
        config()->set('atix.sandbox.keys',['h04-test'=>'public-synthetic-H04-only']);
        config()->set('atix.sandbox.algorithm','sha256');
        config()->set('atix.sandbox.timestamp_tolerance_seconds',300);
        config()->set('atix.sandbox.nonce_ttl_seconds',300);
        config()->set('api_v1.audit.enabled',false);
    }
    protected function tearDown(): void {Carbon::setTestNow();parent::tearDown();}
    private function signed(string $timestamp,string $nonce,string $key='h04-test'): Request {
        $body='{"synthetic":true}';
        $canonical=implode("\n",['POST','/api/v1/integrations/atix/phone-lookups',$timestamp,$nonce,hash('sha256',$body)]);
        return Request::create('/api/v1/integrations/atix/phone-lookups','POST',[],[],[],[
            'CONTENT_TYPE'=>'application/json','HTTP_X_CORRELATION_ID'=>'synthetic-h04-test',
            'HTTP_X_INTEGRATION_KEY_ID'=>$key,'HTTP_X_TIMESTAMP'=>$timestamp,
            'HTTP_X_NONCE'=>$nonce,'HTTP_X_SIGNATURE'=>hash_hmac('sha256',$canonical,'public-synthetic-H04-only')],$body);
    }
    private function deny(Request $r,int $status,string $code): void {
        $response=app(AtixHmacService::class)->authenticate($r);
        $this->assertNotNull($response);$this->assertSame($status,$response->getStatusCode());
        $this->assertSame($code,$response->getData(true)['error']['code']);
    }
    #[Test]
    public function horizon_covers_future_present_and_past_boundaries_for_all_tolerances(): void {
        foreach ([1,300,900] as $tolerance) foreach ([-$tolerance,0,$tolerance] as $offset) {
            Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch));
            config()->set('atix.sandbox.timestamp_tolerance_seconds',$tolerance);
            config()->set('atix.sandbox.nonce_ttl_seconds',1);
            $nonce='horizon-'.$tolerance.'-'.$offset;
            $request=$this->signed((string)($this->epoch+$offset),$nonce);
            $this->assertNull(app(AtixHmacService::class)->authenticate($request));
            $row=AtixSandboxNonce::where('nonce_hash',hash('sha256',$nonce))->sole();
            $this->assertSame($this->epoch+2*$tolerance+1,$row->expires_at->timestamp);
            $this->deny($request,409,'ATIX_NONCE_REPLAY'); // frozen clock
            $last=$this->epoch+$offset+$tolerance;
            Carbon::setTestNow(Carbon::createFromTimestampUTC($last));
            $this->deny($request,409,'ATIX_NONCE_REPLAY'); // inclusive final second
            Carbon::setTestNow(Carbon::createFromTimestampUTC($last+1));
            $this->deny($request,401,'ATIX_TIMESTAMP_EXPIRED');
            $this->assertSame($row->id,AtixSandboxNonce::where('nonce_hash',hash('sha256',$nonce))->sole()->id);
        }
    }
    #[Test]
    public function larger_configured_retention_is_preserved(): void {
        config()->set('atix.sandbox.nonce_ttl_seconds',86400);
        $this->assertNull(app(AtixHmacService::class)->authenticate($this->signed((string)$this->epoch,'long-retention-nonce')));
        $this->assertSame($this->epoch+86400,AtixSandboxNonce::where('nonce_hash',hash('sha256','long-retention-nonce'))->sole()->expires_at->timestamp);
    }
    #[Test]
    public function expired_claim_is_not_recycled_and_clock_rollback_cannot_reopen_replay(): void {
        $r=$this->signed((string)($this->epoch+300),'clock-rollback-nonce');
        $this->assertNull(app(AtixHmacService::class)->authenticate($r));
        $id=AtixSandboxNonce::where('nonce_hash',hash('sha256','clock-rollback-nonce'))->sole()->id;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch+1000));
        $this->deny($r,401,'ATIX_TIMESTAMP_EXPIRED');
        $this->deny($this->signed((string)($this->epoch+1000),'clock-rollback-nonce'),409,'ATIX_NONCE_REPLAY');
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch+301));
        $this->deny($r,409,'ATIX_NONCE_REPLAY');
        $this->assertSame($id,AtixSandboxNonce::where('nonce_hash',hash('sha256','clock-rollback-nonce'))->sole()->id);
    }
    #[Test]
    public function safe_cleanup_requires_permanent_key_retirement_not_just_expiry(): void {
        config()->set('atix.sandbox.keys',['h04-test'=>'public-synthetic-H04-only','active-other'=>'public-synthetic-H04-only']);
        $old=$this->signed((string)($this->epoch+300),'retired-key-nonce');
        $active=$this->signed((string)($this->epoch+300),'retired-key-nonce','active-other');
        $this->assertNull(app(AtixHmacService::class)->authenticate($old));
        $this->assertNull(app(AtixHmacService::class)->authenticate($active));
        // Administrative cleanup simulation ONLY in the ephemeral test DB.
        // The retired key ID must never be restored; there is no active-key pruner.
        config()->set('atix.sandbox.keys',['active-other'=>'public-synthetic-H04-only']);
        AtixSandboxNonce::where('integration_key_hash',hash('sha256','h04-test'))->delete();
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch+301));
        $this->deny($old,401,'ATIX_INTEGRATION_UNAUTHORIZED');
        $this->deny($active,409,'ATIX_NONCE_REPLAY');
        $this->assertSame(1,AtixSandboxNonce::count());
    }
    #[Test]
    public function actual_sqlite_write_failure_is_controlled_and_does_not_claim_nonce(): void {
        $r=$this->signed((string)$this->epoch,'write-failure-nonce');
        DB::connection()->getPdo()->exec('PRAGMA query_only=ON');
        try {$this->deny($r,503,'ATIX_INFRASTRUCTURE_UNAVAILABLE');}
        finally {DB::connection()->getPdo()->exec('PRAGMA query_only=OFF');}
        $this->assertNull(app(AtixHmacService::class)->authenticate($r));
    }
    #[Test]
    public function missing_storage_table_is_controlled_without_follow_up_read(): void {
        DB::statement('ALTER TABLE atix_sandbox_nonces RENAME TO temporarily_unavailable_nonces');
        try {$this->deny($this->signed((string)$this->epoch,'missing-table-nonce'),503,'ATIX_INFRASTRUCTURE_UNAVAILABLE');}
        finally {DB::statement('ALTER TABLE temporarily_unavailable_nonces RENAME TO atix_sandbox_nonces');}
    }
    #[Test]
    public function invalid_config_never_shortens_or_opens_retention(): void {
        foreach ([0,901,'300',1.5,null,[]] as $bad) {
            config()->set('atix.sandbox.timestamp_tolerance_seconds',$bad);
            $this->deny($this->signed((string)$this->epoch,'bad-tolerance-nonce'),503,'ATIX_INFRASTRUCTURE_UNAVAILABLE');
        }
        config()->set('atix.sandbox.timestamp_tolerance_seconds',300);
        foreach ([0,86401,'300',1.5,null,[]] as $bad) {
            config()->set('atix.sandbox.nonce_ttl_seconds',$bad);
            $this->deny($this->signed((string)$this->epoch,'bad-config-ttl-nonce'),503,'ATIX_INFRASTRUCTURE_UNAVAILABLE');
        }
        $this->assertSame(0,AtixSandboxNonce::count());
    }
    #[Test]
    public function timestamp_overflow_and_mysql_timestamp_boundary_fail_closed(): void {
        foreach ([(string)PHP_INT_MAX.'0',str_repeat('9',1000),'0','-1','1.5'] as $timestamp) {
            $this->deny($this->signed($timestamp,'overflow-synthetic-nonce'),401,'ATIX_TIMESTAMP_EXPIRED');
        }
        Carbon::setTestNow(Carbon::createFromTimestampUTC(2147483647));
        $this->deny($this->signed('2147483647','mysql-boundary-nonce'),503,'ATIX_INFRASTRUCTURE_UNAVAILABLE');
        $this->assertSame(0,AtixSandboxNonce::count());
    }
    #[Test]
    public function leading_zero_timestamp_keeps_exact_v1_signed_bytes(): void {
        $r=$this->signed('000'.$this->epoch,'leading-zero-nonce');
        $this->assertNull(app(AtixHmacService::class)->authenticate($r));
        $this->deny($r,409,'ATIX_NONCE_REPLAY');
    }
}
