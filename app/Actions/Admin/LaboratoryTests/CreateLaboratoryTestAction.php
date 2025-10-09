<?php

namespace App\Actions\Admin\LaboratoryTests;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;

class CreateLaboratoryTestAction
{
    public function __invoke(
        LaboratoryBrand $brand,
        string $gda_id,
        string $name,
        ?string $description = null,
        ?array $feature_list = null,
        ?string $indications = null,
        ?string $other_name = null,
        ?string $elements = null,
        ?string $common_use = null,
        bool $requires_appointment = false,
        float $public_price = 0.0,
        float $famedic_price = 0.0,
        int $laboratory_test_category_id,
    ): LaboratoryTest {
        return LaboratoryTest::create([
            'brand' => $brand,
            'gda_id' => $gda_id,
            'name' => $name,
            'description' => $description,
            'feature_list' => $feature_list,
            'indications' => $indications,
            'other_name' => $other_name,
            'elements' => $elements,
            'common_use' => $common_use,
            'requires_appointment' => $requires_appointment,
            'public_price_cents' => (int) round($public_price * 100),
            'famedic_price_cents' => (int) round($famedic_price * 100),
            'laboratory_test_category_id' => $laboratory_test_category_id,
        ]);
    }
}
