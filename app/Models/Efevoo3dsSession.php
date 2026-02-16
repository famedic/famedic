<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Efevoo3dsSession extends Model
{
    use HasFactory;

    protected $table = 'efevoo_3ds_sessions';

    protected $fillable = [
        'customer_id',
        'card_last_four',
        'amount',
        'status',
        'order_id',
        'token_3dsecure',
        'url_3dsecure',
        'request_data',
        'response_data',
        'status_check_response',
        'callback_data',
        'efevoo_token_id',
        'error_message',
        'created_at',
        'updated_at',
        'status_checked_at',
        'callback_received_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'status_check_response' => 'array',
        'callback_data' => 'array',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'status_checked_at' => 'datetime',
        'callback_received_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // Estados posibles
    const STATUS_PENDING = 'pending';
    const STATUS_REDIRECT_REQUIRED = 'redirect_required';
    const STATUS_NO_3DS_REQUIRED = 'no_3ds_required';
    const STATUS_AUTHENTICATED = 'authenticated';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_TOKENIZATION_FAILED = 'tokenization_failed';
    const STATUS_ERROR = 'error';
    const STATUS_CANCELLED = 'cancelled';

    // Relaciones
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function efevooToken()
    {
        return $this->belongsTo(EfevooToken::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeOlderThan($query, $hours = 24)
    {
        return $query->where('created_at', '<', now()->subHours($hours));
    }
}