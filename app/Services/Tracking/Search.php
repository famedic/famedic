<?php

namespace App\Services\Tracking;

class Search extends Base
{
    public static function track(
        array $contentIds,
        string $source,
        ?string $searchString = null,
        array $customProperties = [],
    ): void {
        $event = new self(
            contentIds: $contentIds,
            source: $source,
            searchString: $searchString,
            customProperties: $customProperties
        );

        $event->queue();
    }

    public function __construct(
        array $contentIds,
        string $source,
        ?string $searchString = null,
        array $customProperties = [],
    ) {
        $this->name = 'Search';
        $this->contentIds = $contentIds;
        $this->searchString = $searchString;
        $this->contentType = 'product';

        $this->customProperties = [
            'source' => $source,
            'brand'    => $customProperties['brand']    ?? null,
            'category' => $customProperties['category'] ?? null,
            'page'     => $customProperties['page']     ?? null,
        ];

        $this->id = $this->generateEventId(
            hashData: [
                $source,
                $searchString,
                $customProperties['brand']    ?? null,
                $customProperties['category'] ?? null,
                $customProperties['page']     ?? null,
            ],
            appendTimestampToIdHash: true
        );
    }
}
