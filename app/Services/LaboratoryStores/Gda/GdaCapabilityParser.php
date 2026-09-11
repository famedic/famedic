<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaCapabilityParser
{
    public function __construct(private readonly GdaCapabilityCatalog $catalog) {}

    public function parse(array $rawPayload): array
    {
        $enabled = [];
        $warnings = [];

        foreach ($rawPayload as $column => $value) {
            $capability = $this->catalog->byColumn((string) $column);

            if ($capability === null) {
                continue;
            }

            $cell = trim((string) $value);

            if ($cell === '') {
                continue;
            }

            if (mb_strtolower($cell) === 'a') {
                $enabled[] = $capability['slug'];

                continue;
            }

            $warnings[] = "Unexpected capability marker {$cell} in {$column}";
        }

        return ['enabled' => $enabled, 'warnings' => $warnings];
    }
}
