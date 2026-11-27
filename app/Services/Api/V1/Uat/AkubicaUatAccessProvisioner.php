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
    private const WINDOW2_MEMBERSHIP_SCENARIO = 'functional-window-2-memberships';
    private const WINDOW2_CONTINUITY_SCENARIO = 'functional-window-2-continuity';

    /** @var array<string, list<string>> */
    private const WINDOW2_CAPABILITIES = [
        'native_membership_regular' => [
            'memberships:read', 'cart:read', 'cart:membership:write',
            'cart:laboratory:read', 'cart:laboratory:write',
        ],
        // B18 requires a dedicated native actor whose membership is never set.
        // Keep the exact native capability set; only the fixture role differs.
        'native_membership_none' => [
            'memberships:read', 'cart:read', 'cart:membership:write',
            'cart:laboratory:read', 'cart:laboratory:write', 'checkout:prepare',
        ],
        'leo_membership_none' => [
            'memberships:read', 'cart:read', 'cart:laboratory:read', 'cart:membership:write',
            'checkout:prepare', 'api:operation:api:v1:orders:index',
            'api:operation:api:v1:orders:status', 'api:operation:api:v1:user:family',
        ],
        // A4 only: exact capabilities required to prepare a laboratory cart.
        // Keep this separate from the swisslab negative profile.
        'leo_membership_none_olab' => [
            'checkout:prepare', 'cart:laboratory:read',
        ],
        // INT-028 (opt-in): the akubica-uat-v1 user whose phone the operator
        // controls only needs one Leo delegation so verify-code (A10) can
        // enroll it; A10r reads memberships/status with the issued Bearer.
        'leo_v1_otp_delegate' => [
            'memberships:read',
        ],
    ];

    private const V1_DELEGATE_KEY = 'leo_v1_otp_delegate';
    private const V1_DELEGATE_ROLE = 'v1_otp_delegate';

    public function dryRun(string $namespace, ?User $operator, string $scenario = '', ?int $v1UserId = null): array
    {
        $context = $this->context($namespace);
        $v1Delegate = $v1UserId === null ? null : $this->v1DelegateContext($scenario, $v1UserId);
        $authorized = $operator !== null && Gate::forUser($operator)->allows('manageApiConsumerRegistry');

        $scenarioIdentities = $this->isScenario($scenario)
            ? $this->scenarioContext($namespace, $scenario)['user_ids']
            : [];

        $plan = [
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
            'scenario_bindings' => $this->isScenario($scenario)
                ? collect($this->scenarioDefinitions($scenario))->map(fn (array $definition): array => [
                    'alias' => $this->scenarioAlias($namespace, $definition['key']),
                    'profile' => $definition['kind'],
                    'role' => $definition['role'],
                    'capabilities' => $definition['caps'],
                    'laboratory_brands' => $definition['brands'],
                ])->values()->all()
                : [],
            'effects' => [
                'creates' => ['2 consumers', '2 channels', '4 delegations', '2 machine credentials', '4 delegated bindings'],
                'external_calls' => 0,
                'sms' => 0,
                'email' => 0,
                'payments' => 0,
                'scenario_delegations' => $this->isScenario($scenario) ? count($this->scenarioDefinitions($scenario)) : 0,
                'scenario_bindings' => $this->isScenario($scenario) ? count($this->scenarioDefinitions($scenario)) : 0,
                'scenario_tokens' => $this->isScenario($scenario) ? count($this->scenarioDefinitions($scenario)) : 0,
            ],
            'credentials' => 'created only by apply; plaintext never emitted',
            'expires_at' => Carbon::now()->addMinutes($this->ttlMinutes())->toIso8601String(),
        ];
        if ($v1Delegate === null) return $plan;
        // Only present when --delegate-v1-user is given; default output is unchanged.
        $plan['effects']['v1_delegations'] = 1;
        $plan['effects']['v1_tokens'] = 0;
        $plan['v1_delegation'] = [
            'identity' => 'UAT-'.AkubicaUatFixtureContract::NAMESPACE.'-'.$v1Delegate['role'].'-U'.$v1Delegate['user_id'],
            'profile' => 'leo',
            'capabilities' => self::WINDOW2_CAPABILITIES[self::V1_DELEGATE_KEY],
            'user_changes' => 0,
        ];

        return $plan;
    }

    public function apply(string $namespace, User $operator, string $scenario = '', ?int $v1UserId = null): array
    {
        $this->assertNamespace($namespace);
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');
        // Validate the opt-in v1 delegate before any read of state or write.
        $v1Delegate = $v1UserId === null ? null : $this->v1DelegateContext($scenario, $v1UserId);
        $state = $this->readState($namespace);
        $stateFromInventory = false;
        if ($state !== null) {
            $completed = $this->readInventoryRecord($namespace, true);
            if (($completed['status'] ?? null) === 'completed') {
                $completedState = $completed['state'] ?? null;
                if (! is_array($completedState)) throw new \DomainException('UAT_ACCESS_INVENTORY_INVALID');
                $this->assertState($completedState, $namespace, true);
                $this->assertCleanupComplete($completedState);
                Storage::disk('local')->delete($this->statePath($namespace));
                $state = null;
            }
        }
        if ($state === null) {
            $inventory = $this->readInventoryRecord($namespace, true);
            if (($inventory['status'] ?? null) === 'completed') {
                $completedState = $inventory['state'] ?? null;
                if (! is_array($completedState)) throw new \DomainException('UAT_ACCESS_INVENTORY_INVALID');
                $this->assertState($completedState, $namespace, true);
                $this->assertCleanupComplete($completedState);
            } elseif (($inventory['status'] ?? null) === 'active') {
                $state = $inventory['state'] ?? null;
                $stateFromInventory = $state !== null;
            }
        }
        if ($state !== null) {
            $this->assertState($state, $namespace, $stateFromInventory);
            if (($state['scenario'] ?? '') !== $scenario) throw new \DomainException('UAT_ACCESS_SCENARIO_MISMATCH');
            if ((isset($state['v1_delegate_user_id']) ? (int) $state['v1_delegate_user_id'] : null) !== $v1UserId) throw new \DomainException('UAT_ACCESS_V1_DELEGATE_MISMATCH');

            return ['status' => 'idempotent', 'namespace' => $namespace, 'scenario' => $scenario, 'created' => 0, 'active_credentials' => count($state['token_ids'])];
        }

        $context = $this->context($namespace);
        $this->assertNoUnownedAccess($context['user_ids']);
        $expiresAt = Carbon::now()->addMinutes($this->ttlMinutes());
        $inventoryUserIds = $context['user_ids'];
        if ($this->isScenario($scenario)) {
            $inventoryUserIds = array_merge($inventoryUserIds, $this->scenarioContext($namespace, $scenario)['user_ids']);
        }
        if ($v1Delegate !== null) {
            if (in_array($v1Delegate['user_id'], array_map('intval', array_values($inventoryUserIds)), true)) throw new \DomainException('UAT_ACCESS_V1_DELEGATE_COLLISION');
            $this->assertNoUnownedAccess([$v1Delegate['user_id']]);
            $inventoryUserIds[self::V1_DELEGATE_ROLE] = $v1Delegate['user_id'];
        }
        $created = ['consumers' => [], 'channels' => [], 'delegations' => [], 'credentials' => [], 'bindings' => [], 'tokens' => [], 'secrets' => [], 'scenario_delegations' => [], 'scenario_bindings' => [], 'scenario_tokens' => [], 'scenario_secrets' => [], 'v1_delegations' => [], 'v1_delegate_user_id' => $v1Delegate['user_id'] ?? null];
        // Establish a recoverable, secret-free ledger before the first write.
        $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
        $registry = app(ConsumerRegistryWriter::class);
        $issuer = app(ConsumerMachineCredentialIssuer::class);
        $bindingWriter = app(ConsumerTokenBindingWriter::class);
        $lifecycle = app(ConsumerLifecycle::class);

        try {
            foreach (self::CAPABILITIES as $kind => $caps) {
                $consumer = $registry->register($operator, $kind, $caps, $expiresAt);
                $created['consumers'][$kind] = $consumer->id;
                $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
                $consumer->refresh();
                $effectiveExpiry = Carbon::parse($consumer->expires_at);
                $channel = $registry->channel($operator, $consumer, $effectiveExpiry);
                $created['channels'][$kind] = $channel->id;
                $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
                $lifecycle->transition($operator, $consumer, true, self::APPROVAL);
                $lifecycle->transition($operator, $channel, true, self::APPROVAL);
                $issuedMachine = $issuer->issue($operator, $channel, $effectiveExpiry);
                $created['credentials'][$kind] = $issuedMachine['credential']->id;
                $created['tokens']['machine_'.$kind] = $issuedMachine['token']->accessToken->id;
                $created['secrets']['machine_'.$kind] = Crypt::encryptString($issuedMachine['token']->plainTextToken);
                $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');

                foreach ($context['user_ids'] as $role => $userId) {
                    $user = User::query()->findOrFail($userId);
                    $delegation = $registry->delegate($operator, $channel, $user, $caps, $effectiveExpiry);
                    $lifecycle->transition($operator, $delegation, true, self::APPROVAL);
                    $created['delegations'][$kind][$role] = $delegation->id;
                    $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
                    $token = $user->createToken($namespace.'-'.$kind, $caps, $effectiveExpiry);
                    $created['tokens'][$kind.'_'.$role] = $token->accessToken->id;
                    $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
                    $binding = $bindingWriter->bind($operator, $token->accessToken, $delegation, $caps, $effectiveExpiry, ['olab']);
                    $created['bindings'][$kind][$role] = $binding->id;
                    $created['secrets'][$kind.'_'.$role] = Crypt::encryptString($token->plainTextToken);
                    $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
                }
            }

            if ($this->isScenario($scenario)) {
                $scenarioContext = $this->scenarioContext($namespace, $scenario);
                $this->assertNoUnownedAccess(array_values($scenarioContext['user_ids']));
                foreach ($this->scenarioDefinitions($scenario) as $definition) {
                    $kind = $definition['kind'];
                    $role = $definition['role'];
                    $user = User::query()->findOrFail($scenarioContext['user_ids'][$role]);
                    $channel = ApiConsumerChannel::query()->findOrFail($created['channels'][$kind]);
                    $consumer = ApiConsumer::query()->findOrFail($created['consumers'][$kind]);
                    $effectiveScenarioExpiry = Carbon::parse($consumer->expires_at);
                    $delegation = $registry->delegate($operator, $channel, $user, $definition['caps'], $effectiveScenarioExpiry);
                    $lifecycle->transition($operator, $delegation, true, self::APPROVAL);
                    $created['scenario_delegations'][$definition['key']] = $delegation->id;
                    $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
                    $token = $user->createToken($namespace.'-'.$definition['key'], $definition['caps'], $effectiveScenarioExpiry);
                    $created['scenario_tokens'][$definition['key']] = $token->accessToken->id;
                    $created['scenario_secrets'][$definition['key']] = Crypt::encryptString($token->plainTextToken);
                    $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
                    $binding = $bindingWriter->bind($operator, $token->accessToken, $delegation, $definition['caps'], $effectiveScenarioExpiry, $definition['brands']);
                    $created['scenario_bindings'][$definition['key']] = $binding->id;
                    $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
                }
            }

            if ($v1Delegate !== null) {
                // Delegation only: no PAT, no binding, no change to the v1 user,
                // its phone, customer or fixtures. verify-code issues the Bearer.
                $user = User::query()->findOrFail($v1Delegate['user_id']);
                $channel = ApiConsumerChannel::query()->findOrFail($created['channels']['leo']);
                $consumer = ApiConsumer::query()->findOrFail($created['consumers']['leo']);
                $delegation = $registry->delegate($operator, $channel, $user, self::WINDOW2_CAPABILITIES[self::V1_DELEGATE_KEY], Carbon::parse($consumer->expires_at));
                $created['v1_delegations'][self::V1_DELEGATE_KEY] = $delegation->id;
                $this->writeInventorySnapshot($namespace, $scenario, $inventoryUserIds, $created, $expiresAt, 'active');
                $lifecycle->transition($operator, $delegation, true, self::APPROVAL);
            }

            $state = [
                'namespace' => $namespace,
                'user_ids' => $inventoryUserIds,
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
            ] + $this->v1StateKeys($created['v1_delegations'], $created['v1_delegate_user_id']);
            $this->writeState($namespace, $state);
            $this->writeInventory($namespace, $state, 'active');

            return ['status' => 'ok', 'namespace' => $namespace, 'scenario' => $scenario, 'created' => ['consumers' => 2, 'channels' => 2, 'delegations' => 4 + count($created['scenario_delegations']) + count($created['v1_delegations']), 'credentials' => 2, 'bindings' => 4 + count($created['scenario_bindings']), 'tokens' => count($created['tokens']) + count($created['scenario_tokens'])], 'external_calls' => 0];
        } catch (\Throwable $exception) {
            try {
                $this->revokeCreated($operator, $created, $lifecycle);
                $this->purgeCreated($created);
                $partialState = $this->stateFromCreated($namespace, $scenario, $inventoryUserIds, $created, $expiresAt);
                $this->assertCleanupComplete($partialState);
                $this->writeInventory($namespace, $partialState, 'completed');
            } catch (\Throwable $cleanupException) {
                // Keep the active ledger when cleanup is incomplete so reset can recover it.
                throw $cleanupException;
            }
            throw $exception;
        }
    }

    public function reset(string $namespace, User $operator, string $scenario = ''): array
    {
        $this->assertNamespace($namespace);
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');
        $state = $this->readState($namespace) ?? $this->readInventory($namespace);
        if ($state === null) {
            return ['status' => 'empty', 'namespace' => $namespace, 'deleted' => 0];
        }
        $this->assertState($state, $namespace, true);
        if (($state['scenario'] ?? '') !== $scenario) throw new \DomainException('UAT_ACCESS_SCENARIO_MISMATCH');
        $lifecycle = app(ConsumerLifecycle::class);
        $v1DelegationIds = $this->flattenIds($state['v1_delegation_ids'] ?? []);
        if ($v1DelegationIds !== []) {
            // Bindings that verify-code attached to the v1 delegation are revoked
            // (not deleted); the PAT row stays and is unusable once revoked.
            $derived = ApiConsumerTokenBinding::query()->whereIn('delegation_id', $v1DelegationIds)->whereIn('consumer_id', array_map('intval', array_values($state['consumer_ids'])))->whereNull('revoked_at')->pluck('id');
            foreach ($derived as $id) $this->revokeModel($operator, ApiConsumerTokenBinding::class, (int) $id, $lifecycle);
            foreach ($v1DelegationIds as $id) $this->revokeModel($operator, ApiConsumerDelegation::class, $id, $lifecycle);
        }
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
        $this->writeInventory($namespace, $state, 'completed');
        Storage::disk('local')->delete($this->statePath($namespace));

        return ['status' => 'ok', 'namespace' => $namespace, 'scenario' => $scenario, 'deleted' => 2 + 2 + 4 + 2 + 4 + 6 + count($state['scenario_binding_ids'] ?? []) + count($state['scenario_delegation_ids'] ?? []) + count($state['scenario_token_ids'] ?? []) + count($v1DelegationIds)];
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
    private function scenarioContext(string $namespace, string $scenario): array
    {
        $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)->firstOrFail();
        $ids = (array) data_get($manifest->metadata, 'ids.users', []);
        $roles = $scenario === self::WINDOW2_MEMBERSHIP_SCENARIO
            ? ['membership_none', 'membership_regular', 'membership_institutional', 'membership_expired']
            : ['membership_regular', 'membership_none'];
        $userIds = collect($roles)->mapWithKeys(fn (string $role): array => [$role => (int) ($ids[$role] ?? 0)])->all();
        if (in_array(0, $userIds, true)) throw new \DomainException('UAT_ACCESS_SCENARIO_IDENTITIES_INCOMPLETE');
        return ['user_ids' => $userIds];
    }

    /** @return array{user_id:int, role:string} */
    private function v1DelegateContext(string $scenario, int $userId): array
    {
        if ($scenario !== self::WINDOW2_CONTINUITY_SCENARIO) throw new \DomainException('UAT_ACCESS_V1_DELEGATE_SCENARIO_NOT_ALLOWED');
        if ($userId <= 0) throw new \DomainException('UAT_ACCESS_V1_DELEGATE_INVALID');
        $manifest = AkubicaUatFixtureManifest::query()->where('namespace', AkubicaUatFixtureContract::NAMESPACE)->first();
        if (! $manifest || $manifest->status !== AkubicaUatFixtureContract::STATUS_ACTIVE || ! is_array($manifest->metadata)) throw new \DomainException('UAT_ACCESS_V1_FIXTURES_NOT_ACTIVE');
        $roles = array_keys(array_filter((array) data_get($manifest->metadata, 'ids.users', []), fn ($id): bool => (int) $id === $userId));
        if (count($roles) !== 1) throw new \DomainException('UAT_ACCESS_V1_USER_NOT_IN_MANIFEST');
        $user = User::query()->find($userId);
        if (! $user) throw new \DomainException('UAT_ACCESS_V1_USER_NOT_FOUND');
        if ($user->phone_verified_at === null) throw new \DomainException('UAT_ACCESS_V1_USER_NOT_VERIFIED');
        if (\App\Models\Customer::query()->where('user_id', $userId)->limit(2)->count() !== 1) throw new \DomainException('UAT_ACCESS_V1_USER_CUSTOMER_INVALID');

        return ['user_id' => $userId, 'role' => (string) $roles[0]];
    }

    private function isScenario(string $scenario): bool
    {
        return in_array($scenario, [self::WINDOW2_SCENARIO, self::WINDOW2_MEMBERSHIP_SCENARIO, self::WINDOW2_CONTINUITY_SCENARIO], true);
    }

    /** @return list<array{key:string,kind:string,role:string,caps:list<string>,brands:list<string>}> */
    private function scenarioDefinitions(string $scenario): array
    {
        $definitions = [
            ['key' => 'native_membership_regular', 'kind' => 'native', 'role' => 'membership_regular', 'caps' => self::WINDOW2_CAPABILITIES['native_membership_regular'], 'brands' => ['olab']],
            ['key' => 'native_membership_none', 'kind' => 'native', 'role' => 'membership_none', 'caps' => self::WINDOW2_CAPABILITIES['native_membership_none'], 'brands' => ['olab']],
        ];

        if ($scenario === self::WINDOW2_MEMBERSHIP_SCENARIO) {
            return [
                $definitions[1],
                $definitions[0],
                ['key' => 'native_membership_institutional', 'kind' => 'native', 'role' => 'membership_institutional', 'caps' => self::WINDOW2_CAPABILITIES['native_membership_regular'], 'brands' => ['olab']],
                ['key' => 'native_membership_expired', 'kind' => 'native', 'role' => 'membership_expired', 'caps' => self::WINDOW2_CAPABILITIES['native_membership_regular'], 'brands' => ['olab']],
            ];
        }

        if ($scenario === self::WINDOW2_CONTINUITY_SCENARIO) {
            return [
                ['key' => 'leo_membership_none_olab', 'kind' => 'leo', 'role' => 'membership_none', 'caps' => self::WINDOW2_CAPABILITIES['leo_membership_none_olab'], 'brands' => ['olab']],
            ];
        }

        return [
            $definitions[0],
            $definitions[1],
            ['key' => 'leo_membership_none', 'kind' => 'leo', 'role' => 'membership_none', 'caps' => self::WINDOW2_CAPABILITIES['leo_membership_none'], 'brands' => ['swisslab']],
        ];
    }

    private function scenarioAlias(string $namespace, string $key): string
    {
        return $namespace.'-'.str_replace('_', '-', $key);
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
        foreach ($this->flattenIds($created['v1_delegations'] ?? []) as $id) $this->revokeModel($operator, ApiConsumerDelegation::class, $id, $lifecycle);
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
        foreach ($this->flattenIds($created['v1_delegations'] ?? []) as $id) ApiConsumerDelegation::query()->whereKey($id)->delete();
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

    private function assertState(array $state, string $namespace, bool $allowMissing = false): void
    {
        if (($state['namespace'] ?? null) !== $namespace || ! isset($state['consumer_ids'], $state['binding_ids'], $state['token_ids'])) throw new \DomainException('UAT_ACCESS_STATE_INVALID');
        $this->assertInventoryReferences($state, $allowMissing);
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
            ApiConsumerDelegation::class => array_merge($this->flattenIds($state['delegation_ids']), $this->flattenIds($state['scenario_delegation_ids'] ?? []), $this->flattenIds($state['v1_delegation_ids'] ?? [])),
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

    private function readInventory(string $namespace): ?array
    {
        $inventory = $this->readInventoryRecord($namespace, true);
        if ($inventory === [] || ($inventory['status'] ?? null) === 'completed') return null;
        return $inventory['state'] ?? null;
    }

    private function readInventoryRecord(string $namespace, bool $allowCompleted = false): array
    {
        $path = $this->inventoryPath($namespace);
        if (! Storage::disk('local')->exists($path)) return [];
        $inventory = json_decode((string) Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        $allowedStatuses = $allowCompleted ? ['active', 'completed'] : ['active'];
        if (! is_array($inventory) || ($inventory['namespace'] ?? null) !== $namespace || ! $this->isScenario((string) ($inventory['scenario'] ?? '')) || ! in_array(($inventory['status'] ?? null), $allowedStatuses, true)) {
            throw new \DomainException('UAT_ACCESS_INVENTORY_INVALID');
        }

        return $inventory;
    }

    private function writeInventory(string $namespace, array $state, string $status): void
    {
        $inventory = [
            'namespace' => $namespace,
            'scenario' => $state['scenario'] ?? null,
            'status' => $status,
            'recorded_at' => now()->toIso8601String(),
            'state' => [
                'namespace' => $state['namespace'] ?? null,
                'scenario' => $state['scenario'] ?? null,
                'user_ids' => $state['user_ids'] ?? [],
                'consumer_ids' => $state['consumer_ids'] ?? [],
                'channel_ids' => $state['channel_ids'] ?? [],
                'delegation_ids' => $state['delegation_ids'] ?? [],
                'credential_ids' => $state['credential_ids'] ?? [],
                'binding_ids' => $state['binding_ids'] ?? [],
                'token_ids' => $state['token_ids'] ?? [],
                'scenario_delegation_ids' => $state['scenario_delegation_ids'] ?? [],
                'scenario_binding_ids' => $state['scenario_binding_ids'] ?? [],
                'scenario_token_ids' => $state['scenario_token_ids'] ?? [],
                'expires_at' => $state['expires_at'] ?? null,
            ] + $this->v1StateKeys($state['v1_delegation_ids'] ?? [], $state['v1_delegate_user_id'] ?? null),
        ];
        $this->atomicWrite($this->inventoryPath($namespace), json_encode($inventory, JSON_THROW_ON_ERROR));
    }

    private function writeInventorySnapshot(string $namespace, string $scenario, array $userIds, array $created, Carbon $expiresAt, string $status): void
    {
        $this->writeInventory($namespace, $this->stateFromCreated($namespace, $scenario, $userIds, $created, $expiresAt), $status);
    }

    private function stateFromCreated(string $namespace, string $scenario, array $userIds, array $created, Carbon $expiresAt): array
    {
        return [
            'namespace' => $namespace,
            'user_ids' => $userIds,
            'consumer_ids' => $created['consumers'] ?? [],
            'channel_ids' => $created['channels'] ?? [],
            'delegation_ids' => $created['delegations'] ?? [],
            'credential_ids' => $created['credentials'] ?? [],
            'binding_ids' => $created['bindings'] ?? [],
            'token_ids' => $created['tokens'] ?? [],
            'scenario' => $scenario,
            'scenario_delegation_ids' => $created['scenario_delegations'] ?? [],
            'scenario_binding_ids' => $created['scenario_bindings'] ?? [],
            'scenario_token_ids' => $created['scenario_tokens'] ?? [],
            'expires_at' => $expiresAt->toIso8601String(),
        ] + $this->v1StateKeys($created['v1_delegations'] ?? [], $created['v1_delegate_user_id'] ?? null);
    }

    /** INT-028 keys exist only when the opt-in delegate was requested. */
    private function v1StateKeys(array $delegationIds, mixed $userId): array
    {
        if ($userId === null) return [];

        return ['v1_delegate_user_id' => (int) $userId, 'v1_delegation_ids' => $delegationIds];
    }

    private function assertInventoryReferences(array $state, bool $allowMissing): void
    {
        foreach (['consumer_ids', 'channel_ids', 'credential_ids', 'user_ids'] as $key) {
            foreach ((array) ($state[$key] ?? []) as $id) {
                if (! is_int($id) && ! ctype_digit((string) $id)) throw new \DomainException('UAT_ACCESS_INVENTORY_ID_INVALID');
                if ((int) $id <= 0) throw new \DomainException('UAT_ACCESS_INVENTORY_ID_INVALID');
            }
        }
        foreach (['delegation_ids', 'binding_ids', 'scenario_delegation_ids', 'scenario_binding_ids', 'v1_delegation_ids'] as $key) {
            $values = collect($state[$key] ?? [])->flatten()->all();
            foreach ($values as $id) {
                if (! is_int($id) && ! ctype_digit((string) $id)) throw new \DomainException('UAT_ACCESS_INVENTORY_ID_INVALID');
                if ((int) $id <= 0) throw new \DomainException('UAT_ACCESS_INVENTORY_ID_INVALID');
            }
        }
        foreach (['token_ids', 'scenario_token_ids'] as $key) {
            foreach ((array) ($state[$key] ?? []) as $id) {
                if (! is_int($id) && ! ctype_digit((string) $id)) throw new \DomainException('UAT_ACCESS_INVENTORY_ID_INVALID');
                if ((int) $id <= 0) throw new \DomainException('UAT_ACCESS_INVENTORY_ID_INVALID');
            }
        }
        $this->assertGraphOwnership($state, $allowMissing);
    }

    private function assertGraphOwnership(array $state, bool $allowMissing): void
    {
        $consumerIds = array_values(array_map('intval', $state['consumer_ids'] ?? []));
        $channelIds = array_values(array_map('intval', $state['channel_ids'] ?? []));
        $delegationIds = $this->flattenIds(array_merge(array_values($state['delegation_ids'] ?? []), array_values($state['scenario_delegation_ids'] ?? []), array_values($state['v1_delegation_ids'] ?? [])));
        $bindingIds = $this->flattenIds(array_merge(array_values($state['binding_ids'] ?? []), array_values($state['scenario_binding_ids'] ?? [])));
        if (! $allowMissing && $consumerIds !== [] && ApiConsumer::query()->whereIn('id', $consumerIds)->count() !== count($consumerIds)) throw new \DomainException('UAT_ACCESS_INVENTORY_CONSUMER_REFERENCE_INVALID');
        if (! $allowMissing && $channelIds !== [] && ApiConsumerChannel::query()->whereIn('id', $channelIds)->count() !== count($channelIds)) throw new \DomainException('UAT_ACCESS_INVENTORY_CHANNEL_REFERENCE_INVALID');
        $foreignChannel = ApiConsumerChannel::query()->whereIn('id', $channelIds)->whereNotIn('consumer_id', $consumerIds)->exists();
        if ($foreignChannel) throw new \DomainException('UAT_ACCESS_INVENTORY_CHANNEL_SCOPE_INVALID');
        $foreignDelegation = ApiConsumerDelegation::query()->whereIn('id', $delegationIds)->whereNotIn('consumer_id', $consumerIds)->exists();
        if ($foreignDelegation) throw new \DomainException('UAT_ACCESS_INVENTORY_DELEGATION_SCOPE_INVALID');
        $userIds = array_values(array_map('intval', $state['user_ids'] ?? []));
        if (ApiConsumerDelegation::query()->whereIn('id', $delegationIds)->whereNotIn('user_id', $userIds)->exists()) throw new \DomainException('UAT_ACCESS_INVENTORY_DELEGATION_OWNER_INVALID');
        $foreignBinding = ApiConsumerTokenBinding::query()->whereIn('id', $bindingIds)->whereNotIn('consumer_id', $consumerIds)->exists();
        if ($foreignBinding) throw new \DomainException('UAT_ACCESS_INVENTORY_BINDING_SCOPE_INVALID');
        if (ApiConsumerTokenBinding::query()->whereIn('id', $bindingIds)->whereNotIn('channel_id', $channelIds)->exists()) throw new \DomainException('UAT_ACCESS_INVENTORY_BINDING_CHANNEL_INVALID');
        if (ApiConsumerTokenBinding::query()->whereIn('id', $bindingIds)->whereNotIn('delegation_id', $delegationIds)->exists()) throw new \DomainException('UAT_ACCESS_INVENTORY_BINDING_DELEGATION_INVALID');
        $credentialIds = array_values(array_map('intval', $state['credential_ids'] ?? []));
        if (ApiConsumerCredential::query()->whereIn('id', $credentialIds)->where(function ($query) use ($consumerIds, $channelIds): void {
            $query->whereNotIn('consumer_id', $consumerIds)->orWhereNotIn('channel_id', $channelIds);
        })->exists()) throw new \DomainException('UAT_ACCESS_INVENTORY_CREDENTIAL_SCOPE_INVALID');
        $machineTokenIds = [];
        foreach ((array) ($state['token_ids'] ?? []) as $key => $value) {
            if (str_starts_with((string) $key, 'machine_')) $machineTokenIds[] = (int) $value;
        }
        foreach (array_merge(array_values($state['token_ids'] ?? []), array_values($state['scenario_token_ids'] ?? [])) as $id) {
            $token = PersonalAccessToken::query()->find((int) $id);
            if (! $token) continue;
            $isMachine = in_array((int) $id, $machineTokenIds, true);
            $expectedType = $isMachine ? ApiConsumer::class : User::class;
            $expectedIds = $isMachine ? $consumerIds : $userIds;
            if ($token->tokenable_type !== $expectedType || ! in_array((int) $token->tokenable_id, $expectedIds, true)) throw new \DomainException('UAT_ACCESS_INVENTORY_TOKEN_SCOPE_INVALID');
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $disk = Storage::disk('local');
        $absolute = $disk->path($path);
        $directory = dirname($absolute);
        if (! is_dir($directory)) mkdir($directory, 0770, true);
        $temporary = $absolute.'.tmp.'.bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            @unlink($temporary);
            throw new \RuntimeException('UAT_ACCESS_INVENTORY_WRITE_FAILED');
        }
        if (@rename($temporary, $absolute)) return;
        // PHP on Windows cannot replace an existing file with rename(). Keep
        // the exclusive lock for that local-only fallback; Forge runs the
        // atomic rename path on Linux.
        $lock = fopen($absolute.'.lock', 'c');
        $replaced = false;
        if (is_resource($lock) && flock($lock, LOCK_EX)) {
            $replaced = file_put_contents($absolute, $contents, LOCK_EX) !== false;
            flock($lock, LOCK_UN);
        }
        if (is_resource($lock)) fclose($lock);
        @unlink($temporary);
        @unlink($absolute.'.lock');
        if (! $replaced) throw new \RuntimeException('UAT_ACCESS_INVENTORY_WRITE_FAILED');
    }

    private function writeState(string $namespace, array $state): void
    {
        Storage::disk('local')->put($this->statePath($namespace), json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function statePath(string $namespace): string { return AkubicaUatFixtureContract::storagePath('access-state.json', $namespace); }
    private function inventoryPath(string $namespace): string { return AkubicaUatFixtureContract::storagePath('access-inventory.json', $namespace); }
    private function ttlMinutes(): int { return max(1, min(45, (int) config('akubica_uat.access_ttl_minutes', 30))); }
    private function assertNamespace(string $namespace): void { if ($namespace !== AkubicaUatFixtureContract::DEDICATED_NAMESPACE) throw new \DomainException('UAT_ACCESS_NAMESPACE_NOT_ALLOWED'); }
}
