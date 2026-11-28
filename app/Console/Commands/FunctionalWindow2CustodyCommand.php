<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Private stdin/stdout bridge for the functional-window-2 custody provider.
 * It is intentionally a CLI command; it is not registered as an HTTP route.
 */
final class FunctionalWindow2CustodyCommand extends Command
{
    protected $signature = 'functional-window2:custody {--private : Permit the caller to receive bearer material over the captured pipe}';
    protected $description = 'Read or revoke dedicated functional-window-2 bearer material';

    private const NAMESPACE = AkubicaUatFixtureContract::DEDICATED_NAMESPACE;
    private const SCENARIO = 'functional-window-2';
    private const MEMBERSHIP_SCENARIO = 'functional-window-2-memberships';
    private const CONTINUITY_SCENARIO = 'functional-window-2-continuity';

    /** @var array<string, array<string, string>> */
    private const SECRET_KEYS_BY_SCENARIO = [
        self::SCENARIO => [
            'machine_native' => 'machine_native',
            'machine_leo' => 'machine_leo',
            'native_membership_regular' => 'scenario:native_membership_regular',
            'native_membership_none' => 'scenario:native_membership_none',
            'leo_membership_none' => 'scenario:leo_membership_none',
            // INT-005 fixture owners (UAT primary/foreign). Already stored in
            // encrypted_secrets[leo_primary|leo_foreign] by apply; not scenario material.
            'leo_primary' => 'leo_primary',
            'leo_foreign' => 'leo_foreign',
        ],
        self::MEMBERSHIP_SCENARIO => [
            'machine_native' => 'machine_native',
            'native_membership_none' => 'scenario:native_membership_none',
            'native_membership_regular' => 'scenario:native_membership_regular',
            'native_membership_institutional' => 'scenario:native_membership_institutional',
            'native_membership_expired' => 'scenario:native_membership_expired',
        ],
        self::CONTINUITY_SCENARIO => [
            'machine_leo' => 'machine_leo',
            'leo_membership_none_olab' => 'scenario:leo_membership_none_olab',
        ],
    ];

