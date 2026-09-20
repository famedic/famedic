<?php

namespace App\Services\LaboratoryResults\Extraction;

final class LaboratoryResultTextExtractionResult
{
    /**
     * @param  list<array{page: int, text: string, char_count: int}>  $pages
     */
    public function __construct(
        public readonly array $pages,
        public readonly string $fullText,
        public readonly int $pageCount,
        public readonly bool $success,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public function totalCharacters(): int
    {
        return mb_strlen($this->fullText, 'UTF-8');
    }
}
