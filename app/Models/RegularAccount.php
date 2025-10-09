<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegularAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function customer(): MorphOne
    {
        return $this->morphOne(Customer::class, 'customerable');
    }
}
