<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SuggestMarketingCampaignLandingContentAction
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres un asistente interno para crear contenido de landings comerciales de Famedic.
Responde únicamente con JSON válido del schema solicitado.

Reglas clínicas y comerciales:
- No diagnostiques.
- No afirmes que un estudio detecta, previene o cura con certeza.
- No inventes beneficios clínicos, descuentos, precios, disponibilidad ni productos.
- No proceses ni solicites información personal de pacientes.
- No produzcas HTML.
- Usa lenguaje responsable, claro y revisable por un administrador antes de publicar.
PROMPT;

    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input): array
    {
        $context = Str::limit((string) $input['context'], 1800, '');
        $objective = (string) ($input['objective'] ?? 'promocion');
        $tone = (string) ($input['tone'] ?? 'profesional');
        $audience = Str::limit((string) ($input['audience'] ?? ''), 240, '');

        Log::info('Marketing campaign landing AI suggestion requested.', [
            'objective' => $objective,
            'tone' => $tone,
            'has_audience' => $audience !== '',
        ]);

        $result = $this->client->chatCompletion(
            messages: [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $this->userPrompt($context, $objective, $tone, $audience)],
            ],
            model: config('services.openai.model'),
            jsonSchema: $this->schema(),
            schemaName: 'marketing_campaign_landing_content',
            temperature: 0.3,
        );

        return $this->sanitize($result);
    }

    private function userPrompt(string $context, string $objective, string $tone, string $audience): string
    {
        return <<<PROMPT
Contexto escrito por el administrador:
{$context}

Objetivo: {$objective}
Tono: {$tone}
Audiencia opcional: {$audience}

Genera sugerencias breves para una landing de campaña. No inventes productos, precios ni descuentos.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $text = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'eyebrow' => $text,
                'title' => $text,
                'subtitle' => $text,
                'description' => $text,
                'primary_cta_label' => $text,
                'secondary_cta_label' => $text,
                'editorial_title' => $text,
                'editorial_body' => $text,
                'editorial_items' => [
                    'type' => 'array',
                    'maxItems' => 4,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => $text,
                            'description' => $text,
                            'icon_key' => ['type' => 'string', 'enum' => ['shield', 'heart', 'beaker', 'calendar']],
                        ],
                        'required' => ['title', 'description', 'icon_key'],
                    ],
                ],
                'seo_title' => $text,
                'seo_description' => $text,
            ],
            'required' => [
                'eyebrow',
                'title',
                'subtitle',
                'description',
                'primary_cta_label',
                'secondary_cta_label',
                'editorial_title',
                'editorial_body',
                'editorial_items',
                'seo_title',
                'seo_description',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function sanitize(array $result): array
    {
        return [
            'eyebrow' => $this->plain($result['eyebrow'] ?? '', 120),
            'title' => $this->plain($result['title'] ?? '', 180),
            'subtitle' => $this->plain($result['subtitle'] ?? '', 255),
            'description' => $this->plain($result['description'] ?? '', 1200),
            'primary_cta_label' => $this->plain($result['primary_cta_label'] ?? '', 80),
            'secondary_cta_label' => $this->plain($result['secondary_cta_label'] ?? '', 80),
            'editorial_title' => $this->plain($result['editorial_title'] ?? '', 160),
            'editorial_body' => $this->plain($result['editorial_body'] ?? '', 1200),
            'editorial_items' => collect(Arr::wrap($result['editorial_items'] ?? []))
                ->take(4)
                ->map(fn ($item) => [
                    'title' => $this->plain($item['title'] ?? '', 120),
                    'description' => $this->plain($item['description'] ?? '', 300),
                    'icon_key' => in_array($item['icon_key'] ?? '', ['shield', 'heart', 'beaker', 'calendar'], true)
                        ? $item['icon_key']
                        : 'shield',
                ])
                ->values()
                ->all(),
            'seo_title' => $this->plain($result['seo_title'] ?? '', 180),
            'seo_description' => $this->plain($result['seo_description'] ?? '', 255),
        ];
    }

    private function plain(mixed $value, int $limit): string
    {
        return Str::limit(strip_tags((string) $value), $limit, '');
    }
}
