<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\Administrator;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CreateMarketingCampaignSetupAction
{
    public function __construct(
        private CreateMarketingCampaignAction $createCampaignAction,
        private CreateMarketingCampaignCollectionAction $createCollectionAction,
        private CreateMarketingCampaignLinkAction $createLinkAction,
    ) {}

    /**
     * Crea campaña + enlace (+ colección opcional) en una sola transacción.
     *
     * @param  array<string, mixed>  $data
     * @return array{campaign: MarketingCampaign, link: MarketingCampaignLink}
     */
    public function __invoke(
        array $data,
        Administrator $administrator,
        ?UploadedFile $heroUpload = null,
        array $galleryUploads = [],
    ): array {
        $activate = (bool) ($data['activate'] ?? false);
        $campaignData = (array) ($data['campaign'] ?? []);
        $linkData = (array) ($data['link'] ?? []);
        $collectionData = $data['collection'] ?? null;

        if ($activate) {
            $campaignData['status'] = MarketingCampaignStatus::Active->value;
            $linkData['status'] = MarketingCampaignLinkStatus::Active->value;
        }

        return DB::transaction(function () use (
            $campaignData,
            $linkData,
            $collectionData,
            $administrator,
            $heroUpload,
            $galleryUploads,
        ) {
            $campaign = ($this->createCampaignAction)($campaignData, $administrator);

            if (is_array($collectionData) && $collectionData !== []) {
                $collection = ($this->createCollectionAction)(array_merge($collectionData, [
                    'marketing_campaign_id' => $campaign->id,
                ]));

                $targetType = MarketingCampaignTargetType::tryFrom((string) ($linkData['target_type'] ?? ''));
                if ($targetType === MarketingCampaignTargetType::Collection) {
                    $linkData['target_payload'] = [
                        'marketing_campaign_collection_id' => $collection->id,
                    ];
                }
            }

            $linkPayload = array_merge($linkData, [
                'marketing_campaign_id' => $campaign->id,
            ]);

            if (! empty($linkPayload['gallery_items']) && is_string($linkPayload['gallery_items'])) {
                $decoded = json_decode($linkPayload['gallery_items'], true);
                $linkPayload['gallery_items'] = is_array($decoded) ? $decoded : [];
            }

            if ($galleryUploads !== []) {
                $linkPayload['gallery_uploads'] = $galleryUploads;
            }

            $link = ($this->createLinkAction)(
                $linkPayload,
                $administrator,
                $heroUpload,
            );

            return [
                'campaign' => $campaign->fresh(),
                'link' => $link,
            ];
        });
    }
}
