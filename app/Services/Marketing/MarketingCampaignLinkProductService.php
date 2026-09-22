<?php

namespace App\Services\Marketing;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkProductSection;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketingCampaignLinkProductService
{
    public const MAX_PRIMARY = 20;

    public const MAX_RELATED = 8;

    /**
     * @param  list<int|string>  $primaryIds
     * @param  list<int|string>  $relatedIds
     * @param  list<array<string, mixed>>  $primaryImages
     * @param  list<array<string, mixed>>  $relatedImages
     * @param  list<UploadedFile|null>  $primaryUploads
     * @param  list<UploadedFile|null>  $relatedUploads
     */
    public function sync(
        MarketingCampaignLink $link,
        array $primaryIds,
        array $relatedIds,
        LaboratoryBrand $brand,
        array $primaryImages = [],
        array $relatedImages = [],
        array $primaryUploads = [],
        array $relatedUploads = [],
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

        DB::transaction(function () use ($link, $primary, $related, $primaryImages, $relatedImages, $primaryUploads, $relatedUploads) {
            $existing = MarketingCampaignLinkProduct::query()
                ->where('marketing_campaign_link_id', $link->id)
                ->get()
                ->keyBy(fn (MarketingCampaignLinkProduct $item) => $this->recordKey($item->section, (int) $item->laboratory_test_id));

            MarketingCampaignLinkProduct::query()
                ->where('marketing_campaign_link_id', $link->id)
                ->delete();

            $this->insertSection(
                $link,
                $primary,
                MarketingCampaignLinkProductSection::Primary,
                $primaryImages,
                $primaryUploads,
                $existing,
            );
            $this->insertSection(
                $link,
                $related,
                MarketingCampaignLinkProductSection::Related,
                $relatedImages,
                $relatedUploads,
                $existing,
            );
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
     * @param  list<array<string, mixed>>  $imageItems
     * @param  list<UploadedFile|null>  $uploads
     * @param  \Illuminate\Support\Collection<string, MarketingCampaignLinkProduct>  $existing
     */
    private function insertSection(
        MarketingCampaignLink $link,
        array $ids,
        MarketingCampaignLinkProductSection $section,
        array $imageItems = [],
        array $uploads = [],
        $existing = null,
    ): void {
        $imageItemsByTest = collect($imageItems)
            ->filter(fn ($item) => is_array($item) && isset($item['laboratory_test_id']))
            ->keyBy(fn ($item) => (int) $item['laboratory_test_id']);

        foreach (array_values($ids) as $position => $testId) {
            $testId = (int) $testId;
            $imageItem = $imageItemsByTest->get($testId, []);
            $previous = $existing?->get($this->recordKey($section, $testId));

            MarketingCampaignLinkProduct::query()->create([
                'marketing_campaign_link_id' => $link->id,
                'laboratory_test_id' => $testId,
                'section' => $section,
                'position' => $position,
                'is_featured' => $section === MarketingCampaignLinkProductSection::Primary,
                ...$this->imagePayload($link, $imageItem, $uploads, $previous),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $imageItem
     * @param  list<UploadedFile|null>  $uploads
     * @return array<string, mixed>
     */
    private function imagePayload(
        MarketingCampaignLink $link,
        array $imageItem,
        array $uploads,
        ?MarketingCampaignLinkProduct $previous,
    ): array {
        if ((bool) ($imageItem['clear'] ?? false)) {
            return $this->emptyImagePayload($imageItem);
        }

        $uploadIndex = $imageItem['upload_index'] ?? null;
        $upload = is_numeric($uploadIndex) ? ($uploads[(int) $uploadIndex] ?? null) : null;

        if ($upload instanceof UploadedFile) {
            $disk = 'public';
            $directory = sprintf(
                'marketing-campaigns/%d/links/%d/products',
                (int) $link->marketing_campaign_id,
                (int) $link->id,
            );
            $extension = strtolower($upload->getClientOriginalExtension() ?: 'jpg');
            $filename = Str::uuid()->toString().'.'.$extension;
            $path = $upload->storeAs($directory, $filename, [
                'disk' => $disk,
                'visibility' => 'public',
            ]);

            if (! is_string($path) || $path === '') {
                throw ValidationException::withMessages([
                    'primary_product_image_uploads' => 'No se pudo almacenar una imagen de producto.',
                ]);
            }

            return [
                'image_source' => 'upload',
                'image_disk' => $disk,
                'image_path' => $path,
                'image_alt' => filled($imageItem['alt'] ?? null) ? (string) $imageItem['alt'] : null,
            ];
        }

        if ($previous && $previous->image_source === 'upload' && filled($previous->image_path)) {
            return [
                'image_source' => 'upload',
                'image_disk' => $previous->image_disk ?: 'public',
                'image_path' => $previous->image_path,
                'image_alt' => filled($imageItem['alt'] ?? null)
                    ? (string) $imageItem['alt']
                    : $previous->image_alt,
            ];
        }

        return $this->emptyImagePayload($imageItem);
    }

    /**
     * @param  array<string, mixed>  $imageItem
     * @return array<string, mixed>
     */
    private function emptyImagePayload(array $imageItem = []): array
    {
        return [
            'image_source' => 'none',
            'image_disk' => null,
            'image_path' => null,
            'image_alt' => filled($imageItem['alt'] ?? null) ? (string) $imageItem['alt'] : null,
        ];
    }

    public function copyImagesFromSource(MarketingCampaignLink $link, MarketingCampaignLink $source): void
    {
        $source->loadMissing('landingProducts');

        foreach ($source->landingProducts as $sourceProduct) {
            if ($sourceProduct->image_source !== 'upload' || ! filled($sourceProduct->image_path)) {
                continue;
            }

            MarketingCampaignLinkProduct::query()
                ->where('marketing_campaign_link_id', $link->id)
                ->where('laboratory_test_id', $sourceProduct->laboratory_test_id)
                ->where('section', $sourceProduct->section)
                ->update([
                    'image_source' => $sourceProduct->image_source,
                    'image_disk' => $sourceProduct->image_disk,
                    'image_path' => $sourceProduct->image_path,
                    'image_alt' => $sourceProduct->image_alt,
                ]);
        }
    }

    public function deletePath(?string $disk, ?string $path): void
    {
        if (! filled($path)) {
            return;
        }

        try {
            Storage::disk($disk ?: 'public')->delete($path);
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function recordKey(MarketingCampaignLinkProductSection|string $section, int $testId): string
    {
        $value = $section instanceof MarketingCampaignLinkProductSection
            ? $section->value
            : (string) $section;

        return $value.':'.$testId;
    }
}
