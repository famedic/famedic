<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DatabaseSessionSensitiveCardDataPurger
{
    public const SUPPORTED_DRIVER = 'database';

    public function __construct(
        private LaravelDatabaseSessionPayloadCodec $codec,
        private PaymentAuthenticationSensitiveCardDataStore $cardDataStore,
        private PaymentAuthenticationSensitiveCardDataMetrics $metrics
    ) {}

    public function sessionDriver(): string
    {
        return (string) config('session.driver', 'file');
    }

    public function supportsCurrentDriver(): bool
    {
        return $this->sessionDriver() === self::SUPPORTED_DRIVER;
    }

    /**
     * @return array{
     *     candidates: int,
     *     active_skipped: int,
     *     expired_found: int,
     *     purged: int,
     *     errors: int,
     *     stale_skipped: int,
     *     abandoned_candidates: int
     * }
     */
    public function run(bool $apply, ?string $sessionId = null, ?int $batchSize = null): array
    {
        $batchSize = max(1, (int) ($batchSize ?: config('efevoopay.sensitive_card_data.purge_command.batch_size', 100)));
        $ttlSeconds = $this->cardDataStore->ttlMinutes() * 60;
        $now = now()->timestamp;
        $table = (string) config('session.table', 'sessions');
        $connection = config('session.connection');

        $stats = [
            'candidates' => 0,
            'active_skipped' => 0,
            'expired_found' => 0,
            'purged' => 0,
            'errors' => 0,
            'stale_skipped' => 0,
            'abandoned_candidates' => 0,
        ];

        $query = DB::connection($connection)->table($table)->orderBy('last_activity');

        if ($sessionId) {
            $query->where('id', $sessionId);
        } else {
            $query->where('last_activity', '<=', $now - $ttlSeconds);
        }

        $query->chunkById($batchSize, function ($rows) use ($apply, $ttlSeconds, $now, $table, $connection, &$stats) {
            foreach ($rows as $row) {
                $stats['candidates']++;

                try {
                    $result = $this->processRow($apply, $row, $ttlSeconds, $now, $table, $connection);
                    $stats['active_skipped'] += $result['active_skipped'];
                    $stats['expired_found'] += $result['expired_found'];
                    $stats['purged'] += $result['purged'];
                    $stats['stale_skipped'] += $result['stale_skipped'];
                    $stats['abandoned_candidates'] += $result['abandoned_candidates'];
                } catch (\Throwable) {
                    $stats['errors']++;
                }
            }
        }, 'id');

        return $stats;
    }

    /**
     * @return array{active_skipped: int, expired_found: int, purged: int, stale_skipped: int, abandoned_candidates: int}
     */
    private function processRow(
        bool $apply,
        object $row,
        int $ttlSeconds,
        int $now,
        string $table,
        ?string $connection
    ): array {
        $result = [
            'active_skipped' => 0,
            'expired_found' => 0,
            'purged' => 0,
            'stale_skipped' => 0,
            'abandoned_candidates' => 0,
        ];

        $payload = $this->codec->decode((string) $row->payload);

        if ($payload === null) {
            throw new \RuntimeException('Invalid session payload');
        }

        if (! $this->codec->hasSensitiveKeys($payload)) {
            return $result;
        }

        $keysToRemove = [];
        $sessionExpiredByActivity = ((int) $row->last_activity + $ttlSeconds) <= $now;

        foreach ($this->codec->sensitiveKeys($payload) as $key) {
            $entry = is_array($payload[$key] ?? null) ? $payload[$key] : null;
            $expiresAt = is_array($entry) ? (int) ($entry['expires_at'] ?? 0) : 0;

            $isExpired = ($expiresAt > 0 && $expiresAt <= $now)
                || ($expiresAt === 0 && $sessionExpiredByActivity);

            $isActiveWithinTtl = $expiresAt > $now
                || (! $sessionExpiredByActivity && $expiresAt === 0);

            if ($isActiveWithinTtl && ! $sessionExpiredByActivity) {
                $result['active_skipped']++;

                continue;
            }

            if (! $isExpired && ! $sessionExpiredByActivity) {
                $result['active_skipped']++;

                continue;
            }

            $result['expired_found']++;
            $result['abandoned_candidates']++;
            $keysToRemove[] = $key;
        }

        if ($keysToRemove === []) {
            return $result;
        }

        if (! $apply) {
            return $result;
        }

        $purged = DB::connection($connection)->transaction(function () use ($row, $keysToRemove, $table, $connection, $ttlSeconds, $now) {
            $locked = DB::connection($connection)->table($table)
                ->where('id', $row->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $currentPayload = $this->codec->decode((string) $locked->payload);

            if ($currentPayload === null) {
                return false;
            }

            $removable = [];

            foreach ($keysToRemove as $key) {
                if (! array_key_exists($key, $currentPayload)) {
                    continue;
                }

                $entry = is_array($currentPayload[$key] ?? null) ? $currentPayload[$key] : null;
                $expiresAt = is_array($entry) ? (int) ($entry['expires_at'] ?? 0) : 0;
                $sessionExpiredByActivity = ((int) $locked->last_activity + $ttlSeconds) <= $now;
                $isExpired = ($expiresAt > 0 && $expiresAt <= $now)
                    || ($expiresAt === 0 && $sessionExpiredByActivity);
                $isActiveWithinTtl = $expiresAt > $now
                    || (! $sessionExpiredByActivity && $expiresAt === 0);

                if ($isExpired || $sessionExpiredByActivity) {
                    if (! ($isActiveWithinTtl && ! $sessionExpiredByActivity)) {
                        $removable[] = $key;
                    }
                }
            }

            if ($removable === []) {
                return false;
            }

            foreach ($removable as $key) {
                unset($currentPayload[$key]);
            }

            $updated = DB::connection($connection)->table($table)
                ->where('id', $row->id)
                ->where('payload', $locked->payload)
                ->where('last_activity', $locked->last_activity)
                ->update([
                    'payload' => $this->codec->encode($currentPayload),
                ]);

            return $updated === 1;
        });

        if ($purged === false) {
            $result['stale_skipped']++;

            return $result;
        }

        $result['purged'] = 1;
        $this->metrics->recordPurged('abandoned_session_gc');
        $this->metrics->recordAbandonedCandidate();

        return $result;
    }
}
