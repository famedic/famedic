<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Benefits;

final readonly class BenefitMutationDto implements \JsonSerializable
{
    public function __construct(public string $cart_ref, public string $cart_version, public string $application_ref, public string $status, public BenefitTotalsDto $totals, public string $service_line = 'medical_services', public string $availability = 'preview_only') {}

    public static function from(array $d): self
    {
        return new self($d['cart_ref'], $d['cart_version'], $d['application_ref'], $d['status'], BenefitTotalsDto::from($d['totals']));
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
