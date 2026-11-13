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
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

final class RunAkubicaUatCommand extends Command
{
    protected $signature = 'akubica:uat
        {--dry-run : Validate the QA preconditions without writes}
        {--apply : Provision the temporary registry records, then stop before OTP/Bearer issuance}
        {--recover : Revoke records from the last interrupted apply}
        {--confirm= : Exact namespace confirmation required by apply/recover}
        {--expected-sha= : Expected deployed commit SHA}';

    protected $description = 'Controlled RC2 UAT provisioning; fails closed when Bearer issuance needs OTP.';

    private const STATE_PATH = 'akubica-uat-v1/uat-session.json';
    private const CAPABILITIES = ['memberships', 'cart', 'cart:membership'];

    public function handle(
        ConsumerRegistryWriter $registry,
        ConsumerMachineCredentialIssuer $issuer,
        ConsumerLifecycle $lifecycle,
    ): int {
        $mode = $this->mode();
        if ($mode === null) {
            return self::FAILURE;
        }

        if (! app()->environment(['staging', 'testing'])) {
            return $this->failWithCode('UAT_ENVIRONMENT_NOT_ALLOWED');
        }

        if ((string) ($this->option('confirm') ?? '') !== ''
            && (string) $this->option('confirm') !== AkubicaUatFixtureContract::NAMESPACE) {
            return $this->failWithCode('UAT_CONFIRMATION_MISMATCH');
        }

        try {
            $this->assertSha();
            $plan = (new AkubicaUatFixturePlanner())->buildPlan('dry-run', false);
            $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $plan->namespace)->first();
            if (! $manifest || $manifest->status !== AkubicaUatFixtureContract::STATUS_ACTIVE) {
                return $this->failWithCode('UAT_FIXTURES_NOT_ACTIVE');
            }

            $operator = $this->configuredUser('AKUBICA_UAT_OPERATOR_ID');
            $users = $this->manifestUsers($manifest);
            if (count($users) !== 4) {
                return $this->failWithCode('UAT_MEMBERSHIP_USERS_INCOMPLETE');
            }

            if ($mode === 'dry-run') {
                $this->line(json_encode([
                    'status' => 'ok',
                    'action' => 'dry-run',
                    'namespace' => $plan->namespace,
                    'operator_id' => $operator?->id,
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

            if ($operator === null) {
                return $this->failWithCode('UAT_OPERATOR_NOT_CONFIGURED');
            }

            if ((string) ($this->option('confirm') ?? '') !== AkubicaUatFixtureContract::NAMESPACE) {
                return $this->failWithCode('UAT_APPLY_CONFIRMATION_REQUIRED');
            }

            return $this->apply($registry, $issuer, $lifecycle, $operator, $users);
        } catch (Throwable $e) {
            $this->line(json_encode(['status' => 'error', 'error_code' => 'UAT_EXECUTOR_FAILED'], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    private function apply(
        ConsumerRegistryWriter $registry,
        ConsumerMachineCredentialIssuer $issuer,
        ConsumerLifecycle $lifecycle,
        User $operator,
        array $users,
    ): int {
        $expiresAt = Carbon::now()->addMinutes((int) env('AKUBICA_UAT_REGISTRY_TTL_MINUTES', 45));
        $consumer = null;
        $channel = null;
        $credential = null;
        $delegations = [];
        $pat = null;

        try {
            $consumer = $registry->register($operator, 'leo', self::CAPABILITIES, $expiresAt);
            $channel = $registry->channel($operator, $consumer, $expiresAt);
            $lifecycle->transition($operator, $consumer, true, 'RC2_UAT_ENABLE');
            $lifecycle->transition($operator, $channel, true, 'RC2_UAT_ENABLE');

            foreach ($users as $user) {
                $delegation = $registry->delegate($operator, $channel, $user, self::CAPABILITIES, $expiresAt);
                $lifecycle->transition($operator, $delegation, true, 'RC2_UAT_ENABLE');
                $delegations[] = $delegation;
            }

            $issued = $issuer->issue($operator, $channel, $expiresAt);
            $credential = $issued['credential'];
            $pat = $issued['token']->accessToken;
            $this->writeState($consumer, $channel, $credential, $delegations, $pat);

            // The deployed contract requires OTP for a user Bearer. This command
            // deliberately refuses to fabricate OTPs or bypass that contract.
            throw new \DomainException('UAT_BEARER_FLOW_REQUIRES_APPROVED_OTP');
        } catch (Throwable $e) {
            $cleanupError = null;
            try {
                $this->cleanup($lifecycle, $operator, $consumer, $channel, $credential, $delegations, $pat);
                $this->deleteState();
            } catch (Throwable $cleanupFailure) {
                $cleanupError = 'UAT_CLEANUP_FAILED';
            }
            $this->line(json_encode([
                'status' => 'blocked',
                'error_code' => $e->getMessage() === 'UAT_BEARER_FLOW_REQUIRES_APPROVED_OTP'
                    ? $e->getMessage()
                    : 'UAT_PROVISIONING_FAILED',
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
        ], JSON_THROW_ON_ERROR));
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

    private function manifestUsers(AkubicaUatFixtureManifest $manifest): array
    {
        $ids = (array) data_get($manifest->metadata, 'ids.membership_users', []);
        if ($ids === []) {
            $ids = collect(['membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'])
                ->map(fn (string $role) => data_get($manifest->metadata, "ids.users.{$role}"))
                ->filter()
                ->values()
                ->all();
        }
        return User::query()->whereIn('id', array_map('intval', $ids))->get()->filter(fn (User $u) => $u->customers()->count() === 1)->values()->all();
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
        $selected = array_filter(['dry-run' => $this->option('dry-run'), 'apply' => $this->option('apply'), 'recover' => $this->option('recover')]);
        if (count($selected) !== 1) {
            $this->line('{"status":"error","error_code":"UAT_MODE_REQUIRED"}');
            return null;
        }

        return array_key_first($selected);
    }

    private function failWithCode(string $code): int
    {
        $this->line(json_encode(['status' => 'error', 'error_code' => $code], JSON_THROW_ON_ERROR));
        return self::FAILURE;
    }
}
