<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MurguiaSyncLog extends Model
{
    public const ACTION_ALTA = 'alta';

    public const ACTION_BAJA = 'baja';

    public const ACTION_VALIDACION = 'validacion';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_FOUND = 'not_found';

    public const ENTRY_TYPE_BULK = 'bulk';

    public const ENTRY_TYPE_SINGLE = 'single';

    protected $fillable = [
        'customer_id',
        'triggered_by',
        'email',
        'medical_attention_identifier',
        'action',
        'request_payload',
        'response_payload',
        'status',
        'message',
        'entry_type',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
