<?php

namespace App\Services\Api\V1\Uat;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerCredential;
use App\Models\ApiConsumerDelegation;
use App\Models\ApiConsumerTokenBinding;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\User;
use App\Services\Api\V1\Auth\ConsumerLifecycle;
use App\Services\Api\V1\Auth\ConsumerMachineCredentialIssuer;
use App\Services\Api\V1\Auth\ConsumerRegistryWriter;
use App\Services\Api\V1\Auth\ConsumerTokenBindingWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

final class AkubicaUatAccessProvisioner
{
    private const APPROVAL = 'RC2_UAT_ACCESS';

    /** @var array<string, list<string>> */
    private const CAPABILITIES = [
        'native' => [
            'memberships:read', 'cart:read', 'cart:laboratory:read',
            'cart:laboratory:write', 'checkout:prepare', 'cart:membership:write',
            'cart:write',
        ],
        'leo' => [
            'memberships:read', 'cart:read', 'cart:laboratory:read',
            'cart:laboratory:write', 'checkout:prepare', 'cart:membership:write',
            'api:operation:api:v1:orders:index',
            'api:operation:api:v1:orders:status',
            'api:operation:api:v1:orders:products',
            'api:operation:api:v1:user:family',
            'api:operation:api:v1:orders:results:index',
            'api:operation:api:v1:orders:results',
            'api:operation:api:v1:orders:invoices:index',
            'api:operation:api:v1:orders:invoices',
            'api:operation:api:v1:user:tax-profiles',
            'api:operation:api:v1:orders:results:step-up:request',
            'api:operation:api:v1:orders:results:step-up:verify',
            'api:operation:api:v1:orders:results:secure-link',
            'api:operation:api:v1:orders:invoices:step-up:request',
            'api:operation:api:v1:orders:invoices:step-up:verify',
            'api:operation:api:v1:orders:invoices:secure-link',
            'api:operation:api:v1:orders:invoice-request:status',
            'api:operation:api:v1:orders:invoice-request:store',
        ],
    ];

    private const WINDOW2_SCENARIO = 'functional-window-2';

    /** @var array<string, list<string>> */
    private const WINDOW2_CAPABILITIES = [
        'native_membership_regular' => [
            'memberships:read', 'cart:read', 'cart:membership:write',
        ],
        'leo_membership_none' => [
            'memberships:read', 'cart:read', 'cart:laboratory:read', 'cart:membership:write',
            'checkout:prepare', 'api:operation:api:v1:orders:index',
            'api:operation:api:v1:orders:status', 'api:operation:api:v1:user:family',
        ],
    ];

    public function dryRun(string $namespace, ?User $operator, string $scenario = ''): array
    {
        $context = $this->context($namespace);
        $authorized = $operator !== null && Gate::forUser($operator)->allows('manageApiConsumerRegistry');

        $scenarioIdentities = $scenario === self::WINDOW2_SCENARIO
            ? $this->scenarioContext($namespace)['user_ids']
            : [];

        return [
            'namespace' => $namespace,
            'operator_authorized' => $authorized,
            'identities' => $context['identities'],
            'scenario_identities' => collect($scenarioIdentities)->mapWithKeys(fn (int $id, string $role): array => [$role => 'UAT-'.$namespace.'-'.$role.'-U'.$id])->all(),
            'consumers' => collect(self::CAPABILITIES)->map(fn (array $caps, string $kind): array => [
                'alias' => $namespace.'-'.$kind,
                'kind' => $kind,
                'capabilities' => $caps,
                'laboratory_brands' => ['olab'],
                'delegations' => ['primary', 'foreign'],
            ])->values()->all(),
            'scenario' => $scenario,
            'scenario_bindings' => $scenario === self::WINDOW2_SCENARIO ? [
                ['alias' => $namespace.'-native-membership-regular', 'profile' => 'native', 'role' => 'membership_regular', 'capabilities' => self::WINDOW2_CAPABILITIES['native_membership_regular'], 'laboratory_brands' => []],
                ['alias' => $namespace.'-leo-membership-none', 'profile' => 'leo', 'role' => 'membership_none', 'capabilities' => self::WINDOW2_CAPABILITIES['leo_membership_none'], 'laboratory_brands' => ['swisslab']],
            ] : [],
            'effects' => [
                'creates' => ['2 consumers', '2 channels', '4 delegations', '2 machine credentials', '4 delegated bindings'],
                'external_calls' => 0,
                'sms' => 0,
                'email' => 0,
                'payments' => 0,
                'scenario_delegations' => $scenario === self::WINDOW2_SCENARIO ? 2 : 0,
                'scenario_bindings' => $scenario === self::WINDOW2_SCENARIO ? 2 : 0,
                'scenario_tokens' => $scenario === self::WINDOW2_SCENARIO ? 2 : 0,
            ],
            'credentials' => 'created only by apply; plaintext never emitted',
            'expires_at' => Carbon::now()->addMinutes($this->ttlMinutes())->toIso8601String(),
        ];
    }

