<?php

namespace App\Services\LaboratoryPreparation;

use Illuminate\Support\Facades\Log;

final class LaboratoryPreparationInstructionsTextFormatter
{
    private const MAX_LENGTH = 65000;

    /**
     * @param  array<string, mixed>  $presentation  Output of LaboratoryPreparationPresenter::present()
     */
    public function format(array $presentation): string
    {
        $aiStatus = (string) ($presentation['ai_status'] ?? '');
        $summary = is_array($presentation['summary'] ?? null) ? $presentation['summary'] : [];
        $studies = is_array($presentation['studies'] ?? null) ? $presentation['studies'] : [];

        $blocks = $aiStatus === LaboratoryPreparationPresenter::STATUS_AI_READY
            ? $this->formatAiReady($summary, $studies)
            : $this->formatPerStudy($studies);

        $text = trim(implode("\n\n", array_filter($blocks, static fn (string $block) => trim($block) !== '')));

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            Log::warning('laboratory_preparation_ac_instructions_truncated', [
                'original_length' => mb_strlen($text),
                'max_length' => self::MAX_LENGTH,
            ]);
            $text = mb_substr($text, 0, self::MAX_LENGTH - 3).'...';
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $studies
     * @return list<string>
     */
    private function formatAiReady(array $summary, array $studies): array
    {
        $blocks = [];

        foreach (array_values($summary['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $title = trim((string) ($section['title'] ?? 'Preparación'));
            $parts = $this->instructionParts($section['content'] ?? '');
            if ($parts === []) {
                continue;
            }

            $lines = array_map(static fn (string $part): string => '• '.$part, $parts);
            $blocks[] = $title."\n".implode("\n", $lines);
        }

        $special = array_values($summary['special_instructions'] ?? []);
        if ($special !== []) {
            $specialLines = ['IMPORTANTE:'];
            foreach ($special as $instruction) {
                if (! is_array($instruction)) {
                    continue;
                }
                foreach ($this->instructionParts($instruction['content'] ?? '') as $part) {
                    $specialLines[] = '• '.$part;
                }
            }
            if (count($specialLines) > 1) {
                $blocks[] = implode("\n", $specialLines);
            }
        }

        $individual = array_values($summary['individual_instructions'] ?? []);
        if ($individual !== []) {
            foreach ($individual as $instruction) {
                if (! is_array($instruction)) {
                    continue;
                }
                $studyName = trim((string) ($instruction['study_name'] ?? 'Estudio'));
                $parts = $this->instructionParts($instruction['content'] ?? '');
                if ($parts === []) {
                    continue;
                }
                $lines = array_map(static fn (string $part): string => '• '.$part, $parts);
                $blocks[] = $studyName."\n".implode("\n", $lines);
            }
        }

        if ($blocks === []) {
            return $this->formatPerStudy($studies);
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $studies
     * @return list<string>
     */
    private function formatPerStudy(array $studies): array
    {
        $blocks = [];

        foreach ($studies as $study) {
            if (! is_array($study)) {
                continue;
            }

            $name = trim((string) ($study['name'] ?? 'Estudio'));
            $instructions = trim((string) ($study['instructions'] ?? ''));
            if ($instructions === '' || $instructions === '—') {
                continue;
            }

            $blocks[] = $name."\n".$instructions;
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function instructionParts(mixed $content): array
    {
        $text = trim((string) $content);

        if ($text === '') {
            return [];
        }

        $parts = preg_split('/(?:^|\s)•\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) <= 1) {
            $parts = preg_split('/\R+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        }

        return collect($parts)
            ->map(static function (string $part): string {
                $part = trim($part);

                return preg_replace('/^\x{2022}\s*/u', '', $part) ?? $part;
            })
            ->filter()
            ->values()
            ->all();
    }
}