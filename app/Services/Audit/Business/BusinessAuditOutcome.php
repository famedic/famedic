<?php

namespace App\Services\Audit\Business;

/**
 * Stable outcome vocabulary for business audit events (Block 6A).
 */
final class BusinessAuditOutcome
{
    /** Principal effect confirmed. */
    public const SUCCEEDED = 'succeeded';

    /** Known rule denied the attempt before completing the effect. */
    public const REJECTED = 'rejected';

    /** System knows the effect did not complete. */
    public const FAILED = 'failed';

    /** Cannot determine whether the principal effect was confirmed. */
    public const UNCERTAIN = 'uncertain';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SUCCEEDED,
            self::REJECTED,
            self::FAILED,
            self::UNCERTAIN,
        ];
    }

    public static function isValid(string $outcome): bool
    {
        return in_array($outcome, self::all(), true);
    }
}
