<?php

namespace Database\Seeders;

use App\Enums\LaboratoryAnalyteAliasSource;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryAnalyteAlias;
use App\Services\LaboratoryResults\Catalog\LaboratoryGdaAnalyteCatalogDefinition;
use App\Services\LaboratoryResults\Extraction\LaboratoryAnalyteNameNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class LaboratoryGdaAnalyteSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('laboratory_analytes') || ! Schema::hasTable('laboratory_analyte_aliases')) {
            return;
        }

        foreach (LaboratoryGdaAnalyteCatalogDefinition::entries() as $entry) {
            $analyte = LaboratoryAnalyte::query()->updateOrCreate(
                ['code' => $entry['code']],
                [
                    'canonical_name' => $entry['canonical_name'],
                    'loinc_code' => null,
                    'default_unit' => $entry['default_unit'],
                    'value_kind' => $entry['value_kind'],
                    'category' => $entry['category'],
                    'is_active' => true,
                ],
            );

            foreach ($entry['aliases'] as $aliasRaw) {
                $aliasNormalized = LaboratoryAnalyteNameNormalizer::normalize($aliasRaw);

                if ($aliasNormalized === '') {
                    continue;
                }

                $existing = LaboratoryAnalyteAlias::query()
                    ->where('alias_normalized', $aliasNormalized)
                    ->first();

                if ($existing !== null && $existing->laboratory_analyte_id !== $analyte->id) {
                    continue;
                }

                LaboratoryAnalyteAlias::query()->updateOrCreate(
                    ['alias_normalized' => $aliasNormalized],
                    [
                        'laboratory_analyte_id' => $analyte->id,
                        'alias_raw' => $aliasRaw,
                        'source' => LaboratoryAnalyteAliasSource::Import,
                        'confidence' => 1.0,
                    ],
                );
            }
        }
    }
}