    public function apply(string $namespace, User $operator, string $scenario = ''): array
    {
        $this->assertNamespace($namespace);
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');
        $state = $this->readState($namespace);
        if ($state !== null) {
            $this->assertState($state, $namespace);
            if (($state['scenario'] ?? '') !== $scenario) throw new \DomainException('UAT_ACCESS_SCENARIO_MISMATCH');

            return ['status' => 'idempotent', 'namespace' => $namespace, 'scenario' => $scenario, 'created' => 0, 'active_credentials' => count($state['token_ids'])];
        }

        $context = $this->context($namespace);
        $this->assertNoUnownedAccess($context['user_ids']);
        $expiresAt = Carbon::now()->addMinutes($this->ttlMinutes());
        $created = ['consumers' => [], 'channels' => [], 'delegations' => [], 'credentials' => [], 'bindings' => [], 'tokens' => [], 'secrets' => [], 'scenario_delegations' => [], 'scenario_bindings' => [], 'scenario_tokens' => [], 'scenario_secrets' => []];
        $registry = app(ConsumerRegistryWriter::class);
        $issuer = app(ConsumerMachineCredentialIssuer::class);
        $bindingWriter = app(ConsumerTokenBindingWriter::class);
        $lifecycle = app(ConsumerLifecycle::class);

        try {
            foreach (self::CAPABILITIES as $kind => $caps) {
                $consumer = $registry->register($operator, $kind, $caps, $expiresAt);
                $created['consumers'][$kind] = $consumer->id;
                $consumer->refresh();
                $effectiveExpiry = Carbon::parse($consumer->expires_at);
                $channel = $registry->channel($operator, $consumer, $effectiveExpiry);
                $created['channels'][$kind] = $channel->id;
                $lifecycle->transition($operator, $consumer, true, self::APPROVAL);
                $lifecycle->transition($operator, $channel, true, self::APPROVAL);
                $issuedMachine = $issuer->issue($operator, $channel, $effectiveExpiry);
                $created['credentials'][$kind] = $issuedMachine['credential']->id;
                $created['tokens']['machine_'.$kind] = $issuedMachine['token']->accessToken->id;
                $created['secrets']['machine_'.$kind] = Crypt::encryptString($issuedMachine['token']->plainTextToken);

                foreach ($context['user_ids'] as $role => $userId) {
                    $user = User::query()->findOrFail($userId);
                    $delegation = $registry->delegate($operator, $channel, $user, $caps, $effectiveExpiry);
                    $lifecycle->transition($operator, $delegation, true, self::APPROVAL);
                    $created['delegations'][$kind][$role] = $delegation->id;
                    $token = $user->createToken($namespace.'-'.$kind, $caps, $effectiveExpiry);
                    $created['tokens'][$kind.'_'.$role] = $token->accessToken->id;
                    $binding = $bindingWriter->bind($operator, $token->accessToken, $delegation, $caps, $effectiveExpiry, ['olab']);
                    $created['bindings'][$kind][$role] = $binding->id;
                    $created['secrets'][$kind.'_'.$role] = Crypt::encryptString($token->plainTextToken);
                }
            }

            if ($scenario === self::WINDOW2_SCENARIO) {
                $scenarioContext = $this->scenarioContext($namespace);
                $this->assertNoUnownedAccess(array_values($scenarioContext['user_ids']));
                foreach ([
                    ['key' => 'native_membership_regular', 'kind' => 'native', 'role' => 'membership_regular', 'caps' => self::WINDOW2_CAPABILITIES['native_membership_regular'], 'brands' => []],
                    ['key' => 'leo_membership_none', 'kind' => 'leo', 'role' => 'membership_none', 'caps' => self::WINDOW2_CAPABILITIES['leo_membership_none'], 'brands' => ['swisslab']],
                ] as $definition) {
                    $kind = $definition['kind'];
                    $role = $definition['role'];
                    $user = User::query()->findOrFail($scenarioContext['user_ids'][$role]);
                    $channel = ApiConsumerChannel::query()->findOrFail($created['channels'][$kind]);
                    $consumer = ApiConsumer::query()->findOrFail($created['consumers'][$kind]);
                    $effectiveScenarioExpiry = Carbon::parse($consumer->expires_at);
                    $delegation = $registry->delegate($operator, $channel, $user, $definition['caps'], $effectiveScenarioExpiry);
                    $lifecycle->transition($operator, $delegation, true, self::APPROVAL);
                    $created['scenario_delegations'][$definition['key']] = $delegation->id;
                    $token = $user->createToken($namespace.'-'.$definition['key'], $definition['caps'], $effectiveScenarioExpiry);
                    $created['scenario_tokens'][$definition['key']] = $token->accessToken->id;
                    $created['scenario_secrets'][$definition['key']] = Crypt::encryptString($token->plainTextToken);
                    $binding = $bindingWriter->bind($operator, $token->accessToken, $delegation, $definition['caps'], $effectiveScenarioExpiry, $definition['brands']);
                    $created['scenario_bindings'][$definition['key']] = $binding->id;
                }
            }

            $state = [
                'namespace' => $namespace,
                'user_ids' => $context['user_ids'],
                'consumer_ids' => $created['consumers'],
                'channel_ids' => $created['channels'],
                'delegation_ids' => $created['delegations'],
                'credential_ids' => $created['credentials'],
                'binding_ids' => $created['bindings'],
                'token_ids' => $created['tokens'],
                'secret_hashes' => collect($created['secrets'])->map(fn (string $secret): string => hash('sha256', Crypt::decryptString($secret)))->all(),
                'encrypted_secrets' => $created['secrets'],
                'scenario' => $scenario,
                'scenario_delegation_ids' => $created['scenario_delegations'],
                'scenario_binding_ids' => $created['scenario_bindings'],
                'scenario_token_ids' => $created['scenario_tokens'],
                'scenario_encrypted_secrets' => $created['scenario_secrets'],
                'expires_at' => $expiresAt->toIso8601String(),
            ];
            $this->writeState($namespace, $state);

            return ['status' => 'ok', 'namespace' => $namespace, 'scenario' => $scenario, 'created' => ['consumers' => 2, 'channels' => 2, 'delegations' => 4 + count($created['scenario_delegations']), 'credentials' => 2, 'bindings' => 4 + count($created['scenario_bindings']), 'tokens' => count($created['tokens']) + count($created['scenario_tokens'])], 'external_calls' => 0];
        } catch (\Throwable $exception) {
            $this->revokeCreated($operator, $created, $lifecycle);
            $this->purgeCreated($created);
            throw $exception;
        }
    }

