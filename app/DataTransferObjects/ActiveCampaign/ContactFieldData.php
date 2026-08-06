<?php

namespace App\DataTransferObjects\ActiveCampaign;

class ContactFieldData
{
    public function __construct(
        public readonly int $fieldValueId,
        public readonly int $fieldId,
        public readonly ?string $title,
        public readonly ?string $perstag,
        public readonly ?string $type,
        public readonly ?string $value,
        public readonly ?string $cdate = null,
        public readonly ?string $udate = null,
    ) {}

    /**
     * @return array{
     *     field_value_id: int,
     *     field_id: int,
     *     title: string|null,
     *     perstag: string|null,
     *     type: string|null,
     *     value: string|null,
     *     cdate: string|null,
     *     udate: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'field_value_id' => $this->fieldValueId,
            'field_id' => $this->fieldId,
            'title' => $this->title,
            'perstag' => $this->perstag,
            'type' => $this->type,
            'value' => $this->value,
            'cdate' => $this->cdate,
            'udate' => $this->udate,
        ];
    }
}
