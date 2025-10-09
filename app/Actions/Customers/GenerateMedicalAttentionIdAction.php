<?php

namespace App\Actions\Customers;

use App\Models\Customer;

class GenerateMedicalAttentionIdAction
{
    public function __invoke(): int
    {
        $code = rand(1000000000, 9999999999);
        while (Customer::where('medical_attention_identifier', $code)->exists()) {
            $code = rand(1000000000, 9999999999);
        }

        return $code;
    }
}
