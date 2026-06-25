<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $scoutQueue = config('scout.queue');
        config(['scout.queue' => false]);

        $category = LaboratoryTestCategory::query()->find(9)
            ?? LaboratoryTestCategory::query()->first();

        if ($category === null) {
            if (app()->environment('testing') || config('database.default') === 'sqlite') {
                config(['scout.queue' => $scoutQueue]);

                return;
            }

            $category = LaboratoryTestCategory::query()->create([
                'name' => 'Resonancia magnética',
            ]);
        }

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
                'laboratory_test_category_id' => $category->id,
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
