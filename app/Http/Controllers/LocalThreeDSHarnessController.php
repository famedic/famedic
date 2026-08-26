<?php

namespace App\Http\Controllers;

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Support\PaymentAuthentication3dsStartResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

class LocalThreeDSHarnessController extends Controller
{
    public function harness(Request $request)
    {
        $harnessId = (string) $request->query('harness', Str::uuid());

        return response()
            ->view('local.three-ds-challenge-harness', [
                'harnessId' => $harnessId,
                'acsUrl' => route('local.3ds.fake-acs', ['harness' => $harnessId]),
                'observationUrl' => route('local.3ds.observation', ['harnessId' => $harnessId]),
                'challengeToken' => 'harness-creq',
            ])
            ->header('Cache-Control', 'no-store');
    }

    public function reactComponentHarness(Request $request)
    {
        $customer = $request->user()->customer;
        $harnessId = (string) $request->query('harness', Str::uuid());
        $acsUrl = route('local.3ds.fake-acs', ['harness' => $harnessId]);

        $session = Efevoo3dsSession::create([
            'customer_id' => $customer->id,
            'order_id' => 'HARNESS-'.Str::upper(Str::random(8)),
            'card_last_four' => '0000',
            'amount' => 1.50,
            'status' => 'pending',
            'url_3dsecure' => $acsUrl,
            'token_3dsecure' => 'harness-creq',
        ]);

        $attempt = PaymentAuthenticationAttempt::create([
            'attempt_uuid' => (string) Str::uuid(),
            'support_reference' => 'HARNESS-'.$session->id,
            'customer_id' => $customer->id,
            'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
            'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
            'status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value,
            'merchant_reference' => 'EFV3DS-HARNESS-'.$session->id,
            'efevoo_3ds_session_id' => $session->id,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);

        $session->update([
            'payment_authentication_attempt_id' => $attempt->id,
        ]);

        Cache::put(self::harnessSessionKey($session->id), [
            'harness_id' => $harnessId,
            'observation_url' => route('local.3ds.observation', ['harnessId' => $harnessId]),
        ], now()->addMinutes(15));

        return Inertia::render('PaymentMethods/ThreeDSRedirect', [
            'sessionId' => $session->id,
            'url3ds' => $session->url_3dsecure,
            'token3ds' => $session->token_3dsecure,
            'authenticationAttempt' => PaymentAuthentication3dsStartResource::make($attempt->fresh(), $session->fresh()),
        ]);
    }

    public function fakeAcs(Request $request)
    {
        $harnessId = (string) $request->query('harness', 'anonymous');
        $key = $this->cacheKey($harnessId);
        $current = Cache::get($key, [
            'post_count' => 0,
            'has_creq_field' => false,
        ]);

        $hasCreq = $request->has('creq');

        Cache::put($key, [
            'post_count' => (int) $current['post_count'] + 1,
            'has_creq_field' => $hasCreq || (bool) $current['has_creq_field'],
            'method' => $request->method(),
        ], now()->addMinutes(15));

        return response()
            ->view('local.three-ds-fake-acs', [
                'received' => true,
            ])
            ->header('Cache-Control', 'no-store');
    }

    public function observation(string $harnessId)
    {
        $payload = Cache::get($this->cacheKey($harnessId), [
            'post_count' => 0,
            'has_creq_field' => false,
        ]);

        return response()->json([
            'post_count' => (int) ($payload['post_count'] ?? 0),
            'has_creq_field' => (bool) ($payload['has_creq_field'] ?? false),
            'method' => $payload['method'] ?? null,
        ]);
    }

    public static function harnessSessionKey(int $sessionId): string
    {
        return 'local_3ds_harness_session:'.hash('sha256', (string) $sessionId);
    }

    private function cacheKey(string $harnessId): string
    {
        return 'local_3ds_acs:'.hash('sha256', $harnessId);
    }
}
