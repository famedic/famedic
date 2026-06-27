<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CouponConcept extends Model
{
    protected $fillable = [
        'title',
        'description',
    ];

    public static function findOrCreateByTitle(string $title): self
    {
        $normalized = trim($title);
        if ($normalized === '') {
            throw new \InvalidArgumentException('El título del concepto no puede estar vacío.');
        }

        $existing = static::query()
            ->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower($normalized)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::create([
            'title' => $normalized,
            'description' => null,
        ]);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'coupon_concept_id');
    }
}
