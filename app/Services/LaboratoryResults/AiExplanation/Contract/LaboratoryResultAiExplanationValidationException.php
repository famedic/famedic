<?php

namespace App\Services\LaboratoryResults\AiExplanation\Contract;

use RuntimeException;

final class LaboratoryResultAiExplanationValidationException extends RuntimeException
{
    public static function invalidStructure(string $reason): self
    {
        return new self('AI explanation output invalid: '.$reason);
    }
}
