<?php

namespace App\Traits;

use App\Contracts\LabelledEnum;

trait HasCasesWithLabels
{
    public static function casesWithLabels(): array
    {
        return array_map(function (LabelledEnum $case) {
            return [
                'value' => $case->value,
                'name' => $case->name,
                'label' => $case->label(),
            ];
        }, self::cases());
    }
}