    public function handle(): int
    {
        if ($this->isTty(STDOUT)) {
            return $this->failSafe('CREDENTIAL_OUTPUT_REQUIRES_PIPE');
        }

        try {
            $input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($input)) return $this->failSafe('REQUEST_INVALID');

            $action = $input['action'] ?? null;
            $scenario = $input['scenario'] ?? self::SCENARIO;
            if (! is_string($scenario) || ! array_key_exists($scenario, self::SECRET_KEYS_BY_SCENARIO)) return $this->failSafe('SCENARIO_NOT_ALLOWED');
            $secretKeys = self::SECRET_KEYS_BY_SCENARIO[$scenario];
            $state = $this->validatedState($scenario, $action === 'revoke');
            $aliases = $input['aliases'] ?? null;
            if (! is_array($aliases) || $aliases === [] || count($aliases) !== count(array_unique($aliases))) {
                return $this->failSafe('ALIASES_INVALID');
            }
            foreach ($aliases as $alias) {
                if (! is_string($alias) || ! array_key_exists($alias, $secretKeys)) return $this->failSafe('ALIAS_NOT_ALLOWED');
            }

            return match ($input['action'] ?? null) {
                'read' => $this->readSecrets($state, $aliases, $secretKeys),
                'revoke' => $this->revokeSecrets($state, $aliases),
                default => $this->failSafe('ACTION_INVALID'),
            };
        } catch (\DomainException $exception) {
            return $this->failSafe($exception->getMessage());
        } catch (\Throwable) {
            // Never expose framework, SQL, ciphertext or secret-bearing errors.
            return $this->failSafe('CUSTODY_OPERATION_FAILED');
        }
    }

    /** @return array<string, mixed> */
    private function validatedState(string $scenario, bool $allowAlreadyRevokedTokens = false): array
    {
        $path = AkubicaUatFixtureContract::storagePath('access-state.json', self::NAMESPACE);
        $state = Storage::disk('local')->exists($path)
            ? json_decode((string) Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR)
            : ($allowAlreadyRevokedTokens ? $this->readInventory($scenario) : null);
        if (! is_array($state)) throw new \DomainException('STATE_MISSING');
        if (($state['namespace'] ?? null) !== self::NAMESPACE || ($state['scenario'] ?? null) !== $scenario) throw new \DomainException('STATE_SCOPE_INVALID');
        if ((! $allowAlreadyRevokedTokens && empty($state['scenario_encrypted_secrets'])) || empty($state['scenario_token_ids'])) throw new \DomainException('STATE_SCENARIO_MATERIAL_MISSING');
        if (isset($state['expires_at']) && now()->isAfter($state['expires_at'])) throw new \DomainException('STATE_EXPIRED');
        foreach (array_merge(array_values($state['token_ids'] ?? []), array_values($state['scenario_token_ids'])) as $id) {
            if (! $allowAlreadyRevokedTokens && ! PersonalAccessToken::query()->whereKey((int) $id)->exists()) throw new \DomainException('STATE_TOKEN_REFERENCE_INVALID');
        }
        return $state;
    }

    private function readInventory(string $scenario): ?array
    {
        $path = AkubicaUatFixtureContract::storagePath('access-inventory.json', self::NAMESPACE);
        if (! Storage::disk('local')->exists($path)) return null;
        $inventory = json_decode((string) Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($inventory) || ($inventory['namespace'] ?? null) !== self::NAMESPACE || ($inventory['scenario'] ?? null) !== $scenario || ($inventory['status'] ?? null) !== 'active') return null;

        return $inventory['state'] ?? null;
    }

    /** @param list<string> $aliases */
    private function readSecrets(array $state, array $aliases, array $secretKeys): int
    {
        if (! $this->option('private')) return $this->failSafe('CREDENTIAL_READ_REQUIRES_PRIVATE_CHANNEL');

        $result = [];
        foreach ($aliases as $alias) {
            $encrypted = $this->encryptedSecret($state, $alias, $secretKeys);
            $plain = Crypt::decryptString($encrypted);
            $hash = hash('sha256', $plain);
            $hashKey = str_starts_with($secretKeys[$alias], 'scenario:') ? null : $secretKeys[$alias];
            if ($hashKey !== null && ($state['secret_hashes'][$hashKey] ?? null) !== $hash) throw new \DomainException('SECRET_INTEGRITY_FAILED');
            $result[$alias] = $plain;
        }
        $this->line((string) json_encode($result, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }

    /** @param list<string> $aliases */
    private function revokeSecrets(array $state, array $aliases): int
    {
        // Cleanup is scoped to every PAT recorded by this dedicated state,
        // not only the four bearer aliases needed by the HTTP plan.
        $ids = array_values(array_unique(array_merge(
            array_map('intval', array_values($state['token_ids'] ?? [])),
            array_map('intval', array_values($state['scenario_token_ids'] ?? [])),
        )));
        foreach ($ids as $id) PersonalAccessToken::query()->whereKey($id)->delete();
        foreach ($ids as $id) {
            if (PersonalAccessToken::query()->whereKey($id)->exists()) return $this->failSafe('CREDENTIAL_REVOCATION_UNCONFIRMED');
        }
        $this->line((string) json_encode(['revoked' => array_values($aliases)], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }

    private function encryptedSecret(array $state, string $alias, array $secretKeys): string
    {
        $key = $secretKeys[$alias];
        if (str_starts_with($key, 'scenario:')) {
            $key = substr($key, 9);
            $value = $state['scenario_encrypted_secrets'][$key] ?? null;
        } else {
            $value = $state['encrypted_secrets'][$key] ?? null;
        }
        if (! is_string($value) || $value === '') throw new \DomainException('SECRET_REFERENCE_INVALID');
        return $value;
    }

    private function isTty($stream): bool
    {
        return function_exists('posix_isatty') && @posix_isatty($stream);
    }

    private function failSafe(string $code): int
    {
        $this->line((string) json_encode(['error' => $code], JSON_THROW_ON_ERROR));
        return self::FAILURE;
    }
}
