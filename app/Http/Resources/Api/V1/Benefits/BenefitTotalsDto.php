<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Benefits;

final readonly class BenefitTotalsDto implements \JsonSerializable
{
    public function __construct(public int $subtotal_cents, public int $discount_cents, public int $credit_applied_cents, public int $total_cents, public string $currency = 'MXN') {}

    public static function from(array $d): self
    {
        return new self($d['subtotal_cents'], $d['discount_cents'], $d['credit_applied_cents'], $d['total_cents']);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
