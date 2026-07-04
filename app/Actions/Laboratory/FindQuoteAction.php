<?php
// app/Actions/Laboratory/FindQuoteAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryQuote;
use App\Support\GDA\GdaWebhookPayloadResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FindQuoteAction
{
    public function __construct(
        protected GdaWebhookPayloadResolver $payloadResolver,
    ) {
    }

    /**
     * @param  array<string, mixed>  $resolved  Resultado de GdaWebhookPayloadResolver::resolve()
     */
    public function execute(array $resolved, ?string $gdaAcuse = null): ?LaboratoryQuote
    {
        $columns = Schema::getColumnListing('laboratory_quotes');
        $acuse = $gdaAcuse ?? $resolved['acuse'] ?? null;

        if (! empty($resolved['gda_order_id']) && in_array('gda_order_id', $columns)) {
            $quote = LaboratoryQuote::where('gda_order_id', $resolved['gda_order_id'])->first();
            if ($quote) {
                Log::info('Found quote by gda_order_id', [
                    'gda_order_id' => $resolved['gda_order_id'],
                    'quote_id' => $quote->id,
                ]);

                return $quote;
            }
        }

        $etiqueta = $resolved['infogda_etiqueta'] ?? null;
        $gdaOrderId = $resolved['gda_order_id'] ?? null;
        if (
            $etiqueta
            && $etiqueta !== $gdaOrderId
            && in_array('gda_order_id', $columns)
        ) {
            $quote = LaboratoryQuote::where('gda_order_id', $etiqueta)->first();
            if ($quote) {
                Log::info('Found quote by infogda_etiqueta', [
                    'infogda_etiqueta' => $etiqueta,
                    'quote_id' => $quote->id,
                ]);

                return $quote;
            }
        }

        if (
            isset($resolved['gda_consecutivo'])
            && $resolved['gda_consecutivo'] !== null
            && in_array('gda_consecutivo', $columns)
        ) {
            $quote = LaboratoryQuote::where('gda_consecutivo', $resolved['gda_consecutivo'])->first();
            if ($quote) {
                Log::info('Found quote by gda_consecutivo', [
                    'gda_consecutivo' => $resolved['gda_consecutivo'],
                    'quote_id' => $quote->id,
                ]);

                return $quote;
            }
        }

        $serviceRequestId = $resolved['service_request_id'] ?? null;
        if (
            $serviceRequestId
            && $this->payloadResolver->isNumericConsecutivo($serviceRequestId)
            && in_array('gda_consecutivo', $columns)
        ) {
            $quote = LaboratoryQuote::where('gda_consecutivo', (int) $serviceRequestId)->first();
            if ($quote) {
                Log::info('Found quote by gda_consecutivo', [
                    'gda_consecutivo' => (int) $serviceRequestId,
                    'quote_id' => $quote->id,
                ]);

                return $quote;
            }
        }

        if ($acuse && in_array('gda_acuse', $columns)) {
            $quote = LaboratoryQuote::where('gda_acuse', $acuse)->first();
            if ($quote) {
                Log::info('Found quote by gda_acuse', [
                    'gda_acuse' => $acuse,
                    'quote_id' => $quote->id,
                ]);

                return $quote;
            }
        }

        if (! empty($resolved['gda_external_id']) && in_array('gda_external_id', $columns)) {
            $quote = LaboratoryQuote::where('gda_external_id', $resolved['gda_external_id'])->first();
            if ($quote) {
                Log::info('Found quote by gda_external_id', [
                    'gda_external_id' => $resolved['gda_external_id'],
                    'quote_id' => $quote->id,
                ]);

                return $quote;
            }
        }

        if (! empty($resolved['gda_order_id']) && in_array('gda_quote_id', $columns)) {
            $quote = LaboratoryQuote::where('gda_quote_id', $resolved['gda_order_id'])->first();
            if ($quote) {
                Log::info('Found quote by gda_quote_id', [
                    'gda_quote_id' => $resolved['gda_order_id'],
                    'quote_id' => $quote->id,
                ]);

                return $quote;
            }
        }

        $requisitionValue = $resolved['requisition_value'] ?? null;
        if (
            $requisitionValue
            && $this->payloadResolver->isNumericConsecutivo($requisitionValue)
            && in_array('laboratory_purchase_id', $columns)
        ) {
            $quote = LaboratoryQuote::where('laboratory_purchase_id', (int) $requisitionValue)->first();
            if ($quote) {
                Log::info('Found quote by laboratory_purchase_id from requisition.value', [
                    'laboratory_purchase_id' => (int) $requisitionValue,
                    'quote_id' => $quote->id,
                ]);

                return $quote;
            }
        }

        Log::info('No quote found', [
            'gda_order_id' => $resolved['gda_order_id'] ?? null,
            'gda_external_id' => $resolved['gda_external_id'] ?? null,
            'gda_acuse' => $acuse,
        ]);

        return null;
    }
}
