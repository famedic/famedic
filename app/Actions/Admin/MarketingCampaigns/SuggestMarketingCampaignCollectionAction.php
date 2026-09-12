<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SuggestMarketingCampaignCollectionAction
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres un asistente interno para sugerir colecciones de estudios de laboratorio Famedic.
Solo puedes seleccionar IDs presentes en la lista de candidatos. No inventes estudios, precios, disponibilidad ni beneficios clínicos.
Responde únicamente con JSON válido del schema solicitado.
PROMPT;

    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input): array
    {
        $brand = LaboratoryBrand::from($input['brand']);
        $candidateIds = collect($input['candidate_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(40)
            ->values();

        $candidates = LaboratoryTest::query()
            ->with('laboratoryTestCategory:id,name')
            ->whereIn('id', $candidateIds)
            ->where('brand', $brand)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'other_name', 'brand', 'public_price_cents', 'famedic_price_cents', 'laboratory_test_category_id']);

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'Envía estudios candidatos reales de la marca seleccionada.',
            ]);
        }

        Log::info('Marketing campaign collection AI suggestion requested.', [
            'brand' => $brand->value,
            'candidate_count' => $candidates->count(),
            'desired_count' => $input['desired_count'] ?? null,
            'objective' => $input['objective'] ?? null,
        ]);

        $result = $this->client->chatCompletion(
            messages: [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $this->userPrompt($input, $candidates->map(fn ($test) => [
                    'id' => $test->id,
                    'name' => $test->name,
                    'category' => $test->laboratoryTestCategory?->name,
                    'brand' => $brand->value,
                    'public_price_cents' => (int) $test->public_price_cents,
                ])->values()->all())],
            ],
            model: config('services.openai.model'),
            jsonSchema: $this->schema(),
            schemaName: 'marketing_campaign_collection_suggestion',
            temperature: 0.2,
        );

        $allowed = $candidates->keyBy('id');
        $items = collect($result['items'] ?? [])
            ->map(fn ($item) => [
                'laboratory_test_id' => (int) ($item['laboratory_test_id'] ?? 0),
                'reason' => $this->plain($item['reason'] ?? '', 240),
            ])
            ->filter(fn ($item) => $allowed->has($item['laboratory_test_id']))
            ->unique('laboratory_test_id')
            ->take((int) ($input['desired_count'] ?? 6))
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'La IA no devolvió IDs válidos dentro de los candidatos.',
            ]);
        }

        return [
            'collection_name' => $this->plain($result['collection_name'] ?? '', 120),
            'public_title' => $this->plain($result['public_title'] ?? '', 160),
            'public_description' => $this->plain($result['public_description'] ?? '', 600),
            'reasoning_summary' => $this->plain($result['reasoning_summary'] ?? '', 600),
            'items' => $items->map(function ($item) use ($allowed) {
                $test = $allowed->get($item['laboratory_test_id']);

                return [
                    ...$item,
                    'study' => [
                        'id' => $test->id,
                        'name' => $test->name,
                        'other_name' => $test->other_name,
                        'brand' => $test->brand?->value ?? $test->brand,
                        'category_name' => $test->laboratoryTestCategory?->name,
                        'public_price_cents' => (int) $test->public_price_cents,
                        'famedic_price_cents' => (int) $test->famedic_price_cents,
                    ],
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $candidates
     */
    private function userPrompt(array $input, array $candidates): string
    {
        return 'Solicitud: '.Str::limit((string) $input['context'], 1200, '')
            ."\nMarca: ".$input['brand']
            ."\nCantidad deseada: ".($input['desired_count'] ?? 6)
            ."\nObjetivo: ".($input['objective'] ?? 'preventiva')
            ."\nCandidatos permitidos:\n".json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'collection_name' => ['type' => 'string'],
                'public_title' => ['type' => 'string'],
                'public_description' => ['type' => 'string'],
                'reasoning_summary' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'laboratory_test_id' => ['type' => 'integer'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['laboratory_test_id', 'reason'],
                    ],
                ],
            ],
            'required' => ['collection_name', 'public_title', 'public_description', 'reasoning_summary', 'items'],
        ];
    }

    private function plain(mixed $value, int $limit): string
    {
        return Str::limit(strip_tags((string) $value), $limit, '');
    }
}
