<?php

namespace App\Services\Tracking;

class InitiateCheckout extends Base
{
    public static function track(
        array $contents,
        string $value,
        string $source,
        array $customProperties = [],
    ): void {
        $event = new self(
            contents: $contents,
            value: $value,
            source: $source,
            customProperties: $customProperties
        );

        $event->queue();
    }

    public function __construct(
        array $contents,
        string $value,
        string $source,
        array $customProperties = [],
    ) {
        $this->name = 'InitiateCheckout';
        $this->value = $value;
        $this->currency = 'mxn';
        $this->contentIds = collect($contents)->pluck('id')->all();
        $this->contents = $contents;

        $this->customProperties = [
            'source' => $source,
            'brand'  => $customProperties['brand'] ?? null,
        ];

        $this->id = $this->generateEventId(
            hashData: [
                $source,
                $customProperties['brand'] ?? null,
            ],
            appendTimestampToIdHash: true
        );
    }
}
