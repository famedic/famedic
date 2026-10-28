<?php

namespace App\Models;

use App\Enums\LaboratoryResultAiExplanationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryResultAiExplanation extends Model
{
    use HasFactory;

    protected $fillable = [
        'laboratory_result_observation_id',
        'status',
        'explanation',
        'limitations',
        'input_hash',
        'prompt_version',
        'ai_execution_id',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LaboratoryResultAiExplanationStatus::class,
            'prompt_version' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultObservation::class, 'laboratory_result_observation_id');
    }

    public function aiExecution(): BelongsTo
    {
        return $this->belongsTo(AiExecution::class);
    }
}
