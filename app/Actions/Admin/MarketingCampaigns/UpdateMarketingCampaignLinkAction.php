<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignTargetType;
use App\Models\Administrator;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignLinkSlugService;
use App\Services\Marketing\MarketingCampaignTargetPayloadValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateMarketingCampaignLinkAction
{
    public function __construct(
        private MarketingCampaignLinkSlugService $slugService,
        private MarketingCampaignTargetPayloadValidator $targetPayloadValidator,
    ) {}

    /**
     * Actualiza un enlace de campaña dentro de una transacción.
     *
     * Riesgo residual concurrente: assertAvailable consulta links (con trashed)
     * y aliases por separado; sin lock global entre ambas tablas, dos writers
     * concurrentes podrían validar el mismo slug y fallar luego en unique index.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(
        MarketingCampaignLink $link,
        array $data,
        Administrator $administrator,
    ): MarketingCampaignLink {
        $link->loadMissing('campaign');
        $link->campaign?->assertWritable();

        $targetType = $data['target_type'] instanceof MarketingCampaignTargetType
            ? $data['target_type']
            : MarketingCampaignTargetType::from($data['target_type']);

        $targetPayload = $this->targetPayloadValidator->validate(
            $targetType,
            $data['target_payload'],
            (int) $link->marketing_campaign_id,
        );

        return DB::transaction(function () use ($link, $data, $administrator, $targetType, $targetPayload) {
            $link->update(array_merge(Arr::except($data, ['slug']), [
                'target_type' => $targetType,
                'target_payload' => $targetPayload,
                'updated_by' => $administrator->id,
            ]));

            return $this->slugService->changeSlug($link, $data['slug']);
        });
    }
}
