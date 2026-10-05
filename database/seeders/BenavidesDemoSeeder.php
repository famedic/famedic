<?php

namespace Database\Seeders;

use App\Models\BenavidesCode;
use Illuminate\Database\Seeder;
use RuntimeException;

class BenavidesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('BenavidesDemoSeeder no puede ejecutarse en producción.');
        }

        foreach (range(1, 50) as $number) {
            BenavidesCode::query()->firstOrCreate(
                ['code' => sprintf('TESTBEN%06d', $number)],
                [
                    'user_id' => null,
                    'assigned_at' => null,
                    'import_batch_id' => null,
                ],
            );
        }

        $this->command?->info('50 códigos demo Benavides disponibles para pruebas.');
    }
}
