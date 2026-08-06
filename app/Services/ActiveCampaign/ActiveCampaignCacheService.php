<?php

namespace App\Services\ActiveCampaign;

use App\DataTransferObjects\ActiveCampaign\ActiveCampaignContactSnapshot;
use Illuminate\Support\Facades\Cache;

/**
 * Cache de lectura ActiveCampaign (TTL 5 minutos).
 *
 * Separa el TTL de catálogos/snapshots del cache de escritura (p.ej. tag ids a 6h).
 */
class ActiveCampaignCacheService
{
    public const TTL_SECONDS = 300;

    public const PREFIX = 'ac:read:';

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function remember(string $key, callable $callback): mixed
    {
        return Cache::remember($this->fullKey($key), self::TTL_SECONDS, $callback);
    }

    public function get(string $key): mixed
    {
        return Cache::get($this->fullKey($key));
    }

    public function put(string $key, mixed $value): void
    {
        Cache::put($this->fullKey($key), $value, self::TTL_SECONDS);
    }

    public function forget(string $key): void
    {
        Cache::forget($this->fullKey($key));
    }

    public function snapshotKey(int $customerId): string
    {
        return "snapshot:customer:{$customerId}";
    }

    public function contactSegmentKey(int $acContactId, string $segment): string
    {
        return "contact:{$acContactId}:{$segment}";
    }

    public function catalogKey(string $catalog): string
    {
        return "catalog:{$catalog}";
    }

    public function rememberSnapshot(int $customerId, callable $callback): ActiveCampaignContactSnapshot
    {
        /** @var ActiveCampaignContactSnapshot $snapshot */
        $snapshot = $this->remember($this->snapshotKey($customerId), $callback);

        return $snapshot;
    }

    public function getSnapshot(int $customerId): ?ActiveCampaignContactSnapshot
    {
        $value = $this->get($this->snapshotKey($customerId));

        return $value instanceof ActiveCampaignContactSnapshot ? $value : null;
    }

    public function putSnapshot(int $customerId, ActiveCampaignContactSnapshot $snapshot): void
    {
        $this->put($this->snapshotKey($customerId), $snapshot);
    }

    public function forgetSnapshot(int $customerId): void
    {
        $this->forget($this->snapshotKey($customerId));
    }

    public function forgetContact(int $acContactId): void
    {
        foreach (['tags', 'lists', 'fields', 'automations', 'activities', 'scores', 'contact', 'contactData'] as $segment) {
            $this->forget($this->contactSegmentKey($acContactId, $segment));
        }
    }

    protected function fullKey(string $key): string
    {
        return self::PREFIX.ltrim($key, ':');
    }
}
