<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $scoutQueue = config('scout.queue');
        config(['scout.queue' => false]);

        $laboratoryTest = LaboratoryTest::updateOrCreate(
            ['gda_id' => '445052'],
            [
                'brand' => LaboratoryBrand::AZTECA,
                'name' => 'RMN DE PIE SIMPLE',
                'indications' => '-Realizar previa cita.',
                'other_name' => 'Resonancia magnética de pie simple',
                'elements' => 'Huesos, articulaciones, tejidos blandos del pie',
                'common_use' => 'Evaluación básica de dolor o trauma en el pie',
                'requires_appointment' => true,
                'public_price_cents' => 250900,
                'famedic_price_cents' => 223522,
                'laboratory_test_category_id' => 9,
            ]
        );

        $laboratoryTest->load('laboratoryTestCategory');
        $laboratoryTest->searchable();

        config(['scout.queue' => $scoutQueue]);
    }

    public function down(): void
    {
        LaboratoryTest::query()
            ->where('gda_id', '445052')
            ->forceDelete();
    }
};
