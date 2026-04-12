<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettingGroup extends Model
{
    protected $guarded = [];

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class)->orderBy('sort_order')->orderBy('id');
    }
}
