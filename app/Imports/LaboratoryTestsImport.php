<?php

namespace App\Imports;

use App\Models\LaboratoryTestCategory;
use App\Models\LaboratoryTest;
use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;

class LaboratoryTestsImport implements OnEachRow, WithEvents
{
    use RegistersEventListeners;

    public static function beforeImport(BeforeImport $event)
    {
        LaboratoryTest::disableSearchSyncing();
    }

    public static function afterImport(AfterImport $event)
    {
        LaboratoryTest::enableSearchSyncing();
    }

    public function onRow(Row $row)
    {
        $row = $row->toArray();

        $laboratoryTestCategory = LaboratoryTestCategory::firstOrCreate([
            'name' => $row[6],
        ]);

        $laboratoryTestCategory->laboratoryTests()->create([
            'brand' => $row[0],
            'gda_id' => $row[1],
            'name' => $row[2],
            'indications' => $row[3],
            'public_price_cents' => $row[4],
            'famedic_price_cents' => $row[5],
            'requires_appointment' => $row[7],
        ]);
    }
}
