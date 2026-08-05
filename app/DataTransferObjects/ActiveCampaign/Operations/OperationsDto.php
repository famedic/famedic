<?php

namespace App\DataTransferObjects\ActiveCampaign\Operations;

/**
 * DTO base serializable para consumo UI + futuro AI Operations Assistant.
 */
abstract class OperationsDto
{
    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
