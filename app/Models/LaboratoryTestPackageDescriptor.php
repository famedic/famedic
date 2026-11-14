<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class LaboratoryTestPackageDescriptor extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending_commercial_validation';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_REJECTED = 'rejected';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $descriptor): void {
            if (! in_array($descriptor->validation_status, [
                self::STATUS_PENDING,
                self::STATUS_VALIDATED,
                self::STATUS_REJECTED,
            ], true)) {
                throw new InvalidArgumentException('Invalid package descriptor validation status.');
            }
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTestPackage::class, 'package_id');
    }
}
