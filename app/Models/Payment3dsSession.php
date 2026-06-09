<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment3dsSession extends Model
{
    protected $table = 'payment_3ds_sessions';

    protected $fillable = [
        'user_id',
        'customer_id',
        'payment_method_id',
        'payment_attempt_id',
        'payment_transaction_id',
        'related_type',
        'related_id',
        'provider',
        'flow',
        'folio',
        'reference',
        'amount',
        'currency',
        'mode',
        'status',
        'redirect_url',
        'response_url',
        'eci',
        'ucaf',
        'xid',
        'auth_code',
        'issuer_code',
        'bnrg_reference',
        'bnrg_text',
        'bnrg_codigo_proc',
        'bnrg_codigo_rechazo',
        'bnrg_card_type',
        'bnrg_account_type',
        'bnrg_issuing_bank',
        'request_hash',
        'response_hash',
        'hash_valid',
        'checkout_context',
        'raw_request',
        'raw_response',
        'completed_at',
        'failed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'hash_valid' => 'boolean',
            'checkout_context' => 'array',
            'raw_request' => 'array',
            'raw_response' => 'array',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function markRedirected(string $url): void
    {
        $this->update([
            'redirect_url' => $url,
            'status' => 'redirect_required',
        ]);
    }

    public function markApproved(array $payload): void
    {
        $this->update([
            'status' => 'approved',
            'bnrg_codigo_proc' => $payload['BNRG_CODIGO_PROC'] ?? $this->bnrg_codigo_proc,
            'bnrg_reference' => $payload['BNRG_REFERENCIA'] ?? $this->bnrg_reference,
            'auth_code' => $payload['BNRG_CODIGO_AUT'] ?? $this->auth_code,
            'issuer_code' => $payload['BNRG_CODIGO_EMISOR'] ?? $this->issuer_code,
            'bnrg_text' => $payload['BNRG_TEXTO'] ?? $this->bnrg_text,
            'eci' => $payload['BNRG_3DS_ECI'] ?? $this->eci,
            'ucaf' => $payload['BNRG_3DS_UCAF'] ?? $this->ucaf,
            'xid' => $payload['BNRG_3DS_XID'] ?? $this->xid,
            'bnrg_codigo_rechazo' => $payload['BNRG_CODIGO_RECHAZO'] ?? $this->bnrg_codigo_rechazo,
            'response_hash' => $payload['BNRG_HASH'] ?? $this->response_hash,
            'raw_response' => $payload,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(array $payload, string $status = 'failed'): void
    {
        $this->update([
            'status' => $status,
            'bnrg_codigo_proc' => $payload['BNRG_CODIGO_PROC'] ?? $this->bnrg_codigo_proc,
            'bnrg_reference' => $payload['BNRG_REFERENCIA'] ?? $this->bnrg_reference,
            'bnrg_text' => $payload['BNRG_TEXTO'] ?? $this->bnrg_text,
            'bnrg_codigo_rechazo' => $payload['BNRG_CODIGO_RECHAZO'] ?? $this->bnrg_codigo_rechazo,
            'response_hash' => $payload['BNRG_HASH'] ?? $this->response_hash,
            'raw_response' => $payload,
            'failed_at' => now(),
        ]);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved' && strtoupper((string) $this->bnrg_codigo_proc) === 'A';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'redirect_required', 'processing'], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
