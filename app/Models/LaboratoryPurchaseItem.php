<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryPurchaseItem extends Model
{
    use HasFactory, SoftDeletes;

    protected static $unguarded = true;

    protected $appends = [
        'formatted_price',
    ];

    protected function casts(): array
    {
        return [
            'feature_list' => 'array',
            'laboratory_test_id' => 'integer',
        ];
    }

    /**
     * BLQ-A1-01: laboratory_test_id is a historical snapshot of the study that was bought. It may be set once
     * (null → id) but never changed or cleared through Eloquent (create/fill/update/save/mass assignment).
     * Raw query-builder writes bypass model events; the only one that touches the column is the backfill
     * command, which writes exclusively WHERE laboratory_test_id IS NULL.
     */
    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            if ($item->getOriginal('laboratory_test_id') !== null && $item->isDirty('laboratory_test_id')) {
                throw new \LogicException('laboratory_purchase_items.laboratory_test_id is a historical reference and cannot be changed once persisted.');
            }
        });
    }

    protected function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->price_cents),
        );
    }

    public function laboratoryPurchase()
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }
}
