<?php

namespace Tests\Support\LaboratoryPreparation;

use App\Models\AiPrompt;
use RuntimeException;

trait PreservesOfficialPreparationPrompts
{
    protected array $exceptTables = ['ai_prompts'];

    protected function assertOfficialPreparationPromptsAvailable(): void
    {
        foreach ([
            ['laboratory_preparation_summary', 3],
            ['laboratory_preparation_redactor', 1],
        ] as [$key, $version]) {
            $available = AiPrompt::query()
                ->where('domain', 'laboratory')
                ->where('key', $key)
                ->where('version', $version)
                ->where('status', AiPrompt::STATUS_ACTIVE)
                ->exists();

            if (! $available) {
                throw new RuntimeException("Official laboratory preparation prompt {$key} v{$version} is missing or inactive.");
            }
        }
    }
}
