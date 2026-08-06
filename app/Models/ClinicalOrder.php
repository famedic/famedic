<?php

namespace App\Models;

use App\Enums\ClinicalOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ClinicalOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'session_id',
        'status',
        'document',
        'interpretation',
        'patient',
        'studies',
        'medications',
        'validation',
        'commercial',
        'packages',
        'clinical_summary',
        'cart_payload',
        'quote_payload',
        'integrations',
        'confidence',
        'studies_count',
        'medications_count',
        'subtotal_lab_cents',
        'subtotal_pharmacy_cents',
        'discount_cents',
        'total_cents',
        'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClinicalOrderStatus::class,
            'document' => 'array',
            'interpretation' => 'array',
            'patient' => 'array',
            'studies' => 'array',
            'medications' => 'array',
            'validation' => 'array',
            'commercial' => 'array',
            'packages' => 'array',
            'cart_payload' => 'array',
            'quote_payload' => 'array',
            'integrations' => 'array',
            'confidence' => 'float',
            'validated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ClinicalOrder $order) {
            if (! $order->uuid) {
                $order->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusLabel(): string
    {
        return $this->status instanceof ClinicalOrderStatus
            ? $this->status->label()
            : (string) $this->status;
    }

    /**
     * Compact summary for UI / future CRM · MI · Analytics feeds.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status?->value ?? $this->status,
            'status_label' => $this->statusLabel(),
            'created_at' => $this->created_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'operator' => [
                'id' => $this->user_id,
                'name' => $this->user?->full_name
                    ?? $this->user?->name
                    ?? $this->validation['operator_name']
                    ?? null,
            ],
            'total_cents' => $this->total_cents,
            'total' => function_exists('formattedCentsPrice')
                ? formattedCentsPrice($this->total_cents)
                : ('$'.number_format($this->total_cents / 100, 2)),
            'studies_count' => $this->studies_count,
            'medications_count' => $this->medications_count,
            'session_id' => $this->session_id,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * Full payload for show / reopen.
     *
     * @return array<string, mixed>
     */
    public function toDetailArray(): array
    {
        return [
            'summary' => $this->toSummaryArray(),
            'document' => $this->document,
            'interpretation' => $this->interpretation,
            'patient' => $this->patient,
            'studies' => $this->studies,
            'medications' => $this->medications,
            'validation' => $this->validation,
            'commercial' => $this->commercial,
            'packages' => $this->packages,
            'clinical_summary' => $this->clinical_summary,
            'cart_payload' => $this->cart_payload,
            'quote_payload' => $this->quote_payload,
            'integrations' => $this->integrations,
        ];
    }
}
