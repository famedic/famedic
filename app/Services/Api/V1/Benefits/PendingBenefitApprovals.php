<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

/** No environment switch, approved text, capability or financial write is implied. */
final class PendingBenefitApprovals implements BenefitApprovals
{
    public function api(): bool
    {
        return false;
    }

    public function consent(): bool
    {
        return false;
    }

    public function financial(): bool
    {
        return false;
    }

    public function textVersion(): ?string
    {
        return null;
    }
}
