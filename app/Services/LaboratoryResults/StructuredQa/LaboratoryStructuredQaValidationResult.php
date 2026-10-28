<?php

namespace App\Services\LaboratoryResults\StructuredQa;

final class LaboratoryStructuredQaValidationResult
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $reasons = [],
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function fail(array $reasons): self
    {
        return new self(false, $reasons);
    }
}
