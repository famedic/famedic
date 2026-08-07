<?php

namespace App\Services\Marketing;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkProductSection;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketingCampaignLinkProductService
{
    public const MAX_PRIMARY = 20;

    public const MAX_RELATED = 8;

    /**
     * @param  list<int|string>  $primaryIds
     * @param  list<int|string>  $relatedIds
     */
    public function sync(
        MarketingCampaignLink $link,
        array $primaryIds,
        array $relatedIds,
        LaboratoryBrand $brand,
    ): void {
        $primary = $this->normalizeIds($primaryIds, 'primary_laboratory_test_ids', self::MAX_PRIMARY);
        $related = $this->normalizeIds($relatedIds, 'related_laboratory_test_ids', self::MAX_RELATED);

        $overlap = array_values(array_intersect($primary, $related));
        if ($overlap !== []) {
            throw ValidationException::withMessages([
                'related_laboratory_test_ids' => 'Un estudio no puede aparecer a la vez en destacados y relacionados.',
            ]);
        }

        $allIds = [...$primary, ...$related];
        $this->assertCompatibleTests($allIds, $brand);

        DB::transaction(function () use ($link, $primary, $related) {
            MarketingCampaignLinkProduct::query()
                ->where('marketing_campaign_link_id', $link->id)
                ->delete();

            $this->insertSection($link, $primary, MarketingCampaignLinkProductSection::Primary);
            $this->insertSection($link, $related, MarketingCampaignLinkProductSection::Related);
        });
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids, string $field, int $max): array
    {
        $normalized = array_map(static fn ($id) => (int) $id, $ids);

        if (count($normalized) !== count(array_unique($normalized))) {
            throw ValidationException::withMessages([
                $field => 'La lista contiene estudios duplicados.',
            ]);
        }

        if (count($normalized) > $max) {
            throw ValidationException::withMessages([
                $field => "No puedes asignar más de {$max} estudios en esta sección.",
            ]);
        }

        return array_values($normalized);
    }

    /**
     * @param  list<int>  $ids
     */
    private function assertCompatibleTests(array $ids, LaboratoryBrand $brand): void
    {
        if ($ids === []) {
            return;
        }

        $tests = LaboratoryTest::query()
            ->whereIn('id', $ids)
            ->get(['id', 'brand']);

        if ($tests->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'primary_laboratory_test_ids' => 'Uno o más estudios no existen o fueron eliminados.',
            ]);
        }

        foreach ($tests as $test) {
            if ($test->brand !== $brand) {
                throw ValidationException::withMessages([
                    'primary_laboratory_test_ids' => 'Todos los estudios de la landing deben pertenecer a la marca del enlace.',
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function insertSection(
        MarketingCampaignLink $link,
        array $ids,
        MarketingCampaignLinkProductSection $section,
    ): void {
        foreach (array_values($ids) as $position => $testId) {
            MarketingCampaignLinkProduct::query()->create([
                'marketing_campaign_link_id' => $link->id,
                'laboratory_test_id' => $testId,
                'section' => $section,
                'position' => $position,
                'is_featured' => $section === MarketingCampaignLinkProductSection::Primary,
            ]);
        }
    }
}
