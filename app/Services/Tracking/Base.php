<?php

namespace App\Services\Tracking;

use App\Services\Tracking\Tracking;

abstract class Base
{
    public string $name;
    public string $id;
    public ?string $currency = null;
    public ?string $value = null;
    public ?array $contents = null;
    public ?string $contentType = null;
    public ?array $contentIds = null;
    public ?int $numItems = null;
    public ?string $searchString = null;
    public ?array $customProperties = null;
    public bool $sendToBrowser = true;

    protected function queue(): void
    {
        if (!app()->environment('production')) {
            return;
        }

        app(Tracking::class)->queueEvent($this);
    }

    protected function generateEventId(
        array $hashData,
        bool $appendTimestampToIdHash = false
    ): string {
        $hash = md5(collect($hashData)->filter()->implode('|'));
        return $this->name . '-' . $hash . ($appendTimestampToIdHash ? '-' . now()->timestamp : '');
    }

    public function toBrowserPayload(): array
    {
        return  [
            'eventName' => $this->name,
            'eventID'        => $this->id,
            ...$this->currency ? ['currency' => $this->currency] : [],
            ...$this->value ? ['value' => $this->value] : [],
            ...$this->contents ? ['contents' => json_encode($this->contents)] : [],
            ...$this->contentType ? ['content_type' => $this->contentType] : [],
            ...$this->contentIds ? ['content_ids' => $this->contentIds] : [],
            ...$this->numItems ? ['num_items' => $this->numItems] : [],
            ...$this->searchString ? ['search_string' => $this->searchString] : [],
            ...($this->customProperties ?? []),
        ];
    }
}
