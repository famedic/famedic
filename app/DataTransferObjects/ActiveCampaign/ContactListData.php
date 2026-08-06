<?php

namespace App\DataTransferObjects\ActiveCampaign;

class ContactListData
{
    public function __construct(
        public readonly int $contactListId,
        public readonly int $listId,
        public readonly ?string $name,
        public readonly ?string $status,
        public readonly ?string $sdate = null,
        public readonly ?string $udate = null,
    ) {}

    /**
     * @return array{
     *     contact_list_id: int,
     *     list_id: int,
     *     name: string|null,
     *     status: string|null,
     *     sdate: string|null,
     *     udate: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'contact_list_id' => $this->contactListId,
            'list_id' => $this->listId,
            'name' => $this->name,
            'status' => $this->status,
            'sdate' => $this->sdate,
            'udate' => $this->udate,
        ];
    }
}
