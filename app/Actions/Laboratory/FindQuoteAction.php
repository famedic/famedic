<?php
// app/Actions/Laboratory/FindQuoteAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryQuote;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FindQuoteAction
{
    public function execute(string $gdaOrderId, ?string $gdaExternalId, ?string $gdaAcuse): ?LaboratoryQuote
    {
        $columns = Schema::getColumnListing('laboratory_quotes');
        
        // Buscar por múltiples criterios en orden de prioridad
        $searchStrategies = [
            'gda_consecutivo' => $gdaOrderId,
            'gda_acuse' => $gdaAcuse,
            'gda_order_id' => $gdaOrderId,
            'gda_external_id' => $gdaExternalId,
            'gda_quote_id' => $gdaOrderId,
            'purchase_id' => $gdaOrderId,
        ];

        foreach ($searchStrategies as $field => $value) {
            if ($value && in_array($field, $columns)) {
                $quote = LaboratoryQuote::where($field, $value)->first();
                if ($quote) {
                    Log::info('Found quote by ' . $field, [
                        $field => $value,
                        'quote_id' => $quote->id
                    ]);
                    return $quote;
                }
            }
        }

        Log::info('No quote found', [
            'gda_order_id' => $gdaOrderId,
            'gda_external_id' => $gdaExternalId,
            'gda_acuse' => $gdaAcuse
        ]);

        return null;
    }
}