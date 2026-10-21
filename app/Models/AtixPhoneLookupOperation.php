<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtixPhoneLookupOperation extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED_FINAL = 'failed_final';

    public const STATUS_FAILED_UNCERTAIN = 'failed_uncertain';

    protected $table = 'atix_phone_lookup_operations';

    protected $guarded = [];

    protected $hidden = [
        'response_body',
        'integration_key_hash',
        'channel_event_hash',
        'fingerprint',
        'idempotency_key_hash',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'encrypted',
            'response_headers' => 'array',
            'http_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
