<?php

namespace App\Support;

use App\Services\OpenAi\OpenAiClient;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRedactionValidator;

final class LocalQaOpenAiClient extends OpenAiClient
{
    public function chatCompletionWithMetadata(array $messages, ?string $model = null, ?array $jsonSchema = null,
        ?string $schemaName = null, float $temperature = 0, ?int $timeoutSeconds = null): array
    {
        if ($schemaName !== 'laboratory_preparation_redactor') {
            throw new \RuntimeException('local_qa_openai_feature_blocked');
        }
        $scenario = config('local-checkout-qa.scenario');
        if (! in_array($scenario, ['auto', 'fallback', 'late', 'error'], true)) {
            throw new \RuntimeException('local_qa_scenario_invalid');
        }
        if ($scenario === 'error') {
            throw new \RuntimeException('local_qa_provider_error');
        }
        if ($scenario === 'fallback') {
            return ['content' => [], 'model' => 'local-qa', 'usage' => [], 'raw' => []];
        }
        if ($scenario === 'late') {
            sleep(20); // Real wall time exceeds the coordinator's 15 second budget.
        }
        // The real payload builder provides the clinical text. Copy it without inventing instructions.
        $user = collect($messages)->last(fn ($message) => $message['role'] === 'user')['content'] ?? '';
        $start = strpos($user, '{');
        $end = strrpos($user, '}');
        $payload = $start !== false && $end !== false ? json_decode(substr($user, $start, $end - $start + 1), true) : null;
        $lines = array_values(array_unique(array_filter(array_map(
            fn ($requirement) => trim((string) ($requirement['text'] ?? '')), $payload['requirements'] ?? []))));
        if ($lines === []) {
            throw new \RuntimeException('local_qa_payload_missing');
        }
        return ['content' => ['title' => LaboratoryPreparationRedactionValidator::TITLE,
            'mode' => 'AUTO_CONSOLIDATED', 'patient_text' => implode("\n", $lines), 'bullet_count' => count($lines)],
            'model' => 'local-qa', 'usage' => [], 'raw' => []];
    }
}
