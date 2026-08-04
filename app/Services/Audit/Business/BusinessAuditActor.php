<?php

namespace App\Services\Audit\Business;

use InvalidArgumentException;

/**
 * Immutable resolved actor for a business audit event.
 *
 * Never carries email, phone, IP, session, cookie, token, or payment references.
 * Construction is explicit — no automatic resolution from Request.
 */
final class BusinessAuditActor
{
    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_ADMIN = 'admin';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_INTEGRATION = 'integration';

    /**
     * Allowlisted system actor aliases (without `system:` prefix).
     *
     * @var list<string>
     */
    public const SYSTEM_ALIASES = [
        'scheduler',
        'console',
        'maintenance',
        'worker',
    ];

    /**
     * Allowlisted integration aliases (without `integration:` prefix).
     *
     * @var list<string>
     */
    public const INTEGRATION_ALIASES = [
        'paypal',
        'efevoo',
        'gda',
        'odessa',
        'stripe',
    ];

    /**
     * @param  non-empty-string  $type
     * @param  non-empty-string  $key
     */
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly ?int $actorUserId = null,
        public readonly ?int $actorCustomerId = null,
    ) {
        if (! in_array($type, self::types(), true)) {
            throw new InvalidArgumentException('business audit actor_type is not allowlisted.');
        }

        if ($key === '' || strlen($key) > 128) {
            throw new InvalidArgumentException('business audit actor_key length is invalid.');
        }
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_CUSTOMER,
            self::TYPE_ADMIN,
            self::TYPE_SYSTEM,
            self::TYPE_INTEGRATION,
        ];
    }

    public static function customer(int $customerId, ?int $userId = null): self
    {
        if ($customerId < 1) {
            throw new InvalidArgumentException('customer actor requires a positive customer id.');
        }

        return new self(
            type: self::TYPE_CUSTOMER,
            key: 'customer:'.$customerId,
            actorUserId: $userId !== null && $userId > 0 ? $userId : null,
            actorCustomerId: $customerId,
        );
    }

    public static function admin(int $userId): self
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('admin actor requires a positive user id.');
        }

        return new self(
            type: self::TYPE_ADMIN,
            key: 'admin:'.$userId,
            actorUserId: $userId,
            actorCustomerId: null,
        );
    }

    public static function system(string $alias): self
    {
        $alias = strtolower(trim($alias));
        if (str_starts_with($alias, 'system:')) {
            $alias = substr($alias, strlen('system:'));
        }

        if (! in_array($alias, self::SYSTEM_ALIASES, true)) {
            throw new InvalidArgumentException('system actor alias is not allowlisted.');
        }

        return new self(
            type: self::TYPE_SYSTEM,
            key: 'system:'.$alias,
        );
    }

    public static function integration(string $alias): self
    {
        $alias = strtolower(trim($alias));
        if (str_starts_with($alias, 'integration:')) {
            $alias = substr($alias, strlen('integration:'));
        }

        if (! in_array($alias, self::INTEGRATION_ALIASES, true)) {
            throw new InvalidArgumentException('integration actor alias is not allowlisted.');
        }

        return new self(
            type: self::TYPE_INTEGRATION,
            key: 'integration:'.$alias,
        );
    }

    /**
     * @return array{
     *     actor_type: string,
     *     actor_key: string,
     *     actor_user_id: int|null,
     *     actor_customer_id: int|null
     * }
     */
    public function toWriterAttributes(): array
    {
        return [
            'actor_type' => $this->type,
            'actor_key' => $this->key,
            'actor_user_id' => $this->actorUserId,
            'actor_customer_id' => $this->actorCustomerId,
        ];
    }
}
