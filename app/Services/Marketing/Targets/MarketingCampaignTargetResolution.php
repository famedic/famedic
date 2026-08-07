<?php

namespace App\Services\Marketing\Targets;

readonly class MarketingCampaignTargetResolution
{
    private function __construct(
        public string $kind,
        public ?MarketingCampaignResolvedTarget $target = null,
    ) {}

    public static function resolved(MarketingCampaignResolvedTarget $target): self
    {
        return new self(kind: 'resolved', target: $target);
    }

    public static function invalid(): self
    {
        return new self(kind: 'invalid');
    }

    public function isResolved(): bool
    {
        return $this->kind === 'resolved' && $this->target !== null;
    }

    public function isInvalid(): bool
    {
        return $this->kind === 'invalid';
    }
}
