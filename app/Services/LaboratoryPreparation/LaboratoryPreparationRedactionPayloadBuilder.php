<?php

namespace App\Services\LaboratoryPreparation;

use RuntimeException;

final class LaboratoryPreparationRedactionPayloadBuilder
{
    /**
     * @return array{
     *     status: string,
     *     order_id: string,
     *     rules_version: string|null,
     *     rules_applied: list<string>,
     *     requirements: list<array<string, mixed>>,
     *     operational_requirements: array<string, mixed>
     * }
     */
    public function build(LaboratoryPreparationDecision $decision): array
    {
        if ($decision->status !== LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED) {
            throw new RuntimeException('Only AUTO_CONSOLIDATED decisions can be redacted by OpenAI.');
        }

        $requirements = $this->requirementsFromConsolidated($decision->consolidatedRequirements ?? []);
        if ($requirements === []) {
            throw new RuntimeException('AUTO_CONSOLIDATED decision has no patient-facing requirements.');
        }

        return [
            'status' => $decision->status,
            'order_id' => $decision->orderId,
            'rules_version' => $decision->rulesVersion,
            'rules_applied' => $decision->rulesApplied,
            'requirements' => $requirements,
            'operational_requirements' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $consolidated
     * @return list<array<string, mixed>>
     */
    private function requirementsFromConsolidated(array $consolidated): array
    {
        $requirements = [];

        if (isset($consolidated['fasting']) && is_array($consolidated['fasting'])) {
            $requirements[] = [
                'id' => 'fasting',
                'category' => 'fasting',
                'text' => $this->fastingText($consolidated['fasting']),
                'normalized_value' => $this->withoutSources($consolidated['fasting']),
            ];
        }

        if (isset($consolidated['hydration']) && is_array($consolidated['hydration'])) {
            $requirements[] = [
                'id' => 'hydration',
                'category' => 'hydration',
                'text' => $this->textFromStructuredRequirement('Hidratación', $consolidated['hydration']),
                'normalized_value' => $this->withoutSources($consolidated['hydration']),
            ];
        }

        if (isset($consolidated['no_preparation']) && is_array($consolidated['no_preparation'])) {
            $message = trim((string) ($consolidated['no_preparation']['message'] ?? ''));
            if ($message !== '') {
                $requirements[] = [
                    'id' => 'no_preparation',
                    'category' => 'no_preparation',
                    'text' => $message,
                    'normalized_value' => ['kind' => 'no_preparation'],
                ];
            }
        }

        $this->appendSourceBlocks($requirements, $consolidated);

        return $this->dedupeRequirements($requirements);
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     * @param  array<string, mixed>  $consolidated
     */
    private function appendSourceBlocks(array &$requirements, array $consolidated): void
    {
        foreach ($consolidated as $key => $value) {
            if (! is_array($value) || ! isset($value['blocks']) || ! is_array($value['blocks'])) {
                continue;
            }

            foreach ($value['blocks'] as $blockIndex => $block) {
                if (! is_array($block) || ! isset($block['sources']) || ! is_array($block['sources'])) {
                    continue;
                }

                foreach ($block['sources'] as $sourceIndex => $source) {
                    if (! is_array($source)) {
                        continue;
                    }

                    $text = trim((string) ($source['source_span'] ?? ''));
                    if ($text === '') {
                        continue;
                    }

                    $requirements[] = [
                        'id' => $key.'_'.$blockIndex.'_'.$sourceIndex,
                        'category' => (string) ($source['category'] ?? $key),
                        'requirement_type' => (string) ($source['requirement_type'] ?? ''),
                        'text' => $text,
                        'normalized_value' => is_array($source['normalized_value'] ?? null)
                            ? $source['normalized_value']
                            : [],
                        'study_refs' => array_values(array_filter([
                            $source['study_id'] ?? null,
                        ], fn ($value) => is_string($value) && $value !== '')),
                    ];
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fasting
     */
    private function fastingText(array $fasting): string
    {
        if (isset($fasting['exact_hours'])) {
            return 'Ayuno de '.((int) $fasting['exact_hours']).' horas.';
        }

        if (isset($fasting['minimum_hours'], $fasting['maximum_hours'])) {
            return 'Ayuno de '.((int) $fasting['minimum_hours']).' a '.((int) $fasting['maximum_hours']).' horas.';
        }

        if (isset($fasting['minimum_hours'])) {
            return 'Ayuno de al menos '.((int) $fasting['minimum_hours']).' horas.';
        }

        return 'Ayuno requerido.';
    }

    /**
     * @param  array<string, mixed>  $requirement
     */
    private function textFromStructuredRequirement(string $label, array $requirement): string
    {
        $encoded = json_encode($this->withoutSources($requirement), JSON_UNESCAPED_UNICODE);

        return $label.': '.($encoded ?: '{}');
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function withoutSources(array $value): array
    {
        unset($value['sources'], $value['blocks'], $value['trace']);

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     * @return list<array<string, mixed>>
     */
    private function dedupeRequirements(array $requirements): array
    {
        $seen = [];
        $deduped = [];

        foreach ($requirements as $requirement) {
            $key = ($requirement['category'] ?? '').'|'.($requirement['text'] ?? '').'|'.json_encode($requirement['normalized_value'] ?? []);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $requirement;
        }

        return $deduped;
    }
}
