<?php

namespace App\Services\Marketing\Targets;

readonly class MarketingCampaignTargetResolution
{
    /**
     * @param  array<string, mixed>  $props
     */
    private function __construct(
        public string $kind,
        public ?string $url = null,
        public ?string $component = null,
        public array $props = [],
    ) {}

    public static function redirect(string $url): self
    {
        return new self(kind: 'redirect', url: $url);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    public static function inertia(string $component, array $props): self
    {
        return new self(kind: 'inertia', component: $component, props: $props);
    }

    public static function invalid(): self
    {
        return new self(kind: 'invalid');
    }

    public function isRedirect(): bool
    {
        return $this->kind === 'redirect';
    }

    public function isInertia(): bool
    {
        return $this->kind === 'inertia';
    }

    public function isInvalid(): bool
    {
        return $this->kind === 'invalid';
    }
}
