<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostalCodeLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'confidence' => 'decimal:4',
        ];
    }
}
