<?php

namespace App\Actions\Customers;

class GenerateMedicalAttentionIdAction
{
    public function __construct(
        private GenerateUniqueMedicalAttentionIdAction $generateUniqueMedicalAttentionIdAction,
    ) {}

    public function __invoke(): int
    {
        return ($this->generateUniqueMedicalAttentionIdAction)();
    }
}
