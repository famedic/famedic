<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

interface BenefitApprovals
{
    public function api(): bool;

    public function consent(): bool;

    public function financial(): bool;

    public function textVersion(): ?string;
}
