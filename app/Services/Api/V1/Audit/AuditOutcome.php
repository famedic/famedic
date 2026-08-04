<?php

namespace App\Services\Api\V1\Audit;

/**
 * Stable outcome vocabulary for API v1 audit events (Block 1+).
 */
final class AuditOutcome
{
    /** Business operation completed as intended. */
    public const SUCCEEDED = 'succeeded';

    /** Expected security/business rejection (invalid code, ownership, cooldown, etc.). */
    public const REJECTED = 'rejected';

    /** Infrastructure / delivery / unexpected failure. */
    public const FAILED = 'failed';

    /** Effect cannot be asserted with certainty (e.g. partial post-commit). */
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
}
