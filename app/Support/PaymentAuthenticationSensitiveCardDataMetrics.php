<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class PaymentAuthenticationSensitiveCardDataMetrics
{
    private const PREFIX = 'efevoopay:sensitive_card_data_metrics:';

    public function recordStored(): void
    {
        $this->increment('stored');
    }

    public function recordPurged(string $reason): void
    {
        $this->increment('purged');
        $this->increment('purged_reason:'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($reason)));
    }

    public function recordExpired(): void
    {
        $this->increment('expired');
    }

    public function recordMissing(): void
    {
        $this->increment('missing');
    }

    public function recordAbandonedCandidate(): void
    {
        $this->increment('abandoned_candidate');
    }

    /**
     * @return array<string, int>
     */
    public function snapshot(): array
    {
        $keys = [
            'stored',
            'purged',
            'expired',
            'missing',
            'abandoned_candidate',
        ];

        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = (int) Cache::get(self::PREFIX.$key, 0);
        }

        return $snapshot;
    }

    private function increment(string $suffix): void
    {
        $key = self::PREFIX.$suffix;
        $value = (int) Cache::get($key, 0);
        Cache::forever($key, $value + 1);
    }
}
