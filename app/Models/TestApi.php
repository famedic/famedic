<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestApi extends Model
{
    //
    protected $table = 'test_apis';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'value',
    ];

    protected $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

