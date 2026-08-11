<?php

namespace App\Data\Api\V1\Uat;

final readonly class AkubicaUatFixtureResult
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $status,
        public string $action,
        public string $namespace,
        public array $counts,
        public array $details = [],
    ) {
    }

    public function toSanitizedArray(): array
    {
        return [
            'status' => $this->status,
            'action' => $this->action,
            'namespace' => $this->namespace,
            'counts' => $this->counts,
            'details' => $this->details,
        ];
    }
}
