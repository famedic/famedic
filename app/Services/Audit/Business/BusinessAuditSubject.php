<?php

namespace App\Services\Audit\Business;

use InvalidArgumentException;

/**
 * Optional subject — the person/account the fact is about when distinct from actor.
 *
 * Opaque typed key only. Never email, phone, RFC, or name.
 */
final class BusinessAuditSubject
{
    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_USER = 'user';

    /**
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_CUSTOMER,
        self::TYPE_USER,
    ];

    /**
     * @param  non-empty-string  $type
     * @param  non-empty-string  $key
     */
    public function __construct(
        public readonly string $type,
        public readonly string $key,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('business audit subject_type is not allowlisted.');
        }

        if ($key === '' || strlen($key) > 128) {
            throw new InvalidArgumentException('business audit subject_key length is invalid.');
        }
    }

    public static function customer(int $customerId): self
    {
        if ($customerId < 1) {
            throw new InvalidArgumentException('subject customer requires a positive id.');
        }

        return new self(self::TYPE_CUSTOMER, 'customer:'.$customerId);
    }

    public static function user(int $userId): self
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('subject user requires a positive id.');
        }

        return new self(self::TYPE_USER, 'user:'.$userId);
    }

    /**
     * @return array{subject_type: string, subject_key: string}
     */
    public function toWriterAttributes(): array
    {
        return [
            'subject_type' => $this->type,
            'subject_key' => $this->key,
        ];
    }
}
