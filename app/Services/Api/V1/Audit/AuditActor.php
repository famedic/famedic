<?php

namespace App\Services\Api\V1\Audit;

/**
 * Immutable resolved actor for an audit event. Never carries bearer material.
 */
final class AuditActor
{
    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_PUBLIC = 'public';

    public const TYPE_SYSTEM = 'system';

    /**
     * @param  non-empty-string  $type
     * @param  non-empty-string  $key
     */
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly ?int $customerId = null,
        public readonly ?int $userId = null,
        public readonly ?int $personalAccessTokenId = null,
    ) {}

    /**
     * @return array{
     *     actor_type: string,
     *     actor_key: string,
     *     customer_id: int|null,
     *     user_id: int|null,
     *     personal_access_token_id: int|null
     * }
     */
    public function toWriterAttributes(): array
    {
        return [
            'actor_type' => $this->type,
            'actor_key' => $this->key,
            'customer_id' => $this->customerId,
            'user_id' => $this->userId,
            'personal_access_token_id' => $this->personalAccessTokenId,
        ];
    }
}
