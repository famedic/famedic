<?php

namespace App\Services\Tracking;

class ViewContent extends Base
{
    public static function track(
        string $productId,
        string $value,
        string $source,
        array $customProperties = [],
    ): void {
        $event = new self(
            productId: $productId,
            value: $value,
            source: $source,
            customProperties: $customProperties
        );

        $event->queue();
    }

    public function __construct(
        string $productId,
        string $value,
        string $source,
        array $customProperties = [],
    ) {
        $this->name = 'ViewContent';
        $this->value = $value;
        $this->currency = 'mxn';
        $this->contentType = 'product';
        $this->contentIds = [$productId];
        $this->contents = [[
            'id' => $productId,
            'quantity' => 1,
        ]];

        $this->customProperties = [
            'source' => $source,
            'brand' => $customProperties['brand'] ?? null,
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
