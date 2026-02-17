<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EfevooTransaction extends Model
{
    use HasFactory;

    protected $table = 'efevoo_transactions';
    
    protected $fillable = [
        'efevoo_token_id',
        'transaction_id',
        'reference',
        'amount',
        'currency',
        'status',
        'response_code',
        'response_message',
        'transaction_type',
        'metadata',
        'request_data',
        'response_data',
        'cav',
        'msi',
        'fiid_comercio',
        'processed_at',
    ];
    
    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'request_data' => 'array',
        'response_data' => 'array',
        'processed_at' => 'datetime',
        'msi' => 'integer',
    ];
    
    // Tipos de transacción
    const TYPE_TOKENIZATION = 'tokenization';
    const TYPE_PAYMENT = 'payment';
    const TYPE_REFUND = 'refund';
    const TYPE_THREEDS = '3ds';
    
    // Estados
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_DECLINED = 'declined';
    const STATUS_ERROR = 'error';
    const STATUS_REFUNDED = 'refunded';
    
    public function token()
    {
        return $this->belongsTo(EfevooToken::class, 'efevoo_token_id');
    }
    
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
    
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
    
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
    
    public function isSuccessful()
    {
        return $this->status === self::STATUS_APPROVED;
    }
    
    public function canBeRefunded()
    {
        return $this->isSuccessful() && 
               $this->transaction_type === self::TYPE_PAYMENT &&
               $this->status !== self::STATUS_REFUNDED;
    }
}