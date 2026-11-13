<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerCredential;
use App\Models\ApiConsumerDelegation;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\User;
use App\Services\Api\V1\Auth\ConsumerLifecycle;
use App\Services\Api\V1\Auth\ConsumerMachineCredentialIssuer;
use App\Services\Api\V1\Auth\ConsumerRegistryWriter;
use App\Services\Api\V1\Uat\AkubicaUatFixturePlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

final class RunAkubicaUatCommand extends Command
{
    protected $signature = 'akubica:uat
        {--dry-run : Validate the QA preconditions without writes}
        {--apply : Provision the temporary registry records, then stop before OTP/Bearer issuance}
        {--full : Run the sequential OTP/Bearer UAT flow after provisioning}
        {--allow-sms : Explicitly allow OTP SMS in a real QA run}
        {--recover : Revoke records from the last interrupted apply}
        {--confirm= : Exact namespace confirmation required by apply/recover}
        {--confirm-sms= : Exact SMS authorization phrase required by full}
        {--expected-sha= : Expected deployed commit SHA}
        {--base-url= : QA base URL for full}
        {--scenarios=B08,B09,B10,B11 : Comma-separated membership scenarios}';

    protected $description = 'Controlled RC2 UAT provisioning; fails closed when Bearer issuance needs OTP.';

    private const STATE_PATH = 'akubica-uat-v1/uat-session.json';
    private const CAPABILITIES = ['memberships', 'cart', 'cart:membership'];

