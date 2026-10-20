<?php

namespace Tests\Unit\LaboratoryResults\Support;

use Database\Seeders\LaboratoryGdaAnalyteSeeder;

trait HemogramAnalyteCatalog
{
    protected function seedHemogramAnalyteCatalog(): void
    {
        (new LaboratoryGdaAnalyteSeeder)->run();
    }
}
