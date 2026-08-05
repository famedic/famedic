<?php

namespace App\Services\ClinicalMatching\Catalog;

/**
 * Neutral catalog record. Matching Engine never sees Eloquent models.
 */
final class CatalogItem
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $matchTexts  Texts used by CatalogMatcher (name, short name, aliases, codes)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $shortName,
        public readonly array $aliases,
        public readonly string $code,
        public readonly ?string $price,
        public readonly ?int $priceCents,
        public readonly ?string $deliveryTime,
        public readonly ?string $laboratory,
        public readonly bool $available,
        public readonly array $matchTexts,
        public readonly ?string $brand = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'short_name' => $this->shortName,
            'aliases' => $this->aliases,
            'code' => $this->code,
            'sku' => $this->code,
            'price' => $this->price,
            'price_cents' => $this->priceCents,
            'delivery_time' => $this->deliveryTime,
            'laboratory' => $this->laboratory,
            'available' => $this->available,
            'brand' => $this->brand ?? $this->laboratory,
            'match_texts' => $this->matchTexts,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            type: (string) $data['type'],
            name: (string) $data['name'],
            shortName: $data['short_name'] ?? null,
            aliases: $data['aliases'] ?? [],
            code: (string) ($data['code'] ?? $data['sku'] ?? ''),
            price: $data['price'] ?? null,
            priceCents: $data['price_cents'] ?? null,
            deliveryTime: $data['delivery_time'] ?? null,
            laboratory: $data['laboratory'] ?? null,
            available: (bool) ($data['available'] ?? true),
            matchTexts: $data['match_texts'] ?? [$data['name']],
            brand: $data['brand'] ?? null,
        );
    }
}
