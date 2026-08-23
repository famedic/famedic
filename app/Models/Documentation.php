<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Documentation extends Model
{
    protected $table = 'documentation';

    protected $fillable = [
        'privacy_policy',
        'terms_of_service',
    ];
}
