<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActiveCampaignDispatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'activecampaign_dispatches';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_SYNCED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    protected $fillable = [
        'event_type',
        'entity_type',
        'entity_id',
        'related_entity_type',
        'related_entity_id',
        'user_id',
        'customer_id',
        'email',
        'idempotency_key',
        'status',
        'attempts',
        'last_error',
        'payload',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'synced_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SYNCED,
            self::STATUS_SKIPPED,
        ], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ], true);
    }
}
