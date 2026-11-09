<?php

namespace Famedic\Integration;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\Api\V1\Benefits\BenefitApprovals;
use App\Services\Api\V1\Benefits\PendingBenefitApprovals;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\LocalMachineCredentialSource;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture as Fixture;
use Tests\TestCase;

final class EnforcedCsrf extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
final class UiApprovals implements BenefitApprovals
{
    public function api(): bool
    {
        return true;
    }

    public function consent(): bool
    {
        return true;
    }

    public function financial(): bool
    {
        return false;
    }

    public function textVersion(): ?string
    {
        return 'synthetic-ui-text-v1';
    }
}
final class ConsentUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        $this->app->instance(BenefitApprovals::class, new UiApprovals);
        $this->app->bind(BackendCredentialSource::class, LocalMachineCredentialSource::class);
        config()->set('famedic.medical_attention_subscription_price_cents', 1000);
    }

    private function fixture(): array
    {
        $a = Fixture::issue(User::factory()->withRegularCustomer()->create(), 'leo', ['benefits:read', 'benefits:apply', 'benefits:remove']);
        app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);
        $c = Coupon::factory()->create(['amount_cents' => 1500, 'remaining_cents' => 1500]);
        CouponUser::create(['coupon_id' => $c->id, 'user_id' => $a['user']->id]);
        $headers = ['Authorization' => 'Bearer '.$a['token']->plainTextToken, 'X-Synthetic-Machine-Credential' => $a['machine']->plainTextToken];
        $s = $this->getJson('/api/v1/cart/benefits?service_line=medical_services', $headers)->assertOk()->json('data');
        $this->flushHeaders();

        return [$a, $s, ['cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'target_ref' => $s['benefits'][0]['benefit_ref'], 'operation' => 'apply', 'text_version' => 'synthetic-ui-text-v1']];
    }

    private function login(array $a): void
    {
        $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, EnforcedCsrf::class);
    }

    private function fields(string $html): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML($html);
        $out = [];
        foreach ((new \DOMXPath($doc))->query('//form//input') as $input) {
            $out[$input->getAttribute('name')] = $input->getAttribute('value');
        }

return $out;
    }

    #[Test]
    public function page_requires_famedic_session_and_default_issuance_is_disabled(): void
    {
        $this->get('/benefits/consent')->assertRedirect('/login');
        [$a,$s,$form] = $this->fixture();
        $this->login($a);
        $this->app->instance(BenefitApprovals::class, new PendingBenefitApprovals);
        $this->get('/benefits/consent?'.http_build_query($form))->assertOk()->assertSee('todavía no está habilitada');
        $this->assertSame(0, DB::table('opaque_benefit_states')->where('bucket', 'confirmations')->count());
    }

    #[Test]
    public function first_party_ui_review_confirm_and_revoke_with_actual_csrf(): void
    {
        [$a,$s,$form] = $this->fixture();
        $this->login($a);
        $view = $this->get('/benefits/consent?'.http_build_query($form))->assertOk()->assertSee('Saldo a favor')->assertSee('10.00')->assertSee('Vigencia')->assertHeader('Referrer-Policy', 'no-referrer');
        $fields = $this->fields($view->getContent());
        $this->assertArrayHasKey('_token', $fields);
        $this->assertArrayHasKey('web_intent', $fields);
        $issued = $this->post('/benefits/consent/confirm', $fields)->assertOk()->assertSee('Confirmación registrada');
        preg_match('/cnf_[A-Za-z0-9_-]{43}/', $issued->getContent(), $m);
        $this->assertNotEmpty($m);
        $this->assertSame(1, DB::table('opaque_benefit_states')->where('bucket', 'confirmations')->count());
        $this->post('/benefits/consent/'.$m[0].'/revoke', $this->fields($issued->getContent()))->assertOk()->assertSee('Confirmación revocada');
        $this->assertSame(0, DB::table('medical_service_cart_benefits')->count());
    }

    #[Test]
    public function missing_and_forged_csrf_cannot_issue_or_revoke(): void
    {
        [$a,$s,$form] = $this->fixture();
        $this->login($a);
        $view = $this->get('/benefits/consent?'.http_build_query($form))->assertOk();
        $fields = $this->fields($view->getContent());
        $bad = $fields;
        unset($bad['_token']);
        // Existing global handler converts 419 to home redirect. Assert rejection and no effect.
        $this->post('/benefits/consent/confirm', $bad)->assertRedirect(route('home'));
        $bad['_token'] = 'public-synthetic-invalid';
        $this->post('/benefits/consent/confirm', $bad)->assertRedirect(route('home'));
        $this->assertSame(0, DB::table('opaque_benefit_states')->where('bucket', 'confirmations')->count());
        $issued = $this->post('/benefits/consent/confirm', $fields)->assertOk();
        preg_match('/cnf_[A-Za-z0-9_-]{43}/', $issued->getContent(), $m);
        $this->post('/benefits/consent/'.$m[0].'/revoke', [])->assertRedirect(route('home'));
        $row = DB::table('opaque_benefit_states')->where('bucket', 'confirmations')->sole();
        $this->assertFalse(json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->ciphertext), true)['revoked']);
    }

    #[Test]
    public function expired_review_cannot_be_confirmed_and_recent_password_is_required(): void
    {
        [$a,$s,$form] = $this->fixture();
        $this->login($a);
        $view = $this->get('/benefits/consent?'.http_build_query($form))->assertOk();
        $fields = $this->fields($view->getContent());
        $this->travel(121)->seconds();
        $this->post('/benefits/consent/confirm', $fields)->assertStatus(409);
        $this->travelBack();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(6)->timestamp]);
        $this->get('/benefits/consent?'.http_build_query($form))->assertForbidden();
        $this->assertSame(0, DB::table('opaque_benefit_states')->where('bucket', 'confirmations')->count());
    }

    #[Test]
    public function modified_form_and_replayed_review_ticket_cannot_issue_another_receipt(): void
    {
        [$a,$s,$form] = $this->fixture();
        $this->login($a);
        $fields = $this->fields($this->get('/benefits/consent?'.http_build_query($form))->assertOk()->getContent());
        $bad = $fields;
        $bad['target_ref'] = \App\Domain\Akubica\Benefits\OpaqueReference::issue('bnf');
        $this->post('/benefits/consent/confirm', $bad)->assertStatus(409);
        $this->post('/benefits/consent/confirm', $fields)->assertOk();
        $this->post('/benefits/consent/confirm', $fields)->assertStatus(409);
        $this->assertSame(1,DB::table('opaque_benefit_states')->where('bucket','confirmations')->count());
    }
}