    public function handle(
        ConsumerRegistryWriter $registry,
        ConsumerMachineCredentialIssuer $issuer,
        ConsumerLifecycle $lifecycle,
    ): int {
        $stage = 'mode';
        $mode = $this->mode();
        if ($mode === null) {
            return self::FAILURE;
        }

        if (! app()->environment(['staging', 'testing'])) {
            return $this->failWithCode('UAT_ENVIRONMENT_NOT_ALLOWED', 'environment');
        }

        if ((string) ($this->option('confirm') ?? '') !== ''
            && (string) $this->option('confirm') !== AkubicaUatFixtureContract::NAMESPACE) {
            return $this->failWithCode('UAT_CONFIRMATION_MISMATCH', 'confirmation');
        }

        try {
            $stage = 'assertSha';
            $this->assertSha();
            $stage = 'buildPlan';
            $plan = (new AkubicaUatFixturePlanner())->buildPlan('dry-run', false);
            $stage = 'manifest';
            $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $plan->namespace)->first();
            if (! $manifest || $manifest->status !== AkubicaUatFixtureContract::STATUS_ACTIVE) {
                return $this->failWithCode('UAT_FIXTURES_NOT_ACTIVE', $stage);
            }

            $stage = 'configuredUser';
            $operator = $this->configuredUser('AKUBICA_UAT_OPERATOR_ID');
            $stage = 'scenarios';
            $scenarios = $this->scenarios();
            if ($scenarios === []) {
                return $this->failWithCode('UAT_SCENARIOS_INVALID', $stage);
            }
            $stage = 'manifestUsers';
            $users = $this->manifestUsers($manifest, $scenarios);
            if (count($users) !== count($scenarios)) {
                return $this->failWithCode('UAT_MEMBERSHIP_USERS_INCOMPLETE', $stage);
            }

            if ($mode === 'dry-run') {
                $this->line(json_encode([
                    'status' => 'ok',
                    'action' => 'dry-run',
                    'namespace' => $plan->namespace,
                    'operator_id' => $operator?->id,
                    'scenarios' => $scenarios,
                    'membership_users' => count($users),
                    'machine_credential' => 'planned',
                    'delegations' => 4,
                    'bearer_flow' => 'blocked_without_approved_synthetic_otp',
                    'sms' => 0,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            if ($mode === 'recover') {
                return $this->recover($lifecycle, $operator);
            }

            if ($mode === 'full' && ((string) $this->option('confirm-sms') !== 'RC2-UAT-SMS' || ! (bool) $this->option('allow-sms'))) {
                return $this->failWithCode('UAT_SMS_AUTHORIZATION_REQUIRED', 'authorization');
            }

            if ($operator === null) {
                return $this->failWithCode('UAT_OPERATOR_NOT_CONFIGURED', 'configuredUser');
            }

            if ((string) ($this->option('confirm') ?? '') !== AkubicaUatFixtureContract::NAMESPACE) {
                return $this->failWithCode('UAT_APPLY_CONFIRMATION_REQUIRED', 'confirmation');
            }

            $stage = 'apply';
            return $this->apply($registry, $issuer, $lifecycle, $operator, $users, $mode === 'full');
        } catch (Throwable $e) {
            $correlationId = (string) Str::uuid();
            $errorCode = 'UAT_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $stage)).'_FAILED';
            try {
                Log::warning('akubica_uat_precondition_failed', [
                    'stage' => $stage,
                    'exception_type' => $e::class,
                    'correlation_id' => $correlationId,
                    'error_code' => $errorCode,
                ]);
            } catch (Throwable) {
                // Console evidence remains available even when the local log sink is unavailable.
            }
            $this->line(json_encode([
                'status' => 'error',
                'stage' => $stage,
                'exception_type' => $e::class,
                'correlation_id' => $correlationId,
                'error_code' => $errorCode,
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    private function apply(
        ConsumerRegistryWriter $registry,
        ConsumerMachineCredentialIssuer $issuer,
        ConsumerLifecycle $lifecycle,
        User $operator,
        array $users,
        bool $full,
    ): int {
        $expiresAt = Carbon::now()->addMinutes((int) env('AKUBICA_UAT_REGISTRY_TTL_MINUTES', 45));
        $consumer = null;
        $channel = null;
        $credential = null;
        $delegations = [];
        $pat = null;
        $stage = 'registerConsumer';

        try {
            $stage = 'registerConsumer';
            $consumer = $registry->register($operator, 'leo', self::CAPABILITIES, $expiresAt);
            $stage = 'registerChannel';
            $channel = $registry->channel($operator, $consumer, $expiresAt);
            $stage = 'enableConsumer';
            $lifecycle->transition($operator, $consumer, true, 'RC2_UAT_ENABLE');
            $stage = 'enableChannel';
            $lifecycle->transition($operator, $channel, true, 'RC2_UAT_ENABLE');

            foreach ($users as $user) {
                $stage = 'registerDelegation';
                $delegation = $registry->delegate($operator, $channel, $user, self::CAPABILITIES, $expiresAt);
                $stage = 'enableDelegation';
                $lifecycle->transition($operator, $delegation, true, 'RC2_UAT_ENABLE');
                $delegations[] = $delegation;
            }

            $stage = 'issueMachineCredential';
            $issued = $issuer->issue($operator, $channel, $expiresAt);
            $credential = $issued['credential'];
            $pat = $issued['token']->accessToken;
            $stage = 'writeRecoveryState';
            $this->writeState($consumer, $channel, $credential, $delegations, $pat);

            if (! $full) {
                $stage = 'otpAuthorization';
                throw new \DomainException('UAT_BEARER_FLOW_REQUIRES_APPROVED_OTP');
            }

            $stage = 'fullUat';
            $results = $this->runFull($users, $issued['token']->plainTextToken, $this->scenarios());
            $stage = 'cleanup';
            $this->cleanup($lifecycle, $operator, $consumer, $channel, $credential, $delegations, $pat);
            $this->deleteState();
            $this->line(json_encode(['status' => 'ok', 'action' => 'full', 'results' => $results, 'cleanup' => 'complete'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $cleanupError = null;
            try {
                $this->cleanup($lifecycle, $operator, $consumer, $channel, $credential, $delegations, $pat);
                $this->deleteState();
            } catch (Throwable $cleanupFailure) {
                $cleanupError = 'UAT_CLEANUP_FAILED';
            }
            $errorCode = $e instanceof \DomainException
                && $e->getMessage() === 'UAT_BEARER_FLOW_REQUIRES_APPROVED_OTP'
                    ? $e->getMessage()
                    : 'UAT_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $stage)).'_FAILED';
            $correlationId = (string) Str::uuid();
            try {
                Log::warning('akubica_uat_apply_failed', [
                    'stage' => $stage,
                    'exception_type' => $e::class,
                    'correlation_id' => $correlationId,
                    'error_code' => $errorCode,
                    'cleanup' => $cleanupError === null ? 'complete' : $cleanupError,
                ]);
            } catch (Throwable) {
                // Console evidence remains available even when the local log sink is unavailable.
            }
            $this->line(json_encode([
                'status' => 'blocked',
                'stage' => $stage,
                'exception_type' => $e::class,
                'correlation_id' => $correlationId,
                'error_code' => $errorCode,
                'credentials_revoked' => $credential !== null ? 1 : 0,
                'delegations_revoked' => count($delegations),
                'sms' => 0,
                'bearers' => 0,
                'cleanup' => $cleanupError === null ? 'complete' : $cleanupError,
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    private function recover(ConsumerLifecycle $lifecycle, ?User $operator): int
    {
        $path = Storage::disk('local')->path(self::STATE_PATH);
        if (! is_file($path)) {
            return $this->failWithCode('UAT_RECOVERY_STATE_NOT_FOUND');
        }
        if ($operator === null) {
            return $this->failWithCode('UAT_OPERATOR_NOT_CONFIGURED');
        }
        $state = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $consumer = ApiConsumer::query()->find((int) ($state['consumer_id'] ?? 0));
        $channel = ApiConsumerChannel::query()->find((int) ($state['channel_id'] ?? 0));
        $credential = ApiConsumerCredential::query()->find((int) ($state['credential_id'] ?? 0));
        $delegations = ApiConsumerDelegation::query()->whereIn('id', array_map('intval', (array) ($state['delegation_ids'] ?? [])))->get()->all();
        $pat = PersonalAccessToken::query()->find((int) ($state['personal_access_token_id'] ?? 0));
        foreach (array_map('intval', (array) ($state['bearer_ids'] ?? [])) as $bearerId) {
            PersonalAccessToken::query()->whereKey($bearerId)->delete();
        }
        try {
            $this->cleanup($lifecycle, $operator, $consumer, $channel, $credential, $delegations, $pat);
            $this->deleteState();
        } catch (Throwable) {
            return $this->failWithCode('UAT_CLEANUP_FAILED');
        }
        $this->line('{"status":"ok","action":"recover","sms":0,"cleanup":"complete"}');

        return self::SUCCESS;
    }

    private function cleanup(ConsumerLifecycle $lifecycle, User $operator, ?ApiConsumer $consumer, ?ApiConsumerChannel $channel, ?ApiConsumerCredential $credential, array $delegations, ?PersonalAccessToken $pat): void
    {
        foreach ([$credential, ...array_reverse($delegations), $channel, $consumer] as $record) {
            if ($record !== null && $record->exists && $record->enabled) {
                $lifecycle->transition($operator, $record, false, 'RC2_UAT_CLEANUP');
            }
        }
        if ($pat?->exists) {
            $pat->delete();
        }
    }

    private function writeState(ApiConsumer $consumer, ApiConsumerChannel $channel, ApiConsumerCredential $credential, array $delegations, PersonalAccessToken $pat): void
    {
        Storage::disk('local')->put(self::STATE_PATH, json_encode([
            'namespace' => AkubicaUatFixtureContract::NAMESPACE,
            'consumer_id' => $consumer->id,
            'channel_id' => $channel->id,
            'credential_id' => $credential->id,
            'delegation_ids' => array_map(fn ($d) => $d->id, $delegations),
            'personal_access_token_id' => $pat->id,
            'bearer_ids' => [],
        ], JSON_THROW_ON_ERROR));
    }

    private function appendBearerState(int $id): void
    {
        $path = Storage::disk('local')->path(self::STATE_PATH);
        if (! is_file($path)) {
            throw new \DomainException('UAT_RECOVERY_STATE_MISSING');
        }
        $state = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $state['bearer_ids'] = array_values(array_unique(array_merge((array) ($state['bearer_ids'] ?? []), [$id])));
        Storage::disk('local')->put(self::STATE_PATH, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function deleteState(): void
    {
        Storage::disk('local')->delete(self::STATE_PATH);
    }

    private function configuredUser(string $env): ?User
    {
        $id = (int) env($env, 0);

        return $id > 0 ? User::query()->find($id) : null;
    }

    private function manifestUsers(AkubicaUatFixtureManifest $manifest, array $scenarios): array
    {
        $roles = [
            'B08' => 'membership_none',
            'B09' => 'membership_regular',
            'B10' => 'membership_institutional',
            'B11' => 'membership_expired',
        ];
        $ids = collect($scenarios)
            ->map(fn (string $scenario) => data_get($manifest->metadata, 'ids.users.'.($roles[$scenario] ?? '')))
            ->filter()
            ->values()
            ->all();
        return User::query()->whereIn('id', array_map('intval', $ids))->get()->filter(fn (User $u) => $u->customer()->count() === 1)->values()->all();
    }

    /** @return list<string> */
    private function scenarios(): array
    {
        $allowed = ['B08', 'B09', 'B10', 'B11'];
        $raw = (string) $this->option('scenarios');
        $scenarios = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => strtoupper(trim($value)),
            explode(',', $raw),
        ))));

        if (array_diff($scenarios, $allowed) !== []) {
            return [];
        }

        return $scenarios;
    }

    /** @return list<array<string, mixed>> */
    private function runFull(array $users, string $machineCredential, array $scenarios): array
    {
        $baseUrl = rtrim((string) ($this->option('base-url') ?: env('AKUBICA_UAT_BASE_URL')), '/');
        if ($baseUrl !== 'https://famedic-otp.on-forge.com') {
            throw new \DomainException('UAT_BASE_URL_NOT_AUTHORIZED');
        }
        $keys = config('atix.sandbox.keys', []);
        $keyId = is_array($keys) ? (string) array_key_first($keys) : '';
        $secret = is_array($keys) ? (string) ($keys[$keyId] ?? '') : '';
        if ($keyId === '' || $secret === '') {
            throw new \DomainException('UAT_ATIX_NOT_CONFIGURED');
        }

        $results = [];
        foreach ($users as $index => $user) {
            $payload = [
                'phone' => (string) $user->phone,
                'phone_country' => (string) ($user->phone_country ?: 'MX'),
                'conversation_id' => 'rc2-uat-'.Str::lower(Str::random(20)),
                'channel_event_id' => Str::lower(Str::random(24)),
                'channel' => 'whatsapp',
                'audience' => 'leo',
                'personalization' => ['allowed' => false],
            ];
            $atix = $this->postAtix($baseUrl, $payload, $keyId, $secret);
            if ($atix['status'] !== 202) {
                throw new \DomainException('UAT_ATIX_UNEXPECTED_HTTP');
            }

            $authHeaders = [
                'Accept' => 'application/json',
                'X-Consumer-Credential' => $machineCredential,
            ];
            $request = $this->requestJson('POST', $baseUrl.'/api/v1/auth/login/request-code', $payload, $authHeaders + ['Idempotency-Key' => Str::uuid()->toString()]);
            if ($request['status'] !== 202 || ! is_string($request['json']['data']['challenge_id'] ?? null)) {
                throw new \DomainException('UAT_OTP_REQUEST_UNEXPECTED');
            }

            $code = $this->readHidden('OTP for identity '.($index + 1));
            $verify = $this->requestJson('POST', $baseUrl.'/api/v1/auth/login/verify-code', [
                'challenge_id' => $request['json']['data']['challenge_id'],
                'code' => $code,
            ], $authHeaders);
            if ($verify['status'] !== 200 || (($verify['json']['data']['otp_verified'] ?? false) !== true) || ! is_string($verify['json']['data']['token'] ?? null)) {
                throw new \DomainException('UAT_OTP_VERIFY_UNEXPECTED');
            }

            $bearer = $verify['json']['data']['token'];
            $bearerPat = PersonalAccessToken::findToken($bearer);
            if ($bearerPat === null) {
                throw new \DomainException('UAT_BEARER_NOT_PERSISTED');
            }
            $this->appendBearerState($bearerPat->id);
            $headers = $authHeaders + ['Authorization' => 'Bearer '.$bearer];
            $membership = $this->requestJson('GET', $baseUrl.'/api/v1/memberships/status', null, $headers);
            if ($membership['status'] !== 200) {
                throw new \DomainException('UAT_MEMBERSHIP_UNEXPECTED_HTTP');
            }
            $row = ['scenario' => $scenarios[$index] ?? 'UNKNOWN', 'membership_http' => $membership['status']];
            if (($scenarios[$index] ?? null) === 'B08') {
                $membershipPayload = [
                    'service_line' => 'medical_services',
                    'item_type' => 'membership',
                    'membership_plan_code' => 'basic_annual',
                    'quantity' => 1,
                ];
                $added = $this->requestJson('POST', $baseUrl.'/api/v1/cart/items', $membershipPayload, $headers + ['Idempotency-Key' => Str::uuid()->toString()]);
                if ($added['status'] !== 201) {
                    throw new \DomainException('UAT_CART_ADD_UNEXPECTED_HTTP');
                }
                $totals = $this->requestJson('GET', $baseUrl.'/api/v1/cart/totals?service_line=medical_services', null, $headers);
                if ($totals['status'] !== 200) {
                    throw new \DomainException('UAT_CART_TOTALS_UNEXPECTED_HTTP');
                }
                $duplicate = $this->requestJson('POST', $baseUrl.'/api/v1/cart/items', $membershipPayload, $headers + ['Idempotency-Key' => Str::uuid()->toString()]);
                if ($duplicate['status'] !== 409) {
                    throw new \DomainException('UAT_CART_DUPLICATE_UNEXPECTED_HTTP');
                }
                $cart = $this->requestJson('GET', $baseUrl.'/api/v1/cart?service_line=medical_services', null, $headers);
                $itemId = $cart['json']['data']['items'][0]['id'] ?? null;
                if ($cart['status'] !== 200 || ! is_numeric($itemId)) {
                    throw new \DomainException('UAT_CART_ITEM_NOT_FOUND');
                }
                $removed = $this->requestJson('DELETE', $baseUrl.'/api/v1/cart/items/'.(int) $itemId, null, $headers + ['Idempotency-Key' => Str::uuid()->toString()]);
                if ($removed['status'] !== 200) {
                    throw new \DomainException('UAT_CART_REMOVE_UNEXPECTED_HTTP');
                }
                $finalTotals = $this->requestJson('GET', $baseUrl.'/api/v1/cart/totals?service_line=medical_services', null, $headers);
                if ($finalTotals['status'] !== 200 || (int) ($finalTotals['json']['data']['item_count'] ?? -1) !== 0) {
                    throw new \DomainException('UAT_CART_FINAL_STATE_UNEXPECTED');
                }
                $row['cart'] = ['add_http' => 201, 'totals_http' => 200, 'duplicate_http' => 409, 'remove_http' => 200, 'final_totals_http' => 200];
            }
            $revoked = $this->requestJson('DELETE', $baseUrl.'/api/v1/auth/token', null, $headers);
            if ($revoked['status'] !== 200 || (($revoked['json']['data']['revoked'] ?? false) !== true)) {
                throw new \DomainException('UAT_BEARER_REVOKE_UNEXPECTED');
            }
            $row['bearer_revoke_http'] = $revoked['status'];
            $results[] = $row;
        }

        return $results;
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function requestJson(string $method, string $url, ?array $body, array $headers): array
    {
        $request = Http::withOptions(['http_errors' => false, 'allow_redirects' => false, 'timeout' => 20])->withHeaders($headers)->acceptJson();
        $response = $body === null ? $request->{$method}($url) : $request->{$method}($url, $body);
        $json = $response->json();
        if (! is_array($json)) {
            throw new \DomainException('UAT_NON_JSON_RESPONSE');
        }
        return ['status' => $response->status(), 'json' => $json];
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function postAtix(string $baseUrl, array $payload, string $keyId, string $secret): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::lower(Str::random(24));
        $canonical = implode("\n", ['POST', '/api/v1/integrations/atix/phone-lookups', $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac((string) config('atix.sandbox.algorithm', 'sha256'), $canonical, $secret);
        $response = Http::withOptions(['http_errors' => false, 'allow_redirects' => false, 'timeout' => 20])
            ->withHeaders(['X-Correlation-ID' => Str::uuid()->toString(), 'X-Integration-Key-Id' => $keyId, 'X-Timestamp' => $timestamp, 'X-Nonce' => $nonce, 'X-Signature' => $signature, 'Idempotency-Key' => Str::uuid()->toString(), 'Accept' => 'application/json'])
            ->withBody($body, 'application/json')
            ->post($baseUrl.'/api/v1/integrations/atix/phone-lookups');
        $json = $response->json();
        if (! is_array($json)) {
            throw new \DomainException('UAT_ATIX_NON_JSON_RESPONSE');
        }
        return ['status' => $response->status(), 'json' => $json];
    }

    private function readHidden(string $label): string
    {
        if (app()->environment('testing') && (string) env('AKUBICA_UAT_FAKE_OTP') !== '') {
            return (string) env('AKUBICA_UAT_FAKE_OTP');
        }
        $this->output->write($label.': ');
        $state = trim((string) shell_exec('stty -g 2>/dev/null'));
        try {
            shell_exec('stty -echo 2>/dev/null');
            $value = trim((string) fgets(STDIN));
            $this->output->writeln('');
            return $value;
        } finally {
            if ($state !== '') {
                shell_exec('stty '.$state.' 2>/dev/null');
            }
        }
    }

    private function assertSha(): void
    {
        $expected = trim((string) ($this->option('expected-sha') ?: env('AKUBICA_UAT_EXPECTED_SHA')));
        $actual = trim((string) env('APP_COMMIT_SHA'));
        if ($expected === '' || $actual === '' || ! hash_equals($expected, $actual)) {
            throw new \DomainException('UAT_COMMIT_NOT_VERIFIABLE');
        }
    }

    private function mode(): ?string
    {
        $selected = array_filter(['dry-run' => $this->option('dry-run'), 'apply' => $this->option('apply'), 'full' => $this->option('full'), 'recover' => $this->option('recover')]);
        if (count($selected) !== 1) {
            $this->line('{"status":"error","error_code":"UAT_MODE_REQUIRED"}');
            return null;
        }

        return array_key_first($selected);
    }

    private function failWithCode(string $code, string $stage = 'validation'): int
    {
        $correlationId = (string) Str::uuid();
        try {
            Log::warning('akubica_uat_precondition_rejected', [
                'stage' => $stage,
                'exception_type' => 'controlled',
                'correlation_id' => $correlationId,
                'error_code' => $code,
            ]);
        } catch (Throwable) {
            // Console evidence remains available even when the local log sink is unavailable.
        }
        $this->line(json_encode([
            'status' => 'error',
            'stage' => $stage,
            'exception_type' => 'controlled',
            'correlation_id' => $correlationId,
            'error_code' => $code,
        ], JSON_THROW_ON_ERROR));
        return self::FAILURE;
    }
}
