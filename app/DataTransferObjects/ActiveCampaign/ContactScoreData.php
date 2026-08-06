<?php

namespace App\DataTransferObjects\ActiveCampaign;

class ContactScoreData
{
    public function __construct(
        public readonly int $scoreValueId,
        public readonly int $scoreId,
        public readonly ?string $name,
        public readonly int $scoreValue,
        public readonly ?string $cdate = null,
        public readonly ?string $mdate = null,
    ) {}

    /**
     * @return array{
     *     score_value_id: int,
     *     score_id: int,
     *     name: string|null,
     *     score_value: int,
     *     cdate: string|null,
     *     mdate: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'score_value_id' => $this->scoreValueId,
            'score_id' => $this->scoreId,
            'name' => $this->name,
            'score_value' => $this->scoreValue,
            'cdate' => $this->cdate,
            'mdate' => $this->mdate,
        ];
    }
}
