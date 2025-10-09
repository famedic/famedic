<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OdessaAfiliateAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function customer()
    {
        return $this->morphOne(Customer::class, 'customerable');
    }

    public function odessaAfiliatedCompany(): BelongsTo
    {
        return $this->belongsTo(OdessaAfiliatedCompany::class);
    }
}