    public function reset(string $namespace, User $operator, string $scenario = ''): array
    {
        $this->assertNamespace($namespace);
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');
        $state = $this->readState($namespace);
        if ($state === null) {
            return ['status' => 'empty', 'namespace' => $namespace, 'deleted' => 0];
        }
        $this->assertState($state, $namespace);
        if (($state['scenario'] ?? '') !== $scenario) throw new \DomainException('UAT_ACCESS_SCENARIO_MISMATCH');
        $lifecycle = app(ConsumerLifecycle::class);
        foreach (array_reverse(array_values($state['scenario_binding_ids'] ?? [])) as $id) $this->revokeModel($operator, ApiConsumerTokenBinding::class, $id, $lifecycle);
        foreach (array_reverse(array_values($state['scenario_delegation_ids'] ?? [])) as $id) $this->revokeModel($operator, ApiConsumerDelegation::class, $id, $lifecycle);
        foreach ($state['scenario_token_ids'] ?? [] as $id) PersonalAccessToken::query()->whereKey($id)->delete();
        foreach (array_reverse(array_values($state['binding_ids'])) as $ids) foreach ($ids as $id) $this->revokeModel($operator, ApiConsumerTokenBinding::class, $id, $lifecycle);
        foreach (array_reverse(array_values($state['delegation_ids'])) as $ids) foreach ($ids as $id) $this->revokeModel($operator, ApiConsumerDelegation::class, $id, $lifecycle);
        foreach ($state['credential_ids'] as $id) $this->revokeModel($operator, ApiConsumerCredential::class, $id, $lifecycle);
        foreach ($state['token_ids'] as $id) PersonalAccessToken::query()->whereKey($id)->delete();
        foreach ($state['channel_ids'] as $id) $this->revokeModel($operator, ApiConsumerChannel::class, $id, $lifecycle);
        foreach ($state['consumer_ids'] as $id) $this->revokeModel($operator, ApiConsumer::class, $id, $lifecycle);
        $this->assertCleanupComplete($state);
        Storage::disk('local')->delete($this->statePath($namespace));

        return ['status' => 'ok', 'namespace' => $namespace, 'scenario' => $scenario, 'deleted' => 2 + 2 + 4 + 2 + 4 + 6 + count($state['scenario_binding_ids'] ?? []) + count($state['scenario_delegation_ids'] ?? []) + count($state['scenario_token_ids'] ?? [])];
    }

