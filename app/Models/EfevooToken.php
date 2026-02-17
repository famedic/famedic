<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class EfevooToken extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'efevoo_tokens';
    
    protected $fillable = [
        'alias',
        'client_token',
        'card_token',
        'card_last_four',
        'card_brand',
        'card_expiration',
        'card_holder',
        'customer_id',
        'environment',
        'expires_at',
        'is_active',
        'metadata',
    ];
    
    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];
    
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    
    public function transactions()
    {
        return $this->hasMany(EfevooTransaction::class);
    }
    
    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where(function($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    });
    }
    
    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }
    
    public function scopeTest($query)
    {
        return $query->where('environment', 'test');
    }
    
    public function scopeProduction($query)
    {
        return $query->where('environment', 'production');
    }
    
    // Helper para formatear expiración
    public function getFormattedExpirationAttribute()
    {
        if (strlen($this->card_expiration) === 4) {
            $month = substr($this->card_expiration, 0, 2);
            $year = '20' . substr($this->card_expiration, 2, 2);
            return "$month/$year";
        }
        return $this->card_expiration;
    }
    
    // Helper para generar alias si no existe - ACTUALIZADO
    public function generateAlias(string $defaultBrand = null): string
    {
        if (empty($this->alias)) {
            $brand = $defaultBrand ?: strtolower($this->card_brand);
            $lastFour = $this->card_last_four;
            $this->alias = "{$brand}-{$lastFour}";
            
            // Si se cambió, guardar
            if ($this->exists) {
                $this->save();
            }
        }
        return $this->alias;
    }
    
    // Validación del alias
    public static function validateAlias(string $alias): bool
    {
        return strlen($alias) <= 50 && preg_match('/^[a-zA-Z0-9\s\-_]+$/', $alias);
    }
}