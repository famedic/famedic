<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    public function settingGroup(): BelongsTo
    {
        return $this->belongsTo(SettingGroup::class);
    }
}
