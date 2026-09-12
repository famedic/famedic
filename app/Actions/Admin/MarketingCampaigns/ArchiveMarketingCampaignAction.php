<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignStatus;
use App\Models\Administrator;
use App\Models\MarketingCampaign;
use Illuminate\Auth\Access\AuthorizationException;

class ArchiveMarketingCampaignAction
{
    /**
     * Archiva una campaña (idempotente). No soft-delete ni elimina links/colecciones.
     *
     * Autorización: mismo permiso de edición que update (`marketing-campaigns.manage.edit`).
     */
    public function __invoke(
        MarketingCampaign $campaign,
        Administrator $administrator,
    ): MarketingCampaign {
        if (! $administrator->hasPermissionTo('marketing-campaigns.manage.edit')) {
            throw new AuthorizationException('No autorizado para archivar campañas.');
        }

        if ($campaign->status === MarketingCampaignStatus::Archived) {
            return $campaign;
        }

        $campaign->update([
            'status' => MarketingCampaignStatus::Archived,
            'updated_by' => $administrator->id,
        ]);

        return $campaign->refresh();
    }
}
