<?php

namespace App\Services\LaboratoryPreparation;

use Illuminate\Support\Str;
use RuntimeException;

/** A safe boundary exception: no provider message, previous exception or arbitrary context. */
final class LaboratoryPreparationOperationalException extends RuntimeException
{
    public readonly string $stage;
    public readonly string $correlationId;
    public readonly string $causeCategory;

    public function __construct(string $stage, string $causeCategory = 'unexpected')
    {
        $this->stage = in_array($stage, ['job', 'generation', 'fidelity', 'legacy_generation', 'deterministic_generation', 'redaction', 'notification'], true)
            ? $stage : 'job';
        $this->causeCategory = in_array($causeCategory, ['provider', 'validation', 'persistence', 'unexpected'], true)
            ? $causeCategory : 'unexpected';
        $this->correlationId = (string) Str::uuid();
        parent::__construct('laboratory_preparation_'.$this->stage.'_failed correlation='.$this->correlationId.' category='.$this->causeCategory);
    }

    public function context(): array
    {
        return ['error_code' => 'laboratory_preparation_'.$this->stage.'_failed',
            'stage' => $this->stage, 'cause_category' => $this->causeCategory, 'correlation_id' => $this->correlationId];
    }
}
