<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignTargetType;
use App\Models\Administrator;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignLinkSlugService;
use App\Services\Marketing\MarketingCampaignTargetPayloadValidator;
use Illuminate\Support\Facades\DB;

class CreateMarketingCampaignLinkAction
{
    public function __construct(
        private MarketingCampaignLinkSlugService $slugService,
        private MarketingCampaignTargetPayloadValidator $targetPayloadValidator,
    ) {}

    /**
     * Crea un enlace de campaña.
     *
     * Validación de slug e inserción corren en la misma transacción para reducir
     * ventanas de carrera. Riesgo residual: la disponibilidad se comprueba en dos
     * tablas (`marketing_campaign_links` y `marketing_campaign_link_aliases`) sin
     * un lock global compartido; dos requests concurrentes con el mismo slug aún
     * podrían pasar assertAvailable y chocar en el unique index de una de ellas.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data, Administrator $administrator): MarketingCampaignLink
    {
        $targetType = $data['target_type'] instanceof MarketingCampaignTargetType
            ? $data['target_type']
            : MarketingCampaignTargetType::from($data['target_type']);

        return DB::transaction(function () use ($data, $administrator, $targetType) {
            $campaign = MarketingCampaign::query()->findOrFail((int) $data['marketing_campaign_id']);
            $campaign->assertWritable();

            $data['slug'] = $this->slugService->assertAvailable($data['slug']);
            $data['target_payload'] = $this->targetPayloadValidator->validate(
                $targetType,
                $data['target_payload'],
                (int) $data['marketing_campaign_id'],
            );
            $data['target_type'] = $targetType;

            return MarketingCampaignLink::query()->create(array_merge($data, [
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]));
        });
    }
}
