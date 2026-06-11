<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpCode extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_EMAIL = 'email';

    public const PURPOSE_AKUBICA_LOGIN = 'akubica_login';

    public const PURPOSE_AKUBICA_REGISTER = 'akubica_register';

    protected $fillable = [
        'user_id',
        'laboratory_purchase_id',
        'email',
        'purpose',
        'payload',
        'channel',
        'code',
        'expires_at',
        'attempts',
        'max_attempts',
        'status',
        'verified_at',
        'used_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'used_at' => 'datetime',
        'payload' => 'array',
    ];

    public function scopeActiveAuthFor($query, string $email, string $purpose)
    {
        return $query
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->where('status', self::STATUS_PENDING)
            ->whereNull('used_at')
            ->where('expires_at', '>', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }
}
