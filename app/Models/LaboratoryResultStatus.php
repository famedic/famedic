<?php

namespace App\Models;

use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaboratoryResultStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'laboratory_purchase_id',
        'laboratory_purchase_item_id',
        'status',
        'first_available_at',
        'interpreted_at',
        'last_checked_at',
        'next_check_at',
        'check_attempts',
        'completed_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LaboratoryResultStatusEnum::class,
            'first_available_at' => 'datetime',
            'interpreted_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'completed_notified_at' => 'datetime',
            'check_attempts' => 'integer',
        ];
    }

    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }

    public function laboratoryPurchaseItem(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchaseItem::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LaboratoryResultVersion::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LaboratoryResultEvent::class);
    }
}
