<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Benefits;

final readonly class BenefitOptionDto implements \JsonSerializable
{
    public function __construct(public string $benefit_ref, public string $benefit_type, public int $estimated_amount_cents, public int $max_applicable_cents, public string $reference_expires_at) {}

    public static function from(array $v): self
    {
        return new self($v['benefit_ref'], $v['benefit_type'], $v['estimated_amount_cents'], $v['max_applicable_cents'], gmdate('Y-m-d\TH:i:s\Z', $v['reference_expires_at']));
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