    /** @return array{user_ids: array<string,int>, identities: array<string,string>} */
    private function context(string $namespace): array
    {
        $this->assertNamespace($namespace);
        $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)->first();
        if (! $manifest) throw new \DomainException('UAT_ACCESS_FIXTURES_NOT_ACTIVE');
        $ids = (array) data_get($manifest->metadata, 'ids.users', []);
        $userIds = collect(['primary', 'foreign'])->mapWithKeys(fn (string $role): array => [$role => (int) ($ids[$role] ?? 0)])->all();
        if (in_array(0, $userIds, true)) throw new \DomainException('UAT_ACCESS_IDENTITIES_INCOMPLETE');
        return ['user_ids' => $userIds, 'identities' => collect($userIds)->mapWithKeys(fn (int $id, string $role): array => [$role => 'UAT-'.$namespace.'-'.$role.'-U'.$id])->all()];
    }

    /** @return array{user_ids: array<string,int>} */
    private function scenarioContext(string $namespace): array
    {
        $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)->firstOrFail();
        $ids = (array) data_get($manifest->metadata, 'ids.users', []);
        $userIds = ['membership_regular' => (int) ($ids['membership_regular'] ?? 0), 'membership_none' => (int) ($ids['membership_none'] ?? 0)];
        if (in_array(0, $userIds, true)) throw new \DomainException('UAT_ACCESS_SCENARIO_IDENTITIES_INCOMPLETE');
        return ['user_ids' => $userIds];
    }

    private function assertNoUnownedAccess(array $userIds): void
    {
        $exists = DB::table('api_consumer_delegations')->whereIn('user_id', array_values($userIds))->whereNull('revoked_at')->exists();
        if ($exists) throw new \DomainException('UAT_ACCESS_COLLISION_DELEGATION');
        $exists = DB::table('personal_access_tokens')->where(function ($query) use ($userIds): void {
            $query->whereIn('tokenable_id', array_values($userIds))
                ->where('tokenable_type', User::class)
                ->where(function ($expiry): void { $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
        })->exists();
        if ($exists) throw new \DomainException('UAT_ACCESS_COLLISION_TOKEN');
    }

    private function revokeCreated(User $operator, array $created, ConsumerLifecycle $lifecycle): void
    {
        foreach (array_reverse(array_values($created['scenario_bindings'])) as $id) $this->revokeModel($operator, ApiConsumerTokenBinding::class, $id, $lifecycle);
        foreach (array_reverse(array_values($created['scenario_delegations'])) as $id) $this->revokeModel($operator, ApiConsumerDelegation::class, $id, $lifecycle);
        foreach (array_reverse(array_values($created['bindings'])) as $ids) foreach ($ids as $id) $this->revokeModel($operator, ApiConsumerTokenBinding::class, $id, $lifecycle);
        foreach (array_reverse(array_values($created['delegations'])) as $ids) foreach ($ids as $id) $this->revokeModel($operator, ApiConsumerDelegation::class, $id, $lifecycle);
        foreach ($created['credentials'] as $id) $this->revokeModel($operator, ApiConsumerCredential::class, $id, $lifecycle);
        foreach ($created['tokens'] as $id) PersonalAccessToken::query()->whereKey($id)->delete();
        foreach ($created['scenario_tokens'] as $id) PersonalAccessToken::query()->whereKey($id)->delete();
        foreach ($created['channels'] as $id) $this->revokeModel($operator, ApiConsumerChannel::class, $id, $lifecycle);
        foreach ($created['consumers'] as $id) $this->revokeModel($operator, ApiConsumer::class, $id, $lifecycle);
    }

    private function purgeCreated(array $created): void
    {
        foreach (array_reverse(array_values($created['bindings'])) as $id) ApiConsumerTokenBinding::query()->whereKey($id)->delete();
        foreach (array_reverse(array_values($created['scenario_bindings'])) as $id) ApiConsumerTokenBinding::query()->whereKey($id)->delete();
        foreach (array_reverse(array_values($created['delegations'])) as $ids) foreach ($ids as $id) ApiConsumerDelegation::query()->whereKey($id)->delete();
        foreach (array_reverse(array_values($created['scenario_delegations'])) as $id) ApiConsumerDelegation::query()->whereKey($id)->delete();
        foreach ($created['credentials'] as $id) ApiConsumerCredential::query()->whereKey($id)->delete();
        foreach (array_merge(array_values($created['tokens']), array_values($created['scenario_tokens'])) as $id) PersonalAccessToken::query()->whereKey($id)->delete();
        foreach ($created['channels'] as $id) ApiConsumerChannel::query()->whereKey($id)->delete();
        foreach ($created['consumers'] as $id) ApiConsumer::query()->whereKey($id)->delete();
    }

    private function revokeModel(User $operator, string $class, int $id, ConsumerLifecycle $lifecycle): void
    {
        if ($record = $class::query()->find($id)) $lifecycle->transition($operator, $record, false, self::APPROVAL);
    }

    private function assertState(array $state, string $namespace): void
    {
        if (($state['namespace'] ?? null) !== $namespace || ! isset($state['consumer_ids'], $state['binding_ids'], $state['token_ids'])) throw new \DomainException('UAT_ACCESS_STATE_INVALID');
    }

    private function assertCleanupComplete(array $state): void
    {
        foreach ([
            ApiConsumer::class => $state['consumer_ids'],
            ApiConsumerChannel::class => $state['channel_ids'],
            ApiConsumerCredential::class => $state['credential_ids'],
        ] as $class => $ids) {
            if ($class::query()->whereIn('id', array_values($ids))->where(function ($query): void {
                $query->where('enabled', true)->orWhereNull('revoked_at');
            })->exists()) {
                throw new \DomainException('UAT_ACCESS_CLEANUP_FAILED');
            }
        }
        foreach ([
            ApiConsumerDelegation::class => array_merge($this->flattenIds($state['delegation_ids']), $this->flattenIds($state['scenario_delegation_ids'] ?? [])),
            ApiConsumerTokenBinding::class => array_merge($this->flattenIds($state['binding_ids']), $this->flattenIds($state['scenario_binding_ids'] ?? [])),
        ] as $class => $ids) {
            if ($class::query()->whereIn('id', $ids)->where(function ($query): void {
                $query->where('enabled', true)->orWhereNull('revoked_at');
            })->exists()) {
                throw new \DomainException('UAT_ACCESS_CLEANUP_FAILED');
            }
        }
        $tokenIds = array_merge(array_values($state['token_ids']), array_values($state['scenario_token_ids'] ?? []));
        if (PersonalAccessToken::query()->whereIn('id', $tokenIds)->exists()) {
            throw new \DomainException('UAT_ACCESS_CLEANUP_FAILED');
        }
    }

    /** @return list<int> */
    private function flattenIds(array $values): array
    {
        return collect($values)->flatten()->map(fn ($id): int => (int) $id)->filter()->values()->all();
    }

    private function readState(string $namespace): ?array
    {
        $path = $this->statePath($namespace);
        if (! Storage::disk('local')->exists($path)) return null;
        return json_decode((string) Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeState(string $namespace, array $state): void
    {
        Storage::disk('local')->put($this->statePath($namespace), json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function statePath(string $namespace): string { return AkubicaUatFixtureContract::storagePath('access-state.json', $namespace); }
    private function ttlMinutes(): int { return max(1, min(45, (int) config('akubica_uat.access_ttl_minutes', 30))); }
    private function assertNamespace(string $namespace): void { if ($namespace !== AkubicaUatFixtureContract::DEDICATED_NAMESPACE) throw new \DomainException('UAT_ACCESS_NAMESPACE_NOT_ALLOWED'); }
}
