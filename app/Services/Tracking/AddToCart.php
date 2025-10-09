<?php

namespace App\Services\Tracking;

class AddToCart extends Base
{
    public static function track(
        string $productId,
        string $value,
        string $source,
        int $quantity = 1,
        array $customProperties = [],
    ): void {
        $event = new self(
            productId: $productId,
            value: $value,
            source: $source,
            quantity: $quantity,
            customProperties: $customProperties
        );

        $event->queue();
    }

    public function __construct(
        string $productId,
        string $value,
        string $source,
        int $quantity = 1,
        array $customProperties = [],
    ) {
        $this->name = 'AddToCart';
        $this->value = $value;
        $this->currency = 'mxn';
        $this->contentType = 'product';
        $this->contentIds = [$productId];
        $this->contents = [[
            'id'       => $productId,
            'quantity' => $quantity,
        ]];

        $this->customProperties = [
            'source' => $source,
            'brand'  => $customProperties['brand'] ?? null,
        ];

        $this->id = $this->generateEventId(
            hashData: [
                $source,
                $productId,
                $customProperties['brand'] ?? null,
            ],
            appendTimestampToIdHash: true
        );
    }
}
