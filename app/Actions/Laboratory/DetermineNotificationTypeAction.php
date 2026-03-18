<?php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryNotification;

class DetermineNotificationTypeAction
{
    public function execute(string $status, ?string $lineaNegocio): string
    {
        // Primero verificar por línea de negocio (más específico)
        if ($lineaNegocio === LaboratoryNotification::LINEA_NEGOCIO_SAMPLE) {
            return LaboratoryNotification::TYPE_SAMPLE_COLLECTION;
        }
        
        if ($lineaNegocio === LaboratoryNotification::LINEA_NEGOCIO_RESULTS) {
            return LaboratoryNotification::TYPE_RESULTS;
        }
        
        // Fallback a determinar por status
        return match ($status) {
            'active' => LaboratoryNotification::TYPE_NOTIFICATION,
            'completed' => LaboratoryNotification::TYPE_RESULTS,
            'in-progress', 'cancelled' => LaboratoryNotification::TYPE_STATUS_UPDATE,
            default => LaboratoryNotification::TYPE_NOTIFICATION
        };
    }
}