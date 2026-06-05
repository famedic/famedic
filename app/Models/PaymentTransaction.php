<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'payment_method_id',
        'related_type',
        'related_id',
        'provider',
        'flow',
        'folio',
        'reference',
        'previous_reference',
        'auth_code',
        'amount',
        'currency',
        'mode',
        'status',
        'bnrg_codigo_proc',
        'bnrg_codigo_proc_trans',
        'bnrg_codigo_rechazo',
        'bnrg_codigo_emisor',
        'bnrg_texto',
        'bnrg_estado_trans',
        'bnrg_tipo_trans',
        'raw_request',
        'raw_response_headers',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw_request' => 'array',
            'raw_response_headers' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
