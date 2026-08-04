<?php

namespace App\Models\Api\V1;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only API v1 audit event.
 *
 * Created exclusively via AuditEventWriter. No updated_at. No CRUD endpoints.
 * Mass assignment is restricted to writer-accepted columns only.
 */
class ApiV1AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'api_v1_audit_events';

    /**
     * Columns accepted by the writer (and mass assignment).
     *
     * @var list<string>
     */
    public const WRITER_ATTRIBUTES = [
        'event_name',
        'occurred_at',
        'correlation_id',
        'related_correlation_id',
        'actor_type',
        'actor_key',
        'customer_id',
        'user_id',
        'personal_access_token_id',
        'resource_type',
        'resource_key',
        'route_name',
        'method',
        'outcome',
        'http_status',
        'error_code',
        'retryable',
        'idempotency_record_id',
        'idempotency_effect',
        'ip_hash',
        'user_agent_hash',
        'metadata',
        'created_at',
    ];

    protected $fillable = self::WRITER_ATTRIBUTES;

    protected $hidden = [
        'ip_hash',
        'user_agent_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'metadata' => 'array',
            'retryable' => 'boolean',
            'customer_id' => 'integer',
            'user_id' => 'integer',
            'personal_access_token_id' => 'integer',
            'http_status' => 'integer',
            'idempotency_record_id' => 'integer',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(
                'ApiV1AuditEvent is append-only; updates after create are not supported.'
            );
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException(
            'ApiV1AuditEvent is append-only; update() is not supported.'
        );
    }

    public function delete(): ?bool
    {
        throw new LogicException(
            'ApiV1AuditEvent is append-only; ordinary delete() is not supported. '
            .'Cleanup belongs to a later maintenance block.'
        );
    }

    /**
     * Force-fill without going through guarded mass assignment of secrets
     * outside the writer allowlist (defensive; writer already filters).
     *
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
