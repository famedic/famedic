<?php

namespace App\Services\DocumentInterpretation;

use Illuminate\Support\Facades\Log;

/**
 * Technical metrics only — never logs patient PHI from the prescription.
 */
class InterpretationMetricsLogger
{
    /**
     * @param  array<string, mixed>  $metrics
     */
    public function logSuccess(array $metrics): void
    {
        Log::info('clinical_interpreter.vision.success', $this->sanitize($metrics));
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public function logFailure(array $metrics): void
    {
        Log::warning('clinical_interpreter.vision.failure', $this->sanitize($metrics));
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function sanitize(array $metrics): array
    {
        $allowed = [
            'duration_ms',
            'model',
            'prompt_tokens',
            'completion_tokens',
            'total_tokens',
            'estimated_cost_usd',
            'prompt_version',
            'prompt_key',
            'prompt_status',
            'provider',
            'http_status',
            'error_class',
        ];

        return array_intersect_key($metrics, array_flip($allowed));
    }
}
