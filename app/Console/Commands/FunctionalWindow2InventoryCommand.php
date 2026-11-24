<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\LaboratoryCartItem;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * Read-only inventory bridge for functional-window-2 cleanup.
 *
 * It runs beside the Laravel release, reads the active manifest and MySQL,
 * and obtains the deployed SHA from release metadata/current. It never uses
 * the runner checkout and never mutates application data.
 */
final class FunctionalWindow2InventoryCommand extends Command
{
    protected $signature = 'functional-window2:inventory';
    protected $description = 'Verify dedicated functional-window-2 resource ownership (read-only)';

    private const NAMESPACE = AkubicaUatFixtureContract::DEDICATED_NAMESPACE;
    private const PURPOSES = [
        'B06b' => 'B06b',
        'B20' => 'B20',
        'B21' => 'B21',
        'cart-item-created-by-B06b' => 'B06b',
        'cart-item-created-by-B20' => 'B20',
        'cart-item-created-by-B21' => 'B21',
    ];
    private const OWNERS = ['customer-dedicated', 'customer:primary'];

    public function handle(): int
    {
        if ($this->isTty(STDIN)) {
            return $this->failSafe('INVENTORY_INPUT_REQUIRES_PIPE');
        }

        try {
            $input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($input) || ! in_array($input['action'] ?? null, ['identity', 'verify'], true)) {
                return $this->failSafe('REQUEST_INVALID');
            }
            if (($input['action'] ?? null) === 'identity') {
                $release = $this->releaseIdentity();

                return $this->json([
                    'status' => 'verified',
                    'release' => $release['release'],
                    'active_sha' => $release['sha'],
                ]);
            }
            if (($input['namespace'] ?? null) !== self::NAMESPACE) {
                return $this->failSafe('NAMESPACE_NOT_ALLOWED');
            }
            $resourceId = filter_var($input['resource_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($resourceId === false) {
                return $this->failSafe('RESOURCE_ID_INVALID');
            }
            $purpose = $input['purpose'] ?? null;
            $owner = $input['owner'] ?? null;
            $expectedSha = $input['expected_sha'] ?? null;
            if (! is_string($purpose) || ! is_string($owner) || ! is_string($expectedSha)) {
                return $this->failSafe('PROVENANCE_REQUIRED');
            }
            if (! in_array($owner, self::OWNERS, true)) {
                return $this->failSafe('OWNER_NOT_ALLOWED');
            }

            $release = $this->releaseIdentity();
            if (! hash_equals(strtolower($release['sha']), strtolower($expectedSha))) {
                return $this->failSafe('RELEASE_SHA_MISMATCH');
            }
            $this->verifyResource((int) $resourceId, $purpose, $owner);

            return $this->json([
                'status' => 'verified',
                'namespace' => self::NAMESPACE,
                'resource_id' => (int) $resourceId,
                'purpose' => $purpose,
                'owner' => $owner,
            ]);
        } catch (\DomainException $exception) {
            return $this->failSafe($exception->getMessage());
        } catch (\Throwable) {
            return $this->failSafe('INVENTORY_VERIFICATION_FAILED');
        }
    }

    private function verifyResource(int $resourceId, string $purpose, string $owner): void
    {
        $scenario = $this->scenarioFromPurpose($purpose);
        $manifest = AkubicaUatFixtureManifest::query()
            ->where('namespace', self::NAMESPACE)
            ->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)
            ->first();
        if ($manifest === null) {
            throw new \DomainException('MANIFEST_NOT_ACTIVE');
        }
        if ($manifest->expires_at !== null && $manifest->expires_at->isPast()) {
            throw new \DomainException('MANIFEST_EXPIRED');
        }

        $ids = (array) Arr::get((array) $manifest->metadata, 'ids', []);
        $primaryCustomerId = (int) Arr::get($ids, 'customers.primary', 0);
        $testIds = array_map('intval', array_values((array) Arr::get($ids, 'tests', [])));
        if ($primaryCustomerId < 1 || $testIds === []) {
            throw new \DomainException('MANIFEST_INVENTORY_INCOMPLETE');
        }

        $item = LaboratoryCartItem::query()->with('laboratoryTest')->find($resourceId);
        if ($item === null) {
            throw new \DomainException('RESOURCE_NOT_FOUND');
        }
        if ((int) $item->customer_id !== $primaryCustomerId) {
            throw new \DomainException('RESOURCE_OWNER_MISMATCH');
        }
        if (! in_array((int) $item->laboratory_test_id, $testIds, true)) {
            throw new \DomainException('RESOURCE_NAMESPACE_MISMATCH');
        }
        $brand = $item->laboratoryTest?->brand;
        $brandValue = $brand instanceof \BackedEnum ? $brand->value : $brand;
        if ($item->laboratoryTest === null || $brandValue !== 'olab') {
            throw new \DomainException('RESOURCE_TYPE_MISMATCH');
        }
        if ($scenario === null) {
            throw new \DomainException('RESOURCE_PURPOSE_MISMATCH');
        }
    }

    private function scenarioFromPurpose(string $purpose): ?string
    {
        return self::PURPOSES[$purpose] ?? null;
    }

    /** @return array{sha:string,release:string} */
    private function releaseIdentity(): array
    {
        $metadataPath = config('akubica_uat.release_metadata_path') ?: base_path('REVISION');
        $candidates = [$metadataPath, base_path('release.json'), base_path('REVISION')];
        foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
            if (! File::exists($candidate)) {
                continue;
            }
            $raw = trim((string) File::get($candidate));
            $decoded = json_decode($raw, true);
            $sha = is_array($decoded)
                ? ($decoded['active_sha'] ?? $decoded['sha'] ?? $decoded['commit'] ?? null)
                : $raw;
            if (is_string($sha) && preg_match('/^[0-9a-f]{40}$/i', $sha)) {
                $release = is_array($decoded) && is_string($decoded['release'] ?? null)
                    ? $decoded['release']
                    : basename(dirname($candidate));

                return ['sha' => $sha, 'release' => $release];
            }
        }

        throw new \DomainException('RELEASE_METADATA_UNAVAILABLE');
    }

    private function isTty($stream): bool
    {
        return function_exists('posix_isatty') && @posix_isatty($stream);
    }

    private function json(array $payload): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function failSafe(string $code): int
    {
        $this->line((string) json_encode(['error' => $code], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
