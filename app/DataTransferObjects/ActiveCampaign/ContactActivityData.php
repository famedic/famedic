<?php

namespace App\DataTransferObjects\ActiveCampaign;

class ContactActivityData
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $type,
        public readonly ?string $tstamp,
        public readonly ?string $referenceType,
        public readonly ?string $referenceId,
        public readonly ?string $referenceAction,
        public readonly ?string $referenceModelName,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array{
     *     id: string|null,
     *     type: string|null,
     *     tstamp: string|null,
     *     reference_type: string|null,
     *     reference_id: string|null,
     *     reference_action: string|null,
     *     reference_model_name: string|null,
     *     meta: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'tstamp' => $this->tstamp,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'reference_action' => $this->referenceAction,
            'reference_model_name' => $this->referenceModelName,
            'meta' => $this->meta,
        ];
    }
}
