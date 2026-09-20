<?php

namespace App\Services\LaboratoryResults\StructuredQa;

final class LaboratoryStructuredResultPublicationGateResult
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $reasonsHuman
     * @param  array<string, mixed>  $preview
     */
    public function __construct(
        public readonly bool $canPublish,
        public readonly array $reasonCodes,
        public readonly array $reasonsHuman,
        public readonly array $preview = [],
    ) {}
}
