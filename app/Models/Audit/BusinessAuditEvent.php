<?php

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only business audit event (Block 6A).
 *
 * Created exclusively via BusinessAuditEventWriter. No updated_at. No CRUD
 * endpoints. Mass assignment is restricted to writer-accepted columns only.
 *
 * Application-level append-only protection does not prevent direct SQL
 * modifications by database administrators.
 */
class BusinessAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'business_audit_events';

    /**
     * Columns accepted by the writer (and mass assignment).
     *
     * @var list<string>
     */
    public const WRITER_ATTRIBUTES = [
        'public_id',
        'occurred_at',
        'event_name',
        'outcome',
        'channel',
        'actor_type',
        'actor_key',
        'actor_user_id',
        'actor_customer_id',
        'subject_type',
        'subject_key',
        'resource_type',
        'resource_key',
        'correlation_id',
        'error_code',
        'retryable',
        'metadata',
        'created_at',
    ];

    protected $fillable = self::WRITER_ATTRIBUTES;

    protected $hidden = [
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'metadata' => 'array',
            'retryable' => 'boolean',
            'actor_user_id' => 'integer',
            'actor_customer_id' => 'integer',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(
                'BusinessAuditEvent is append-only; updates after create are not supported.'
            );
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException(
            'BusinessAuditEvent is append-only; update() is not supported.'
        );
    }

    public function delete(): ?bool
    {
        throw new LogicException(
            'BusinessAuditEvent is append-only; ordinary delete() is not supported. '
            .'Cleanup belongs to a later maintenance block.'
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fillWriterAttributes(array $attributes): static
    {
        $allowed = array_intersect_key(
            $attributes,
            array_flip(self::WRITER_ATTRIBUTES)
        );

        return $this->fill($allowed);
    }
}
