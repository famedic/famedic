<?php

use App\Models\LaboratoryTest;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * RM mama simple y contrastada + RM Hipófisis (silla turca) simple y contrastada.
     * Swiss se mantiene sin cambios (gda_id 560186, 560181).
     *
     * @return array<int, array<string, mixed>>
     */
    private function updates(): array
    {
        return [
            // RM mama simple y contrastada
            [
                'id' => 4479,
                'gda_id' => '165385',
                'famedic_price_cents' => 291323,
            ],
            [
                'id' => 4480,
                'gda_id' => '445309',
                'famedic_price_cents' => 291323,
            ],
            [
                'id' => 4481,
                'gda_id' => '714678',
                'famedic_price_cents' => 291323,
            ],
            [
                'id' => 4482,
                'gda_id' => '1505836',
                'famedic_price_cents' => 307124,
            ],
            // RM Hipófisis (silla turca) simple y contrastada
            [
                'id' => 4471,
                'gda_id' => '165111',
                'famedic_price_cents' => 297430,
            ],
            [
                'id' => 4472,
                'gda_id' => '445319',
                'famedic_price_cents' => 297430,
            ],
            [
                'id' => 4473,
                'gda_id' => '714685',
                'public_price_cents' => 297430,
            ],
            [
                'id' => 4474,
                'gda_id' => '1501527',
                'famedic_price_cents' => 297430,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rollbacks(): array
    {
        return [
            ['id' => 4479, 'gda_id' => '165016'],
            ['id' => 4480, 'gda_id' => '445038'],
            ['id' => 4481, 'gda_id' => '730105'],
            ['id' => 4482, 'gda_id' => '1501616'],
            ['id' => 4471, 'gda_id' => '165024'],
            ['id' => 4472, 'gda_id' => '445036'],
            ['id' => 4473, 'gda_id' => '730107'],
            ['id' => 4474, 'gda_id' => '1501526'],
        ];
    }

    public function up(): void
    {
        $this->apply($this->updates());
    }

    public function down(): void
    {
        $this->apply($this->rollbacks());
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function apply(array $changes): void
    {
        $scoutQueue = config('scout.queue');
        config(['scout.queue' => false]);

        foreach ($changes as $change) {
            $laboratoryTest = LaboratoryTest::query()->find($change['id']);

            if ($laboratoryTest === null) {
                continue;
            }

            $attributes = collect($change)->except('id')->all();
            $laboratoryTest->update($attributes);
            $laboratoryTest->load('laboratoryTestCategory');
            $laboratoryTest->searchable();
        }

        config(['scout.queue' => $scoutQueue]);
    }
};
