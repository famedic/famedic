<?php
// app/Actions/Laboratory/HandleStatusUpdateAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryQuote;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class HandleStatusUpdateAction
{
    public function execute(LaboratoryNotification $notification, array $data, array $references): void
    {
        Log::info('Processing status update', [
            'notification_id' => $notification->id,
            'gda_order_id' => $data['id'],
            'status' => $data['status']
        ]);

        // Actualizar notificación
        $notification->update([
            'gda_status' => $data['status'],
            'results_received_at' => $data['status'] === 'completed' ? now() : null,
        ]);

        // Actualizar purchase si existe
        $this->updatePurchase($references, $data);

        // Actualizar quote si existe
        $this->updateQuote($references, $data);

        // Marcar como procesada
        $notification->update(['status' => LaboratoryNotification::STATUS_PROCESSED]);
    }

    protected function updatePurchase(array $references, array $data): void
    {
        if (empty($references['purchase_id'])) {
            return;
        }

        $purchase = LaboratoryPurchase::find($references['purchase_id']);
        if (!$purchase) {
            return;
        }

        $purchaseColumns = Schema::getColumnListing('laboratory_purchases');
        $updates = ['gda_status' => $data['status']];

        // Actualizar gda_acuse si viene
        if (isset($data['GDA_menssage']['acuse']) && in_array('gda_acuse', $purchaseColumns)) {
            $updates['gda_acuse'] = $data['GDA_menssage']['acuse'];
        }

        // Si es cancelado, actualizar cancelled_at
        if ($data['status'] === 'cancelled' && in_array('cancelled_at', $purchaseColumns)) {
            $updates['cancelled_at'] = now();
        }

        $purchase->update($updates);

        Log::info('Purchase status updated', [
            'purchase_id' => $purchase->id,
            'new_status' => $data['status']
        ]);
    }

    protected function updateQuote(array $references, array $data): void
    {
        if (empty($references['quote_id'])) {
            return;
        }

        $quote = LaboratoryQuote::find($references['quote_id']);
        if (!$quote) {
            return;
        }

        $quoteColumns = Schema::getColumnListing('laboratory_quotes');
        $updates = ['gda_status' => $data['status']];

        // Actualizar gda_acuse si viene
        if (isset($data['GDA_menssage']['acuse']) && in_array('gda_acuse', $quoteColumns)) {
            $updates['gda_acuse'] = $data['GDA_menssage']['acuse'];
        }

        // Si es cancelado, actualizar expires_at
        if ($data['status'] === 'cancelled' && in_array('expires_at', $quoteColumns)) {
            $updates['expires_at'] = now();
        }

        $quote->update($updates);

        Log::info('Quote status updated', [
            'quote_id' => $quote->id,
            'new_status' => $data['status']
        ]);
    }
}