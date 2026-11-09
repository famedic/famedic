<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Benefits;

final readonly class BenefitSelectionDto implements \JsonSerializable
{
    public function __construct(public string $application_ref, public string $status, public int $applied_cents) {}

    public static function from(array $v): self
    {
        return new self($v['application_ref'], $v['status'], $v['applied_cents']);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
