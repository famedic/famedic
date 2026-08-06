<?php

namespace App\DataTransferObjects\ActiveCampaign;

class ContactTagData
{
    public function __construct(
        public readonly int $contactTagId,
        public readonly int $tagId,
        public readonly ?string $name,
        public readonly ?string $cdate = null,
    ) {}

    /**
     * @return array{contact_tag_id: int, tag_id: int, name: string|null, cdate: string|null}
     */
    public function toArray(): array
    {
        return [
            'contact_tag_id' => $this->contactTagId,
            'tag_id' => $this->tagId,
            'name' => $this->name,
            'cdate' => $this->cdate,
        ];
    }
}
