<?php

namespace App\Services\DocumentInterpretation;

use App\Services\ClinicalMatching\ClinicalMatchingEngine;
use App\Services\DocumentInterpretation\Prompts\PromptProvider;
use Illuminate\Http\UploadedFile;

/**
 * Document → Interpreter → JSON → Matching Engine.
 * Keeps OpenAI away from catalog decisions.
 */
class ClinicalInterpretationOrchestrator
{
    public function __construct(
        private DocumentInterpreterInterface $interpreter,
        private EngineInterpretationMapper $mapper,
        private ClinicalMatchingEngine $matchingEngine,
        private PromptProvider $promptProvider,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function interpretAndMatch(UploadedFile $file): array
    {
        $document = [
            'contents' => file_get_contents($file->getRealPath()) ?: '',
            'mime' => $file->getMimeType() ?: 'image/jpeg',
            'filename' => $file->getClientOriginalName(),
        ];

        $previewUrl = null; // Preview local en el cliente (blob). No emitir data-URL (PHI).

        $result = $this->interpreter->interpret($document);
        $mapped = $this->mapper->toEngineFormat($result['contract'], [
            'filename' => $document['filename'],
            'mime' => $document['mime'],
            'pages' => 1,
            'preview_url' => $previewUrl,
        ]);

        $payload = $this->matchingEngine->build($mapped);

        // Preserve Vision contract (Engine rebuilds a derived ai_json for its own shape).
        $payload['interpretation']['ai_json'] = $result['contract'];
        $payload['ai_config'] = $result['prompt']->toConfigArray();
        $payload['prompt_catalog'] = $this->promptProvider->catalogForUi();
        $payload['interpretation_metrics'] = $result['metrics'];
        $payload['vision'] = [
            'provider' => 'openai_vision',
            'configured' => filled(config('services.openai.key')),
            'raw_json' => $result['contract'],
        ];
        $payload['meta']['note'] = 'Interpretación vía OpenAI Vision · Matching exclusivo Famedic (catálogo laboratory_tests).';
        $payload['meta']['interpretation_source'] = 'openai_vision';

        return $payload;
    }

    /**
     * Shell payload for initial page load (demo matching + live prompt config).
     *
     * @return array<string, mixed>
     */
    public function matchingShell(): array
    {
        $payload = $this->matchingEngine->build();
        $active = $this->promptProvider->active();

        $payload['ai_config'] = $active->toConfigArray();
        $payload['prompt_catalog'] = $this->promptProvider->catalogForUi();
        $payload['interpretation_metrics'] = null;
        $payload['vision'] = [
            'provider' => 'openai_vision',
            'configured' => filled(config('services.openai.key')),
            'raw_json' => $payload['interpretation']['ai_json'] ?? null,
        ];
        $payload['meta']['note'] = 'Sube una receta para interpretar con Vision. Matching usa catálogo real de laboratorio.';
        $payload['meta']['interpretation_source'] = 'demo_fallback';

        return $payload;
    }
}
