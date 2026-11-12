<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Tests\Support\Api\V1\Identity\OtpEnrollmentFixture;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

trait Rc2IsolatedDatabase
{
    protected function resetRc2IsolatedState(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new \LogicException('RC2 isolation requires the isolated MySQL test database');
        }

        $tables = DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                $name = (string) ($table->TABLE_NAME ?? '');
                if ($name === '' || $name === 'migrations') {
                    continue;
                }
                $q = chr(96);
                DB::statement('DELETE FROM '.$q.str_replace($q, $q.$q, $name).$q);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        DB::table('opaque_benefit_mutex')->updateOrInsert(['id' => 1], ['generation' => 0]);
        SyntheticConsumerRegistryFixture::reset();
        OtpEnrollmentFixture::reset();
    }
}
