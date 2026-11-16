<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Continuity;

final class MedicalContinuityUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
