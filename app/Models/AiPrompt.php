<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPrompt extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'key',
        'domain',
        'version',
        'status',
        'model',
        'system_prompt',
        'user_prompt',
        'response_schema',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'response_schema' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AiExecution::class, 'prompt_id');
    }
}
