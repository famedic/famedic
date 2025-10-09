<?php

namespace App\Imports;

use App\Models\LaboratoryStore;
use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;

class LaboratoryStoresImport implements OnEachRow
{
    use RegistersEventListeners;

    public function onRow(Row $row)
    {
        $row = $row->toArray();

        LaboratoryStore::create([
            'brand' => $row[0],
            'state' => $row[1],
            'name' => $row[2],
            'address' => $row[3],
            'weekly_hours' => $row[4],
            'saturday_hours' => $row[5],
            'sunday_hours' => $row[6],
            'google_maps_url' => $row[7],
        ]);
    }
}
