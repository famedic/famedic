<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

final class BenefitFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
