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

    /** @var array<string, string> */
    private const SECRET_KEYS = [
        'machine_native' => 'machine_native',
        'machine_leo' => 'machine_leo',
        'native_membership_regular' => 'scenario:native_membership_regular',
        'native_membership_none' => 'scenario:native_membership_none',
        'leo_membership_none' => 'scenario:leo_membership_none',
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
            $state = $this->validatedState($action === 'revoke');
            $aliases = $input['aliases'] ?? null;
            if (! is_array($aliases) || $aliases === [] || count($aliases) !== count(array_unique($aliases))) {
                return $this->failSafe('ALIASES_INVALID');
            }
            foreach ($aliases as $alias) {
                if (! is_string($alias) || ! array_key_exists($alias, self::SECRET_KEYS)) return $this->failSafe('ALIAS_NOT_ALLOWED');
            }

            return match ($input['action'] ?? null) {
                'read' => $this->readSecrets($state, $aliases),
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
    private function validatedState(bool $allowAlreadyRevokedTokens = false): array
    {
        $path = AkubicaUatFixtureContract::storagePath('access-state.json', self::NAMESPACE);
        $state = Storage::disk('local')->exists($path)
            ? json_decode((string) Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR)
            : ($allowAlreadyRevokedTokens ? $this->readInventory() : null);
        if (! is_array($state)) throw new \DomainException('STATE_MISSING');
        if (($state['namespace'] ?? null) !== self::NAMESPACE || ($state['scenario'] ?? null) !== self::SCENARIO) throw new \DomainException('STATE_SCOPE_INVALID');
        if ((! $allowAlreadyRevokedTokens && empty($state['scenario_encrypted_secrets'])) || empty($state['scenario_token_ids'])) throw new \DomainException('STATE_SCENARIO_MATERIAL_MISSING');
        if (isset($state['expires_at']) && now()->isAfter($state['expires_at'])) throw new \DomainException('STATE_EXPIRED');
        foreach (array_merge(array_values($state['token_ids'] ?? []), array_values($state['scenario_token_ids'])) as $id) {
            if (! $allowAlreadyRevokedTokens && ! PersonalAccessToken::query()->whereKey((int) $id)->exists()) throw new \DomainException('STATE_TOKEN_REFERENCE_INVALID');
        }
        return $state;
    }

    private function readInventory(): ?array
    {
        $path = AkubicaUatFixtureContract::storagePath('access-inventory.json', self::NAMESPACE);
        if (! Storage::disk('local')->exists($path)) return null;
        $inventory = json_decode((string) Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($inventory) || ($inventory['namespace'] ?? null) !== self::NAMESPACE || ($inventory['scenario'] ?? null) !== self::SCENARIO || ($inventory['status'] ?? null) !== 'active') return null;

        return $inventory['state'] ?? null;
    }

    /** @param list<string> $aliases */
    private function readSecrets(array $state, array $aliases): int
    {
        if (! $this->option('private')) return $this->failSafe('CREDENTIAL_READ_REQUIRES_PRIVATE_CHANNEL');

        $result = [];
        foreach ($aliases as $alias) {
            $encrypted = $this->encryptedSecret($state, $alias);
            $plain = Crypt::decryptString($encrypted);
            $hash = hash('sha256', $plain);
            $hashKey = str_starts_with(self::SECRET_KEYS[$alias], 'scenario:') ? null : self::SECRET_KEYS[$alias];
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

    private function encryptedSecret(array $state, string $alias): string
    {
        $key = self::SECRET_KEYS[$alias];
        if (str_starts_with($key, 'scenario:')) {
            $key = substr($key, 9);
            $value = $state['scenario_encrypted_secrets'][$key] ?? null;
        } else {
            $value = $state['encrypted_secrets'][$key] ?? null;
        }
        if (! is_string($value) || $value === '') throw new \DomainException('SECRET_REFERENCE_INVALID');
        return $value;
    }

    private function tokenId(array $state, string $alias): int
    {
        $key = self::SECRET_KEYS[$alias];
        if (str_starts_with($key, 'scenario:')) return (int) ($state['scenario_token_ids'][substr($key, 9)] ?? 0);
        return (int) ($state['token_ids'][$key] ?? 0);
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
