<?php

namespace App\Support\LaboratoryRequirements;

class CartRequirements
{
    /**
     * @param  array<int, array<string, mixed>>  $studies
     * @param  array<int, CartRequirementGroup>  $groups
     * @param  array<int, CartRequirement>  $requirements
     * @param  array<int, string>  $unresolvedReasons
     * @param  array<string, array<string, mixed>>  $brands
     */
    public function __construct(
        public readonly ?string $cartId,
        public readonly array $studies,
        public readonly array $groups,
        public readonly array $requirements,
        public readonly string $confidence,
        public readonly bool $isResolvable,
        public readonly array $unresolvedReasons = [],
        public readonly array $brands = [],
        public readonly array $evidence = [],
    ) {}

    public function toArray(): array
    {
        return [
            'cart_id' => $this->cartId,
            'studies' => $this->studies,
            'groups' => array_map(fn (CartRequirementGroup $group) => $group->toArray(), $this->groups),
            'requirements' => array_map(fn (CartRequirement $requirement) => $requirement->toArray(), $this->requirements),
            'confidence' => $this->confidence,
            'is_resolvable' => $this->isResolvable,
            'unresolved_reasons' => $this->unresolvedReasons,
            'brands' => $this->brands,
            'evidence' => $this->evidence,
        ];
    }
}
