<?php

namespace App\Services\Otp;

use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpRateLimit;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Safe, batched pruning of terminal OTP rows past configurable retention.
 * Does not touch personal_access_tokens (use sanctum:prune-expired).
 * Does not hard-delete akubica_registration_intents; related challenges are skipped.
 */
final class AkubicaOtpPruningService
{
    public const TYPE_ALL = 'all';

    public const TYPE_CHALLENGES = 'challenges';

    public const TYPE_DELIVERIES = 'deliveries';

    public const TYPE_RATE_LIMITS = 'rate-limits';

    public const TYPE_GRANTS = 'grants';

    public const TYPE_SECURE_LINKS = 'secure-links';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_ALL,
        self::TYPE_CHALLENGES,
        self::TYPE_DELIVERIES,
        self::TYPE_RATE_LIMITS,
        self::TYPE_GRANTS,
        self::TYPE_SECURE_LINKS,
    ];

    /** Terminal delivery statuses (never delete pending). */
    public const TERMINAL_DELIVERY_STATUSES = [
        'suppressed',
        'sms_accepted',
        'sms_temporary_failed',
        'sms_permanent_failed',
        'email_accepted',
        'email_failed',
    ];

    /**
     * Ordered prune types for TYPE_ALL (respects FK / cascade safety).
     *
     * @var list<string>
     */
    private const ORDER = [
        self::TYPE_SECURE_LINKS,
        self::TYPE_GRANTS,
        self::TYPE_DELIVERIES,
        self::TYPE_CHALLENGES,
        self::TYPE_RATE_LIMITS,
    ];

    public function prune(bool $dryRun = true, int $batch = 1000, string $type = self::TYPE_ALL): AkubicaOtpPruningResult
    {
        $type = strtolower(trim($type));
        $batch = max(1, min(10000, $batch));
        $started = hrtime(true);

        $counts = [
            self::TYPE_CHALLENGES => 0,
            self::TYPE_DELIVERIES => 0,
            self::TYPE_RATE_LIMITS => 0,
            self::TYPE_GRANTS => 0,
            self::TYPE_SECURE_LINKS => 0,
        ];
        $skipped = [
            'challenges_registration_intent' => 0,
            'challenges_remaining_grants' => 0,
            'grants_active_secure_links' => 0,
        ];
        $errors = [];

        $types = $type === self::TYPE_ALL ? self::ORDER : [$type];

        // Dry-run type=all simulates deletion order so challenge counts match force
        // (grants that would be removed no longer block challenges).
        $dryRunGrantIdsToIgnore = [];

        foreach ($types as $entity) {
            try {
                if ($entity === self::TYPE_CHALLENGES) {
                    $skipped['challenges_registration_intent'] += $this->countChallengesSkippedForRegistrationIntent();
                    $skipped['challenges_remaining_grants'] += $this->countChallengesSkippedForRemainingGrants(
                        ignoreGrantIds: $dryRunGrantIdsToIgnore
                    );
                }
                if ($entity === self::TYPE_GRANTS) {
                    $skipped['grants_active_secure_links'] += $this->countGrantsSkippedForActiveLinks();
                }

                if ($dryRun) {
                    if ($entity === self::TYPE_GRANTS && $type === self::TYPE_ALL) {
                        $dryRunGrantIdsToIgnore = $this->candidateIds(self::TYPE_GRANTS);
                        $counts[$entity] = count($dryRunGrantIdsToIgnore);
                    } elseif ($entity === self::TYPE_CHALLENGES && $type === self::TYPE_ALL) {
                        $counts[$entity] = $this->challengesQuery(ignoreGrantIds: $dryRunGrantIdsToIgnore)->count();
                    } else {
                        $counts[$entity] = $this->countEligible($entity);
                    }
                } else {
                    $counts[$entity] = $this->deleteEligible($entity, $batch);
                }
            } catch (Throwable $e) {
                $errors[] = [
                    'entity' => $entity,
                    'exception_class' => $e::class,
                    'message' => $this->sanitizeExceptionMessage($e),
                ];
                Log::error('akubica_otp_prune_failed', [
                    'entity' => $entity,
                    'exception_class' => $e::class,
                    'message' => $this->sanitizeExceptionMessage($e),
                    'environment' => app()->environment(),
                ]);
            }
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $result = new AkubicaOtpPruningResult(
            dryRun: $dryRun,
            batch: $batch,
            type: $type,
            challenges: $counts[self::TYPE_CHALLENGES],
            deliveryOperations: $counts[self::TYPE_DELIVERIES],
            rateLimits: $counts[self::TYPE_RATE_LIMITS],
            stepUpGrants: $counts[self::TYPE_GRANTS],
            secureDownloadLinks: $counts[self::TYPE_SECURE_LINKS],
            skipped: $skipped,
            durationMs: $durationMs,
            errors: $errors,
        );

        Log::info('akubica_otp_prune_completed', $result->toLogContext());

        return $result;
    }

    public function countEligible(string $type): int
    {
        return match ($type) {
            self::TYPE_SECURE_LINKS => $this->secureLinksQuery()->count(),
            self::TYPE_GRANTS => $this->grantsQuery()->count(),
            self::TYPE_DELIVERIES => $this->deliveriesQuery()->count(),
            self::TYPE_CHALLENGES => $this->challengesQuery()->count(),
            self::TYPE_RATE_LIMITS => $this->rateLimitsQuery()->count(),
            default => throw new \InvalidArgumentException("Tipo de prune invalido: {$type}"),
        };
    }

    /**
     * Candidate IDs using the same eligibility rules as delete (for tests / dry comparison).
     *
     * @return list<int>
     */
    public function candidateIds(string $type, ?int $limit = null): array
    {
        $query = match ($type) {
            self::TYPE_SECURE_LINKS => $this->secureLinksQuery()->orderBy('id'),
            self::TYPE_GRANTS => $this->grantsQuery()->orderBy('id'),
            self::TYPE_DELIVERIES => $this->deliveriesQuery()->orderBy('id'),
            self::TYPE_CHALLENGES => $this->challengesQuery()->orderBy('id'),
            self::TYPE_RATE_LIMITS => $this->rateLimitsQuery()->orderBy('id'),
            default => throw new \InvalidArgumentException("Tipo de prune invalido: {$type}"),
        };

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function deleteEligible(string $type, int $batch): int
    {
        $deleted = 0;

        do {
            $ids = $this->candidateIds($type, $batch);
            if ($ids === []) {
                break;
            }

            $deleted += match ($type) {
                self::TYPE_SECURE_LINKS => $this->deleteSecureLinksRevalidated($ids),
                self::TYPE_GRANTS => $this->deleteGrantsRevalidated($ids),
                self::TYPE_DELIVERIES => $this->deleteDeliveriesRevalidated($ids),
                self::TYPE_CHALLENGES => $this->deleteChallengesRevalidated($ids),
                self::TYPE_RATE_LIMITS => $this->deleteRateLimitsRevalidated($ids),
                default => 0,
            };
        } while (count($ids) === $batch);

        return $deleted;
    }

    /** @param list<int> $ids */
    private function deleteSecureLinksRevalidated(array $ids): int
    {
        return (int) $this->secureLinksQuery()->whereIn('id', $ids)->delete();
    }

    /** @param list<int> $ids */
    private function deleteGrantsRevalidated(array $ids): int
    {
        return (int) $this->grantsQuery()->whereIn('id', $ids)->delete();
    }

    /** @param list<int> $ids */
    private function deleteDeliveriesRevalidated(array $ids): int
    {
        return (int) $this->deliveriesQuery()->whereIn('id', $ids)->delete();
    }

    /** @param list<int> $ids */
    private function deleteChallengesRevalidated(array $ids): int
    {
        return (int) $this->challengesQuery()->whereIn('id', $ids)->delete();
    }

    /** @param list<int> $ids */
    private function deleteRateLimitsRevalidated(array $ids): int
    {
        return (int) $this->rateLimitsQuery()->whereIn('id', $ids)->delete();
    }

    /**
     * @return Builder<OtpSecureDownloadLink>
     */
    public function secureLinksQuery(?Carbon $now = null): Builder
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($this->retentionDays('secure_links_retention_days'));

        return OtpSecureDownloadLink::query()
            ->where(function (Builder $q) use ($now) {
                $q->whereNotNull('consumed_at')
                    ->orWhereNotNull('revoked_at')
                    ->orWhere('expires_at', '<=', $now);
            })
            ->whereRaw(
                'COALESCE(consumed_at, revoked_at, expires_at) < ?',
                [$cutoff]
            );
    }

    /**
     * Terminal grant past retention, without active secure links.
     * Orphan PAT alone does not qualify unless also expired/revoked.
     *
     * @return Builder<OtpStepUpGrant>
     */
    public function grantsQuery(?Carbon $now = null): Builder
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($this->retentionDays('grants_retention_days'));
        $patTable = (new PersonalAccessToken)->getTable();

        return OtpStepUpGrant::query()
            ->where(function (Builder $q) use ($now, $patTable) {
                $q->whereNotNull('revoked_at')
                    ->orWhere('expires_at', '<=', $now)
                    ->orWhere(function (Builder $orphan) use ($now, $patTable) {
                        // Orphan PAT path still requires terminality (expired or revoked).
                        $orphan->whereNotNull('personal_access_token_id')
                            ->whereNotExists(function ($sub) use ($patTable) {
                                $sub->select(DB::raw(1))
                                    ->from($patTable)
                                    ->whereColumn(
                                        $patTable.'.id',
                                        'otp_step_up_grants.personal_access_token_id'
                                    );
                            })
                            ->where(function (Builder $term) use ($now) {
                                $term->whereNotNull('revoked_at')
                                    ->orWhere('expires_at', '<=', $now);
                            });
                    });
            })
            ->whereRaw('COALESCE(revoked_at, expires_at) < ?', [$cutoff])
            ->whereNotExists(function ($sub) use ($now) {
                $sub->select(DB::raw(1))
                    ->from('otp_secure_download_links')
                    ->whereColumn(
                        'otp_secure_download_links.otp_step_up_grant_id',
                        'otp_step_up_grants.id'
                    )
                    ->whereNull('otp_secure_download_links.revoked_at')
                    ->whereNull('otp_secure_download_links.consumed_at')
                    ->where('otp_secure_download_links.expires_at', '>', $now);
            });
    }

    /**
     * @return Builder<OtpDeliveryOperation>
     */
    public function deliveriesQuery(?Carbon $now = null): Builder
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($this->retentionDays('deliveries_retention_days'));

        return OtpDeliveryOperation::query()
            ->whereIn('status', self::TERMINAL_DELIVERY_STATUSES)
            ->where('updated_at', '<', $cutoff);
    }

    /**
     * Terminal challenge past retention; no registration intent; no remaining grants.
     *
     * @param  list<int>  $ignoreGrantIds  Grant IDs treated as already pruned (dry-run order sim).
     * @return Builder<OtpChallenge>
     */
    public function challengesQuery(?Carbon $now = null, array $ignoreGrantIds = []): Builder
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($this->retentionDays('challenges_retention_days'));

        $query = OtpChallenge::query()
            ->where(function (Builder $q) use ($now) {
                $q->whereNotNull('consumed_at')
                    ->orWhereNotNull('invalidated_at')
                    ->orWhere('expires_at', '<=', $now);
            })
            ->whereRaw(
                'COALESCE(consumed_at, invalidated_at, expires_at) < ?',
                [$cutoff]
            )
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('akubica_registration_intents')
                    ->whereColumn(
                        'akubica_registration_intents.otp_challenge_id',
                        'otp_challenges.id'
                    );
            });

        if ($ignoreGrantIds === []) {
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('otp_step_up_grants')
                    ->whereColumn(
                        'otp_step_up_grants.otp_challenge_id',
                        'otp_challenges.id'
                    );
            });
        } else {
            $query->whereNotExists(function ($sub) use ($ignoreGrantIds) {
                $sub->select(DB::raw(1))
                    ->from('otp_step_up_grants')
                    ->whereColumn(
                        'otp_step_up_grants.otp_challenge_id',
                        'otp_challenges.id'
                    )
                    ->whereNotIn('otp_step_up_grants.id', $ignoreGrantIds);
            });
        }

        return $query;
    }

    /**
     * @return Builder<OtpRateLimit>
     */
    public function rateLimitsQuery(?Carbon $now = null): Builder
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($this->retentionDays('rate_limits_retention_days'));
        $windowMinutes = (int) config('otp.p0a.anti_abuse.rate_limit_window_minutes', 30);

        // Window ended when window_started_at + windowMinutes <= now
        // ⇔ window_started_at <= now - windowMinutes (portable SQLite/MySQL).
        $windowEndedAt = $now->copy()->subMinutes($windowMinutes);

        return OtpRateLimit::query()
            ->where('updated_at', '<', $cutoff)
            ->where('window_started_at', '<=', $windowEndedAt)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('blocked_until')
                    ->orWhere('blocked_until', '<=', $now);
            });
    }

    /**
     * Terminal challenges past retention that still have a registration intent (skipped).
     */
    public function countChallengesSkippedForRegistrationIntent(?Carbon $now = null): int
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($this->retentionDays('challenges_retention_days'));

        return (int) OtpChallenge::query()
            ->where(function (Builder $q) use ($now) {
                $q->whereNotNull('consumed_at')
                    ->orWhereNotNull('invalidated_at')
                    ->orWhere('expires_at', '<=', $now);
            })
            ->whereRaw(
                'COALESCE(consumed_at, invalidated_at, expires_at) < ?',
                [$cutoff]
            )
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('akubica_registration_intents')
                    ->whereColumn(
                        'akubica_registration_intents.otp_challenge_id',
                        'otp_challenges.id'
                    );
            })
            ->count();
    }

    /**
     * Terminal challenges past retention blocked only by remaining grants.
     *
     * @param  list<int>  $ignoreGrantIds
     */
    public function countChallengesSkippedForRemainingGrants(?Carbon $now = null, array $ignoreGrantIds = []): int
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($this->retentionDays('challenges_retention_days'));

        $query = OtpChallenge::query()
            ->where(function (Builder $q) use ($now) {
                $q->whereNotNull('consumed_at')
                    ->orWhereNotNull('invalidated_at')
                    ->orWhere('expires_at', '<=', $now);
            })
            ->whereRaw(
                'COALESCE(consumed_at, invalidated_at, expires_at) < ?',
                [$cutoff]
            )
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('akubica_registration_intents')
                    ->whereColumn(
                        'akubica_registration_intents.otp_challenge_id',
                        'otp_challenges.id'
                    );
            });

        if ($ignoreGrantIds === []) {
            $query->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('otp_step_up_grants')
                    ->whereColumn(
                        'otp_step_up_grants.otp_challenge_id',
                        'otp_challenges.id'
                    );
            });
        } else {
            $query->whereExists(function ($sub) use ($ignoreGrantIds) {
                $sub->select(DB::raw(1))
                    ->from('otp_step_up_grants')
                    ->whereColumn(
                        'otp_step_up_grants.otp_challenge_id',
                        'otp_challenges.id'
                    )
                    ->whereNotIn('otp_step_up_grants.id', $ignoreGrantIds);
            });
        }

        return (int) $query->count();
    }

    /**
     * Terminal grants past retention that still have an active secure link.
     */
    public function countGrantsSkippedForActiveLinks(?Carbon $now = null): int
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($this->retentionDays('grants_retention_days'));

        return (int) OtpStepUpGrant::query()
            ->where(function (Builder $q) use ($now) {
                $q->whereNotNull('revoked_at')
                    ->orWhere('expires_at', '<=', $now);
            })
            ->whereRaw('COALESCE(revoked_at, expires_at) < ?', [$cutoff])
            ->whereExists(function ($sub) use ($now) {
                $sub->select(DB::raw(1))
                    ->from('otp_secure_download_links')
                    ->whereColumn(
                        'otp_secure_download_links.otp_step_up_grant_id',
                        'otp_step_up_grants.id'
                    )
                    ->whereNull('otp_secure_download_links.revoked_at')
                    ->whereNull('otp_secure_download_links.consumed_at')
                    ->where('otp_secure_download_links.expires_at', '>', $now);
            })
            ->count();
    }

    private function retentionDays(string $key): int
    {
        return max(1, (int) config("otp.p0a.cleanup.{$key}", 30));
    }

    private function sanitizeExceptionMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace('/\+?\d{10,15}/', '[redacted-phone]', $message) ?? $message;
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;
        $message = preg_replace('/\b[a-f0-9]{64}\b/i', '[redacted-hash]', $message) ?? $message;

        return mb_substr($message, 0, 240);
    }
}
