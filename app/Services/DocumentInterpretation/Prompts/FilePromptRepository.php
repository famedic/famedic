<?php

namespace App\Services\DocumentInterpretation\Prompts;

use Illuminate\Support\Facades\File;

/**
 * Read-only prompt store backed by versioned JSON files.
 */
class FilePromptRepository implements PromptRepositoryInterface
{
    public function find(string $promptKey): ?PromptDefinition
    {
        $path = $this->basePath().DIRECTORY_SEPARATOR.$promptKey.'.json';

        if (! File::exists($path)) {
            return null;
        }

        return $this->loadFile($path);
    }

    /**
     * @return list<PromptDefinition>
     */
    public function all(): array
    {
        $files = File::glob($this->basePath().DIRECTORY_SEPARATOR.'*.json') ?: [];

        $definitions = [];
        foreach ($files as $file) {
            $definition = $this->loadFile($file);
            if ($definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    private function basePath(): string
    {
        return (string) config('clinical_interpreter.prompt_path', resource_path('clinical_interpreter/prompts'));
    }

    private function loadFile(string $path): ?PromptDefinition
    {
        $raw = File::get($path);
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        return PromptDefinition::fromArray($data, $path);
    }
}
