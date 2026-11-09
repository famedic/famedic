<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Benefits;

final readonly class BenefitSnapshotDto implements \JsonSerializable
{
    public function __construct(public string $cart_ref, public string $cart_version, public array $benefits, public ?BenefitSelectionDto $application, public BenefitTotalsDto $totals, public string $service_line = 'medical_services', public string $availability = 'preview_only') {}

    public static function from(array $d): self
    {
        $options = array_map(BenefitOptionDto::from(...), $d['benefits']);

        return new self($d['cart_ref'], $d['cart_version'], $options, $d['application'] ? BenefitSelectionDto::from($d['application']) : null, BenefitTotalsDto::from($d['totals']));
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
