<?php

namespace App\Services\LaboratoryPreparation;

use Illuminate\Support\Arr;

class LaboratoryPreparationResponseFidelityValidator
{
    /**
     * @var list<string>
     */
    private const INVENTED_INSTRUCTION_PATTERNS = [
        '/seguir las instrucciones espec/i',
        '/instrucciones espec[ií]ficas que se proporcion/i',
        '/al momento de la recolecci[oó]n/i',
        '/instrucciones espec[ií]ficas de recolecci[oó]n/i',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const UNIT_VARIANTS = [
        'hora' => ['hora', 'horas'],
        'dia' => ['dia', 'dias', 'día', 'días'],
        'semana' => ['semana', 'semanas'],
        'mes' => ['mes', 'meses'],
        'minuto' => ['minuto', 'minutos'],
        'gramo' => ['gramo', 'gramos', 'g'],
        'ml' => ['ml'],
        'mg' => ['mg'],
        'porciento' => ['%', 'por ciento', 'porcentaje'],
    ];

    /**
     * Concrete preparation vocabulary. Only terms present in the source clause are required.
     *
     * @var list<string>
     */
    private const CONCRETE_TERMS = [
        'ultrasonido', 'gestacion', 'copia', 'interpretacion', 'recipiente', 'esteril',
        'ayuno', 'miccion', 'orina', 'chorro', 'ingestas', 'alimento', 'rosca', 'tapa',
        'tirosina', 'recien', 'nacido', 'documentacion', 'manana', 'mattutina', 'esterilizado',
    ];

    /**
     * @var array<string, string>
     */
    private const ORDINAL_ROOTS = [
        'primero' => '1',
        'primer' => '1',
        'primera' => '1',
        'segundo' => '2',
        'segunda' => '2',
        'tercero' => '3',
        'tercer' => '3',
        'tercera' => '3',
        'cuarto' => '4',
        'cuarta' => '4',
        'quinto' => '5',
        'quinta' => '5',
        'sexto' => '6',
        'sexta' => '6',
        'septimo' => '7',
        'septima' => '7',
        'octavo' => '8',
        'octava' => '8',
        'noveno' => '9',
        'novena' => '9',
        'decimo' => '10',
        'decima' => '10',
    ];

    /**
     * @var list<array{key: string, clause: string, response: string}>
     */
    private const PHRASE_CHECKS = [
        ['key' => 'quantity_at_least', 'clause' => '/al menos \d+/u', 'response' => '/al menos \d+/u'],
        ['key' => 'three_quarters', 'clause' => '/tres cuartas partes/u', 'response' => '/tres cuartas partes/u'],
        ['key' => 'first_morning_urine', 'clause' => '/primera orina/u', 'response' => '/primera orina/u'],
        ['key' => 'hours_after_last_void', 'clause' => '/despues de \d+ horas/u', 'response' => '/despues de \d+ horas/u'],
        ['key' => 'before_days', 'clause' => '/antes de los \d+/u', 'response' => '/antes de los \d+/u'],
        ['key' => 'discard_first_stream', 'clause' => '/desechar el primer chorro/u', 'response' => '/desechar el primer chorro|desecha el primer chorro/u'],
        ['key' => 'mid_stream', 'clause' => '/chorro medio/u', 'response' => '/chorro medio/u'],
        ['key' => 'copy_interpretation', 'clause' => '/copia de interpretacion/u', 'response' => '/copia de interpretacion/u'],
        ['key' => 'if_not_possible', 'clause' => '/si no es posible/u', 'response' => '/si no es posible|no es posible/u'],
    ];

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $input
     */
    public function validate(array $response, array $input): void
    {
        $this->assertItemsWithoutIndicationsAreNotSourced($response, $input);
        $this->assertNoInventedInstructions($response, $input);
        $this->assertIndicationFidelity($response, $input);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $input
     */
    private function assertItemsWithoutIndicationsAreNotSourced(array $response, array $input): void
    {
        $withoutIndications = collect($input['items'] ?? [])
            ->filter(fn (array $item) => ! filled($item['indications'] ?? null))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($withoutIndications === []) {
            return;
        }

        $sourcedIds = $this->sourcedItemIds($response);

        foreach ($withoutIndications as $itemId) {
            if (in_array($itemId, $sourcedIds, true)) {
                $this->fail(
                    "OpenAI response sources an item without preparation indications. Item [{$itemId}].",
                    $response,
                    $itemId,
                    ['sourced_item_without_indications'],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $input
     */
    private function assertNoInventedInstructions(array $response, array $input): void
    {
        $responseTexts = $this->antiInventionResponseTexts($response);
        $combinedResponseText = $this->normalizeText(implode("\n", array_column($responseTexts, 'text')));

        foreach (self::INVENTED_INSTRUCTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $combinedResponseText)) {
                $this->fail(
                    'OpenAI response contains invented generic preparation instructions.',
                    $response,
                    null,
                    ['invented_generic_instruction'],
                );
            }
        }

        $supportedIndicationText = $this->normalizeText($this->collectSourceIndicationsText($input));

        foreach ($input['items'] ?? [] as $item) {
            if (filled($item['indications'] ?? null)) {
                continue;
            }

            foreach ($this->normalizeFeatureList($item['feature_list'] ?? []) as $feature) {
                $normalizedFeature = $this->normalizeText($feature);
                if ($normalizedFeature === '' || mb_strlen($normalizedFeature) < 8) {
                    continue;
                }

                if (str_contains($supportedIndicationText, $normalizedFeature)) {
                    continue;
                }

                $matches = $this->matchingAntiInventionLocations($responseTexts, $normalizedFeature);
                if ($matches !== []) {
                    $featureHash = substr(hash('sha256', $normalizedFeature), 0, 12);
                    $locations = implode(',', $matches);

                    $this->fail(
                        'OpenAI response appears to derive preparation instructions from feature_list. '
                        ."Item [".((int) ($item['id'] ?? 0))."], feature_hash [{$featureHash}], "
                        ."locations [{$locations}], reason [feature_list_used_as_instruction].",
                        $response,
                        (int) ($item['id'] ?? 0) ?: null,
                        [
                            'feature_list_used_as_instruction',
                            "feature_hash:{$featureHash}",
                            "locations:{$locations}",
                        ],
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $input
     */
    private function assertIndicationFidelity(array $response, array $input): void
    {
        $responseText = $this->normalizeText($this->collectStructuredResponseText($response));

        foreach ($input['items'] ?? [] as $item) {
            $indications = trim((string) ($item['indications'] ?? ''));
            if ($indications === '') {
                continue;
            }

            foreach ($this->splitIndicationClauses($indications) as $clause) {
                $this->assertClauseIsRepresented($clause, $responseText, (int) $item['id'], $response);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function assertClauseIsRepresented(string $clause, string $responseText, int $itemId, array $response): void
    {
        $requirements = $this->extractCriticalRequirements($clause);
        $missingElements = [];

        foreach ($requirements['numbers'] as $number) {
            if (! preg_match('/\b'.preg_quote($number, '/').'\b/', $responseText)) {
                $missingElements[] = "number:{$number}";
            }
        }

        foreach ($requirements['units'] as $unitStem) {
            if (! $this->responseContainsUnit($unitStem, $responseText)) {
                $missingElements[] = "unit:{$unitStem}";
            }
        }

        foreach ($requirements['ordinals'] as $ordinal) {
            if (! $this->ordinalIsPresent($ordinal, $responseText)) {
                $missingElements[] = "ordinal:{$ordinal}";
            }
        }

        foreach ($requirements['concrete_terms'] as $term) {
            if (! $this->termIsPresent($term, $responseText)) {
                $missingElements[] = "concrete:{$term}";
            }
        }

        foreach ($requirements['phrases'] as $phraseKey) {
            if (! $this->phraseIsPresent($phraseKey, $responseText)) {
                $missingElements[] = "phrase:{$phraseKey}";
            }
        }

        if ($missingElements === []) {
            return;
        }

        $this->fail(
            "OpenAI response lost critical preparation information for item [{$itemId}]. Missing critical elements: "
            .implode(', ', $missingElements),
            $response,
            $itemId,
            $missingElements,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $missingElements
     */
    private function fail(string $message, array $response, ?int $itemId, array $missingElements): void
    {
        throw new LaboratoryPreparationFidelityValidationException(
            $message,
            $response,
            $itemId,
            $missingElements,
        );
    }

    private function termIsPresent(string $term, string $responseText): bool
    {
        if ($term === '') {
            return true;
        }

        if (str_contains($responseText, $term)) {
            return true;
        }

        $stemLength = min(6, mb_strlen($term));
        $stem = mb_substr($term, 0, $stemLength);

        return mb_strlen($stem) >= 4 && str_contains($responseText, $stem);
    }

    private function ordinalIsPresent(string $ordinal, string $responseText): bool
    {
        if ($this->termIsPresent($ordinal, $responseText)) {
            return true;
        }

        $digit = self::ORDINAL_ROOTS[$ordinal] ?? null;

        return $digit !== null && preg_match('/\b'.preg_quote($digit, '/').'\b/', $responseText) === 1;
    }

    private function phraseIsPresent(string $phraseKey, string $responseText): bool
    {
        foreach (self::PHRASE_CHECKS as $check) {
            if ($check['key'] !== $phraseKey) {
                continue;
            }

            return preg_match($check['response'], $responseText) === 1;
        }

        return false;
    }

    /**
     * @return array{
     *     numbers: list<string>,
     *     units: list<string>,
     *     ordinals: list<string>,
     *     concrete_terms: list<string>,
     *     phrases: list<string>
     * }
     */
    private function extractCriticalRequirements(string $clause): array
    {
        $normalizedClause = $this->normalizeText($clause);

        $numbers = [];
        if (preg_match_all('/\d+/', $clause, $matches)) {
            $numbers = array_values(array_unique($matches[0]));
        }

        $units = [];
        foreach (self::UNIT_VARIANTS as $stem => $variants) {
            foreach ($variants as $variant) {
                if (preg_match('/\b'.preg_quote($this->normalizeText($variant), '/').'\b/u', $normalizedClause)) {
                    $units[] = $stem;
                    break;
                }
            }
        }

        $ordinals = [];
        foreach (array_keys(self::ORDINAL_ROOTS) as $ordinal) {
            if (preg_match('/\b'.preg_quote($ordinal, '/').'\b/u', $normalizedClause)) {
                $ordinals[] = $ordinal;
            }
        }
        $ordinals = array_values(array_unique($ordinals));

        $concreteTerms = [];
        foreach (self::CONCRETE_TERMS as $term) {
            if (preg_match('/\b'.preg_quote($term, '/').'\b/u', $normalizedClause)) {
                $concreteTerms[] = $term;
            }
        }

        $phrases = [];
        foreach (self::PHRASE_CHECKS as $check) {
            if (preg_match($check['clause'], $normalizedClause)) {
                $phrases[] = $check['key'];
            }
        }

        return [
            'numbers' => $numbers,
            'units' => array_values(array_unique($units)),
            'ordinals' => $ordinals,
            'concrete_terms' => $concreteTerms,
            'phrases' => $phrases,
        ];
    }

    /**
     * @return list<string>
     */
    private function splitIndicationClauses(string $indications): array
    {
        $lines = preg_split('/\R/u', $indications) ?: [];
        $clauses = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/^[-•*]\s*/u', '', trim($line)));
            if ($line !== '') {
                $clauses[] = $line;
            }
        }

        if ($clauses === [] && trim($indications) !== '') {
            $clauses[] = trim($indications);
        }

        return $clauses;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<int>
     */
    private function sourcedItemIds(array $response): array
    {
        return collect(Arr::wrap($response['sections'] ?? []))
            ->flatMap(fn (array $section) => Arr::wrap($section['source_item_ids'] ?? []))
            ->merge(collect(Arr::wrap($response['special_instructions'] ?? []))
                ->flatMap(fn (array $instruction) => Arr::wrap($instruction['source_item_ids'] ?? [])))
            ->merge(collect(Arr::wrap($response['individual_instructions'] ?? []))
                ->map(fn (array $instruction) => (int) ($instruction['source_item_id'] ?? 0)))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Summary plus instructional content only. Titles and study names are identifiers.
     *
     * @param  array<string, mixed>  $response
     * @return list<array{location: string, text: string}>
     */
    private function antiInventionResponseTexts(array $response): array
    {
        $parts = [];

        $summary = trim((string) ($response['summary'] ?? ''));
        if ($summary !== '') {
            $parts[] = ['location' => 'summary', 'text' => $summary];
        }

        foreach (Arr::wrap($response['sections'] ?? []) as $index => $section) {
            $content = trim((string) ($section['content'] ?? ''));
            if ($content !== '') {
                $parts[] = ['location' => "sections.{$index}.content", 'text' => $content];
            }
        }

        foreach (Arr::wrap($response['special_instructions'] ?? []) as $index => $instruction) {
            $content = trim((string) ($instruction['content'] ?? ''));
            if ($content !== '') {
                $parts[] = ['location' => "special_instructions.{$index}.content", 'text' => $content];
            }
        }

        foreach (Arr::wrap($response['individual_instructions'] ?? []) as $index => $instruction) {
            $content = trim((string) ($instruction['content'] ?? ''));
            if ($content !== '') {
                $parts[] = ['location' => "individual_instructions.{$index}.content", 'text' => $content];
            }
        }

        return $parts;
    }

    /**
     * @param  list<array{location: string, text: string}>  $responseTexts
     * @return list<string>
     */
    private function matchingAntiInventionLocations(array $responseTexts, string $normalizedNeedle): array
    {
        return collect($responseTexts)
            ->filter(fn (array $part) => str_contains($this->normalizeText($part['text']), $normalizedNeedle))
            ->pluck('location')
            ->values()
            ->all();
    }

    /**
     * Structured preparation content only. Summary text is excluded from fidelity matching.
     *
     * @param  array<string, mixed>  $response
     */
    private function collectStructuredResponseText(array $response): string
    {
        $parts = [];

        foreach (Arr::wrap($response['sections'] ?? []) as $section) {
            $parts[] = trim((string) ($section['title'] ?? ''));
            $parts[] = trim((string) ($section['content'] ?? ''));
        }

        foreach (Arr::wrap($response['special_instructions'] ?? []) as $instruction) {
            $parts[] = trim((string) ($instruction['content'] ?? ''));
        }

        foreach (Arr::wrap($response['individual_instructions'] ?? []) as $instruction) {
            $parts[] = trim((string) ($instruction['study_name'] ?? ''));
            $parts[] = trim((string) ($instruction['content'] ?? ''));
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function collectSourceIndicationsText(array $input): string
    {
        return collect($input['items'] ?? [])
            ->pluck('indications')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->implode("\n");
    }

    private function responseContainsUnit(string $unitStem, string $responseText): bool
    {
        foreach (self::UNIT_VARIANTS[$unitStem] ?? [] as $variant) {
            if (preg_match('/\b'.preg_quote($this->normalizeText($variant), '/').'\b/u', $responseText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function normalizeFeatureList(mixed $featureList): array
    {
        if (is_string($featureList)) {
            $decoded = json_decode($featureList, true);
            $featureList = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($featureList)) {
            return [];
        }

        return collect($featureList)
            ->map(fn ($entry) => is_array($entry) ? trim((string) ($entry['name'] ?? $entry['label'] ?? '')) : trim((string) $entry))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $normalized
        );
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized;
    }
}
