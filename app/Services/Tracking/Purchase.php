<?php

namespace App\Services\Tracking;

class Purchase extends Base
{
    public static function track(
        string $purchaseId,
        array $contents,
        string $value,
        string $source,
        array $customProperties = [],
    ): void {
        $event = new self(
            purchaseId: $purchaseId,
            contents: $contents,
            value: $value,
            source: $source,
            customProperties: $customProperties
        );

        $event->queue();
    }

    public function __construct(
        string $purchaseId,
        array $contents,
        string $value,
        string $source,
        array $customProperties = [],
    ) {
        $this->name = 'Purchase';
        $this->value = $value;
        $this->currency = 'mxn';
        $this->contentType = 'product';
        $this->contentIds = collect($contents)->pluck('id')->all();
        $this->contents = $contents;
        $this->numItems = collect($contents)->map(fn($item) => $item['quantity'] ?? 1)->sum();

        $this->customProperties = [
            'source' => $source,
            'brand'  => $customProperties['brand'] ?? null,
        ];

        $this->id = $this->generateEventId(
            hashData: [
                $source,
                $purchaseId
            ]
        );
    }
}
