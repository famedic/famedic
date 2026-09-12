<?php

namespace App\Services\Marketing;

use App\Models\LaboratoryTestCategory;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketingCampaignLinkCategoryService
{
    public const MAX = 8;

    /**
     * @param  list<int|string>  $categoryIds
     */
    public function sync(MarketingCampaignLink $link, array $categoryIds): void
    {
        $ids = array_map(static fn ($id) => (int) $id, $categoryIds);

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'related_category_ids' => 'La lista contiene categorías duplicadas.',
            ]);
        }

        if (count($ids) > self::MAX) {
            throw ValidationException::withMessages([
                'related_category_ids' => 'No puedes asignar más de '.self::MAX.' categorías relacionadas.',
            ]);
        }

        if ($ids !== []) {
            $count = LaboratoryTestCategory::query()->whereIn('id', $ids)->count();
            if ($count !== count($ids)) {
                throw ValidationException::withMessages([
                    'related_category_ids' => 'Una o más categorías no existen o fueron eliminadas.',
                ]);
            }
        }

        DB::transaction(function () use ($link, $ids) {
            MarketingCampaignLinkCategory::query()
                ->where('marketing_campaign_link_id', $link->id)
                ->delete();

            foreach (array_values($ids) as $position => $categoryId) {
                MarketingCampaignLinkCategory::query()->create([
                    'marketing_campaign_link_id' => $link->id,
                    'laboratory_test_category_id' => $categoryId,
                    'position' => $position,
                ]);
            }
        });
    }
}
