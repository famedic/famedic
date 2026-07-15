<?php

namespace App\Services\Zoho;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Búsqueda asistida de estudios de laboratorio para Zoho SalesIQ (Fase 5).
 * OpenAI solo puede elegir IDs de candidatos reales del catálogo Famedic.
 */
class SalesIqStudySearchService
{
    private const MAX_RESULTS = 5;

    private const MAX_BOT_MESSAGE_RESULTS = 3;

    private const MAX_STORES_RESPONSE = 5;

    private const MAX_STORES_BOT = 3;

    private const MAX_CANDIDATES = 20;

    private const OPENAI_TIMEOUT_SECONDS = 20;

    private const PRICE_UNAVAILABLE = 'Precio no disponible para esta opción';

    /** @var list<string> */
    private const BROAD_QUERY_TERMS = [
        'orina',
        'sangre',
        'perfil',
        'examen',
    ];

    /** @var list<string> */
    private const UNKNOWN_BRAND_VALUES = [
        'unknown',
        'no_se',
        'no-se',
        'no se',
        'no estoy seguro',
        'noestoyseguro',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres un asistente de búsqueda para el catálogo de estudios de laboratorio de Famedic.

Tu tarea es relacionar la búsqueda del usuario con estudios existentes del catálogo.

Reglas estrictas:
- No inventes estudios.
- No inventes precios.
- No inventes marcas.
- No inventes disponibilidad.
- No inventes indicaciones médicas.
- No des diagnóstico médico.
- No recomiendes estudios como indicación médica definitiva.
- Solo puedes elegir IDs incluidos en la lista de candidatos.
- Si ningún candidato es suficientemente seguro, responde con lista vacía.
- Devuelve únicamente JSON válido.

Formato de respuesta:
{
  "selected_ids": [1, 2],
  "confidence": "high|medium|low",
  "reason": "explicación breve"
}
PROMPT;

    /** @var list<string> */
    private const STOP_WORDS = [
        'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas',
        'y', 'o', 'u', 'en', 'con', 'por', 'para', 'a', 'al', 'lo', 'su', 'se',
        'que', 'es', 'mi', 'me', 'te', 'tu',
    ];

    public function __construct(
        private SalesIqWebhookService $webhookService,
    ) {}

    public static function isUnknownBrand(?string $brand): bool
    {
        if (! is_string($brand) || trim($brand) === '') {
            return true;
        }

        $raw = mb_strtolower(trim($brand));
        if (in_array($raw, self::UNKNOWN_BRAND_VALUES, true)) {
            return true;
        }

        $normalized = preg_replace('/\s+/u', ' ', $raw) ?? $raw;

        return in_array($normalized, self::UNKNOWN_BRAND_VALUES, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $result = $this->search($input);
        $this->recordEvent($input, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function search(array $input): array
    {
        $rawQuery = trim((string) ($input['query'] ?? ''));
        $normalized = $this->normalizeQuery($rawQuery);
        $brand = $this->resolveBrandInput($input['brand'] ?? null);
        $state = $this->normalizeState($input['state'] ?? null);

        if ($normalized === '') {
            return $this->noResultsResponse(
                'No encontré una coincidencia segura. Te recomiendo hablar con Atención a Clientes para evitar sugerirte un estudio incorrecto.',
                $brand,
                $state,
            );
        }

        $directMatches = $this->findDirectMatches($rawQuery, $normalized, $brand);

        if ($directMatches->isNotEmpty()) {
            return $this->buildSuccessResponse(
                $directMatches,
                $input,
                $brand,
                $state,
                'catalog',
                $directMatches->count(),
            );
        }

        $candidates = $this->findBroadCandidates($normalized, $brand);

        if ($candidates->isEmpty()) {
            return $this->noResultsResponse(
                'No encontré una coincidencia segura. Te recomiendo hablar con Atención a Clientes para evitar sugerirte un estudio incorrecto.',
                $brand,
                $state,
            );
        }

        try {
            $selection = $this->selectWithOpenAi($rawQuery, $candidates);
        } catch (Throwable $e) {
            Log::warning('Zoho SalesIQ study search OpenAI failed', [
                'message' => Str::limit($e->getMessage(), 200),
            ]);

            return $this->noResultsResponse(
                'No pude confirmar una coincidencia segura en este momento. Te recomiendo hablar con Atención a Clientes.',
                $brand,
                $state,
            );
        }

        $allowedIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selectedIds = array_values(array_filter(
            $selection['selected_ids'],
            fn (int $id) => in_array($id, $allowedIds, true)
        ));

        $filtered = $candidates
            ->filter(fn (LaboratoryTest $test) => in_array((int) $test->id, $selectedIds, true))
            ->sortBy(fn (LaboratoryTest $test) => array_search((int) $test->id, $selectedIds, true))
            ->values();

        if ($filtered->isEmpty()) {
            return $this->noResultsResponse(
                'No encontré una coincidencia segura. Te recomiendo hablar con Atención a Clientes para evitar sugerirte un estudio incorrecto.',
                $brand,
                $state,
                $selection['reason'] ?? null,
            );
        }

        return $this->buildSuccessResponse(
            $filtered,
            $input,
            $brand,
            $state,
            'openai_assisted',
            $filtered->count(),
            $selection['reason'] ?? null,
        );
    }

    /**
     * @param  Collection<int, LaboratoryTest>  $tests
     * @return array<string, mixed>
     */
    private function buildSuccessResponse(
        Collection $tests,
        array $input,
        ?LaboratoryBrand $brandFilter,
        ?string $state,
        string $source,
        int $totalMatches,
        ?string $reason = null,
    ): array {
        $normalized = $this->normalizeQuery((string) ($input['query'] ?? ''));
        $tooBroad = $this->isBroadQuery($normalized) && $totalMatches > self::MAX_BOT_MESSAGE_RESULTS;

        $limit = $tooBroad ? self::MAX_BOT_MESSAGE_RESULTS : self::MAX_RESULTS;
        $selectedTests = $tests->take($limit)->values();
        $results = $selectedTests
            ->map(fn (LaboratoryTest $test) => $this->formatResult($test))
            ->all();

        $resultBrands = collect($results)->pluck('brand')->unique()->filter()->values()->all();
        $stores = $this->findStores($brandFilter, $state, $resultBrands);

        $response = [
            'ok' => true,
            'source' => $source,
            'message' => $tooBroad
                ? 'Encontré varias opciones relacionadas con tu búsqueda.'
                : 'Encontré algunos estudios que podrían ayudarte.',
            'results' => $results,
            'stores' => $stores,
            'handoff_recommended' => false,
            'bot_message' => $this->buildBotMessage($results, $state, $stores, $tooBroad),
        ];

        if ($reason !== null && trim($reason) !== '') {
            $response['reason'] = Str::limit(trim($reason), 500, '…');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function noResultsResponse(
        string $message,
        ?LaboratoryBrand $brandFilter,
        ?string $state,
        ?string $reason = null,
    ): array {
        $stores = $this->findStores($brandFilter, $state, []);

        $response = [
            'ok' => true,
            'source' => 'no_results',
            'message' => $message,
            'results' => [],
            'stores' => $stores,
            'handoff_recommended' => true,
            'bot_message' => $message,
        ];

        if ($reason !== null && trim($reason) !== '') {
            $response['reason'] = Str::limit(trim($reason), 500, '…');
        }

        return $response;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $stores
     */
    private function buildBotMessage(array $results, ?string $state, array $stores, bool $tooBroad): string
    {
        if ($results === []) {
            return 'No encontré una coincidencia segura. Te recomiendo hablar con Atención a Clientes para evitar sugerirte un estudio incorrecto.';
        }

        if ($tooBroad) {
            $lines = [
                'Encontré varias opciones relacionadas con tu búsqueda. Para ayudarte mejor, puedes escribir un nombre más específico o confirmar con Atención a Clientes.',
                '',
                'Estas son algunas opciones:',
            ];
        } else {
            $lines = ['Encontré estas opciones:'];
        }

        $index = 1;
        foreach (array_slice($results, 0, self::MAX_BOT_MESSAGE_RESULTS) as $result) {
            $name = is_string($result['name'] ?? null) ? trim($result['name']) : '';
            if ($name === '') {
                continue;
            }

            $lines[] = "{$index}. {$name}";
            $lines[] = '   Código: '.($result['search_code'] ?? $name);
            $lines[] = '   Precio Famedic: '.($result['price_formatted'] ?? self::PRICE_UNAVAILABLE);
            $lines[] = '   Laboratorio: '.($result['brand_label'] ?? $result['brand'] ?? '');

            if ($state !== null && $state !== '') {
                $lines[] = '   Estado: '.$state;
            }

            $lines[] = '';
            $index++;
        }

        $lines[] = 'Puedes copiar el código o nombre del estudio y buscarlo directamente en Famedic.';
        $lines[] = '';

        if ($state !== null && $state !== '') {
            if ($stores !== []) {
                $lines[] = "Sucursales disponibles en {$state}:";
                $storeIndex = 1;
                foreach (array_slice($stores, 0, self::MAX_STORES_BOT) as $store) {
                    $storeName = is_string($store['name'] ?? null) ? trim($store['name']) : '';
                    $address = is_string($store['address'] ?? null) ? trim($store['address']) : '';
                    if ($storeName === '') {
                        continue;
                    }
                    $lines[] = $address !== ''
                        ? "{$storeIndex}. {$storeName} — {$address}"
                        : "{$storeIndex}. {$storeName}";
                    $storeIndex++;
                }
            } else {
                $lines[] = 'No encontré sucursales disponibles para ese estado/laboratorio en este momento.';
            }
        } else {
            $lines[] = 'Si no estás seguro, puedo canalizarte con Atención a Clientes.';
        }

        return implode("\n", $lines);
    }

    public function normalizeQuery(string $query): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim(mb_strtolower($query))) ?? trim(mb_strtolower($query));

        if ($collapsed === '') {
            return '';
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $collapsed);
        if (is_string($ascii) && trim($ascii) !== '') {
            $collapsed = mb_strtolower(trim($ascii));
        }

        return preg_replace('/\s+/u', ' ', $collapsed) ?? $collapsed;
    }

    private function normalizeState(mixed $state): ?string
    {
        if (! is_string($state)) {
            return null;
        }

        $trimmed = trim($state);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function resolveBrandInput(mixed $brand): ?LaboratoryBrand
    {
        if (! is_string($brand) || self::isUnknownBrand($brand)) {
            return null;
        }

        return LaboratoryBrand::tryFrom(mb_strtolower(trim($brand)));
    }

    private function isBroadQuery(string $normalized): bool
    {
        if (in_array($normalized, self::BROAD_QUERY_TERMS, true)) {
            return true;
        }

        $tokens = $this->tokens($normalized);

        return count($tokens) === 1 && in_array($tokens[0], self::BROAD_QUERY_TERMS, true);
    }

    /**
     * @return Collection<int, LaboratoryTest>
     */
    private function findDirectMatches(string $rawQuery, string $normalized, ?LaboratoryBrand $brand): Collection
    {
        $phraseTerms = $this->searchTerms($rawQuery, $normalized);
        $tokenTerms = $this->tokens($normalized);
        $termsForSql = array_values(array_unique(array_merge($phraseTerms, $tokenTerms)));

        if ($termsForSql === []) {
            return collect();
        }

        $query = $this->baseCatalogQuery($brand);

        $query->where(function (Builder $builder) use ($termsForSql) {
            foreach ($termsForSql as $term) {
                $like = '%'.$this->escapeLike($term).'%';
                $builder->orWhere(function (Builder $inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('other_name', 'like', $like)
                        ->orWhere('elements', 'like', $like)
                        ->orWhere('common_use', 'like', $like)
                        ->orWhere('gda_id', 'like', $like)
                        ->orWhereHas('laboratoryTestCategory', function (Builder $category) use ($like) {
                            $category->where('name', 'like', $like);
                        });
                });
            }
        });

        /** @var Collection<int, LaboratoryTest> $matches */
        $matches = $query
            ->orderBy('name')
            ->limit(40)
            ->get();

        if ($matches->isEmpty()) {
            return collect();
        }

        $clear = $matches->filter(function (LaboratoryTest $test) use ($normalized, $phraseTerms, $tokenTerms) {
            $name = $this->normalizeQuery((string) $test->name);
            $other = $this->normalizeQuery((string) ($test->other_name ?? ''));
            $gda = $this->normalizeQuery((string) ($test->gda_id ?? ''));
            $haystacks = array_filter([$name, $other, $gda], fn ($value) => $value !== '');

            foreach ($phraseTerms as $term) {
                foreach ($haystacks as $haystack) {
                    if (str_contains($haystack, $term)) {
                        return true;
                    }
                }
            }

            if ($tokenTerms === []) {
                return false;
            }

            if (count($tokenTerms) === 1) {
                $token = $tokenTerms[0];
                foreach ($haystacks as $haystack) {
                    if (str_contains($haystack, $token)) {
                        return true;
                    }
                }

                return false;
            }

            $combined = trim($name.' '.$other.' '.$gda);
            if ($combined === '') {
                return false;
            }

            if (str_contains($combined, $normalized)) {
                return true;
            }

            foreach ($tokenTerms as $token) {
                if (! str_contains($combined, $token)) {
                    return false;
                }
            }

            return true;
        });

        return $clear->values();
    }

    /**
     * @return Collection<int, LaboratoryTest>
     */
    private function findBroadCandidates(string $normalized, ?LaboratoryBrand $brand): Collection
    {
        $tokens = $this->tokens($normalized);

        if ($tokens === []) {
            $tokens = [$normalized];
        }

        $query = $this->baseCatalogQuery($brand);

        $query->where(function (Builder $builder) use ($tokens) {
            foreach ($tokens as $token) {
                $like = '%'.$this->escapeLike($token).'%';
                $builder->orWhere(function (Builder $inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('other_name', 'like', $like)
                        ->orWhere('elements', 'like', $like)
                        ->orWhere('common_use', 'like', $like)
                        ->orWhere('gda_id', 'like', $like)
                        ->orWhereHas('laboratoryTestCategory', function (Builder $category) use ($like) {
                            $category->where('name', 'like', $like);
                        });
                });
            }
        });

        /** @var Collection<int, LaboratoryTest> $candidates */
        $candidates = $query
            ->orderBy('name')
            ->limit(80)
            ->get();

        return $candidates
            ->map(function (LaboratoryTest $test) use ($tokens) {
                return ['test' => $test, 'score' => $this->scoreCandidate($test, $tokens)];
            })
            ->sortByDesc('score')
            ->take(self::MAX_CANDIDATES)
            ->pluck('test')
            ->values();
    }

    /**
     * @param  Collection<int, LaboratoryTest>  $candidates
     * @return array{selected_ids: list<int>, reason: string|null}
     */
    private function selectWithOpenAi(string $query, Collection $candidates): array
    {
        $apiKey = config('services.openai.key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $payloadCandidates = $candidates
            ->take(self::MAX_CANDIDATES)
            ->map(fn (LaboratoryTest $test) => [
                'id' => (int) $test->id,
                'name' => (string) $test->name,
                'other_name' => $test->other_name,
                'elements' => $this->truncateField($test->elements),
                'common_use' => $this->truncateField($test->common_use),
                'category' => $test->laboratoryTestCategory?->name,
                'brand' => $test->brand instanceof LaboratoryBrand ? $test->brand->value : (string) $test->brand,
                'gda_id' => $test->gda_id,
            ])
            ->values()
            ->all();

        $candidatesJson = json_encode($payloadCandidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($candidatesJson)) {
            throw new \RuntimeException('Unable to encode OpenAI candidates.');
        }

        $userPrompt = "Búsqueda del usuario:\n\"{$query}\"\n\nCandidatos reales:\n{$candidatesJson}";

        $timeout = min(
            self::OPENAI_TIMEOUT_SECONDS,
            max(5, (int) config('services.openai.timeout', 60))
        );

        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI HTTP '.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new \RuntimeException('OpenAI returned empty content.');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('OpenAI returned invalid JSON.');
        }

        $selected = [];
        foreach ($decoded['selected_ids'] ?? [] as $id) {
            if (is_int($id) && $id > 0) {
                $selected[] = $id;
            } elseif (is_string($id) && ctype_digit($id) && (int) $id > 0) {
                $selected[] = (int) $id;
            }
        }

        $reason = null;
        if (isset($decoded['reason']) && is_string($decoded['reason'])) {
            $reason = Str::limit(trim($decoded['reason']), 500, '…');
        }

        return [
            'selected_ids' => array_values(array_unique($selected)),
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatResult(LaboratoryTest $test): array
    {
        $brandEnum = $test->brand instanceof LaboratoryBrand
            ? $test->brand
            : LaboratoryBrand::tryFrom((string) $test->brand);

        $brandValue = $brandEnum?->value ?? (string) $test->brand;
        $price = $this->formatPriceFields($test);

        return [
            'id' => (int) $test->id,
            'name' => (string) $test->name,
            'brand' => $brandValue,
            'brand_label' => $brandEnum?->label() ?? $brandValue,
            'category' => $test->laboratoryTestCategory?->name,
            'gda_id' => $test->gda_id,
            'search_code' => $this->resolveSearchCode($test),
            'price_cents' => $price['price_cents'],
            'price_formatted' => $price['price_formatted'],
            'url' => $this->buildResultUrl($brandValue, (string) $test->name),
        ];
    }

    /**
     * @return array{price_cents: int|null, price_formatted: string}
     */
    private function formatPriceFields(LaboratoryTest $test): array
    {
        $cents = $test->famedic_price_cents;

        if (is_numeric($cents) && (int) $cents > 0) {
            return [
                'price_cents' => (int) $cents,
                'price_formatted' => formattedCentsPrice((int) $cents),
            ];
        }

        return [
            'price_cents' => null,
            'price_formatted' => self::PRICE_UNAVAILABLE,
        ];
    }

    private function resolveSearchCode(LaboratoryTest $test): string
    {
        $gdaId = trim((string) ($test->gda_id ?? ''));
        if ($gdaId !== '') {
            return $gdaId;
        }

        $otherName = trim((string) ($test->other_name ?? ''));
        if ($otherName !== '' && $this->isUsefulAbbreviation($otherName, (string) $test->name)) {
            return $otherName;
        }

        return (string) $test->name;
    }

    private function isUsefulAbbreviation(string $otherName, string $name): bool
    {
        if (mb_strtolower($otherName) === mb_strtolower($name)) {
            return false;
        }

        if (mb_strlen($otherName) <= 15) {
            return true;
        }

        return mb_strlen($otherName) <= 25 && preg_match('/^[A-Z0-9\s\-]+$/u', $otherName) === 1;
    }

    /**
     * @param  list<string>  $resultBrandValues
     * @return list<array<string, mixed>>
     */
    private function findStores(?LaboratoryBrand $brand, ?string $state, array $resultBrandValues): array
    {
        if ($state === null || $state === '') {
            return [];
        }

        $query = LaboratoryStore::query()->orderBy('name');

        if ($brand) {
            $query->ofBrand($brand);
        } elseif ($resultBrandValues !== []) {
            $query->whereIn('brand', $resultBrandValues);
        }

        $escapedState = $this->escapeLike($state);
        $query->where(function (Builder $builder) use ($state, $escapedState) {
            $builder->where('state', $state)
                ->orWhere('state', 'like', '%'.$escapedState.'%');
        });

        return $query
            ->limit(self::MAX_STORES_RESPONSE)
            ->get()
            ->map(fn (LaboratoryStore $store) => $this->formatStore($store))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatStore(LaboratoryStore $store): array
    {
        $brandEnum = $store->brand instanceof LaboratoryBrand
            ? $store->brand
            : LaboratoryBrand::tryFrom((string) $store->brand);

        return [
            'id' => (int) $store->id,
            'name' => (string) $store->name,
            'brand' => $brandEnum?->value ?? (string) $store->brand,
            'brand_label' => $brandEnum?->label(),
            'state' => (string) $store->state,
            'address' => (string) $store->address,
            'google_maps_url' => (string) $store->google_maps_url,
        ];
    }

    private function buildResultUrl(string $brand, string $name): string
    {
        try {
            return route('laboratory-tests', [
                'laboratory_brand' => $brand,
                'query' => $name,
            ], absolute: true);
        } catch (Throwable) {
            return url('/laboratory/'.$brand.'/laboratory-tests');
        }
    }

    private function baseCatalogQuery(?LaboratoryBrand $brand): Builder
    {
        $query = LaboratoryTest::query()
            ->with('laboratoryTestCategory');

        if ($brand) {
            $query->ofBrand($brand);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function searchTerms(string $rawQuery, string $normalized): array
    {
        $collapsedRaw = preg_replace('/\s+/u', ' ', trim(mb_strtolower($rawQuery))) ?? trim(mb_strtolower($rawQuery));

        return array_values(array_unique(array_filter([
            $collapsedRaw,
            $normalized,
        ], fn ($term) => is_string($term) && $term !== '')));
    }

    /**
     * @return list<string>
     */
    private function tokens(string $normalized): array
    {
        $parts = preg_split('/[^a-z0-9]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 2) {
                continue;
            }
            if (in_array($part, self::STOP_WORDS, true)) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function scoreCandidate(LaboratoryTest $test, array $tokens): int
    {
        $haystack = $this->normalizeQuery(implode(' ', array_filter([
            (string) $test->name,
            (string) ($test->other_name ?? ''),
            (string) ($test->elements ?? ''),
            (string) ($test->common_use ?? ''),
            (string) ($test->gda_id ?? ''),
            (string) ($test->laboratoryTestCategory?->name ?? ''),
        ])));

        $score = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $score += str_contains($this->normalizeQuery((string) $test->name), $token) ? 5 : 2;
                if (str_contains($this->normalizeQuery((string) ($test->other_name ?? '')), $token)) {
                    $score += 3;
                }
            }
        }

        return $score;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function truncateField(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), 280, '…');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     */
    private function recordEvent(array $input, array $result): void
    {
        $resultIds = [];
        foreach ($result['results'] ?? [] as $item) {
            if (is_array($item) && isset($item['id']) && is_numeric($item['id'])) {
                $resultIds[] = (int) $item['id'];
            }
        }

        $brandInput = $input['brand'] ?? null;
        $brandForEvent = is_string($brandInput) && trim($brandInput) !== ''
            ? trim($brandInput)
            : 'unknown';

        $payload = [
            'event_type' => 'study_search',
            'visitor_id' => $input['visitor_id'] ?? null,
            'conversation_id' => $input['conversation_id'] ?? null,
            'intent' => 'help_study_search',
            'last_event' => 'search_no_results',
            'page' => $input['page'] ?? null,
            'environment' => $input['environment'] ?? null,
            'brand' => $brandForEvent,
            'state' => $input['state'] ?? null,
            'query' => isset($input['query']) ? Str::limit((string) $input['query'], 120, '') : null,
            'source' => $result['source'] ?? 'no_results',
            'result_count' => count($resultIds),
            'result_ids' => $resultIds,
            'store_count' => count($result['stores'] ?? []),
            'handoff_recommended' => (bool) ($result['handoff_recommended'] ?? false),
        ];

        if (isset($result['reason']) && is_string($result['reason']) && trim($result['reason']) !== '') {
            $payload['reason'] = Str::limit(trim($result['reason']), 500, '…');
        }

        $this->webhookService->record('study_search', $payload);
    }
}
