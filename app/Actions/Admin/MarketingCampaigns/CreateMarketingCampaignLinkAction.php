<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignTargetType;
use App\Models\Administrator;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkImage;
use App\Services\Marketing\MarketingCampaignLinkBrandResolver;
use App\Services\Marketing\MarketingCampaignLinkCategoryService;
use App\Services\Marketing\MarketingCampaignLinkImageService;
use App\Services\Marketing\MarketingCampaignLinkMediaCleanup;
use App\Services\Marketing\MarketingCampaignLinkProductService;
use App\Services\Marketing\MarketingCampaignLinkSlugService;
use App\Services\Marketing\MarketingCampaignTargetPayloadValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateMarketingCampaignLinkAction
{
    /**
     * @var list<string>
     */
    private const HERO_COLUMNS = [
        'hero_image_source',
        'hero_image_disk',
        'hero_image_path',
        'hero_image_url',
        'hero_image_alt',
    ];

    public function __construct(
        private MarketingCampaignLinkSlugService $slugService,
        private MarketingCampaignTargetPayloadValidator $targetPayloadValidator,
        private MarketingCampaignLinkBrandResolver $brandResolver,
        private MarketingCampaignLinkProductService $productService,
        private MarketingCampaignLinkCategoryService $categoryService,
        private MarketingCampaignLinkImageService $imageService,
        private MarketingCampaignLinkMediaCleanup $mediaCleanup,
    ) {}

    /**
     * Crea un enlace de campaña junto con su landing comercial (marca derivada,
     * productos primary/related, categorías relacionadas y hero image).
     *
     * Validación de slug e inserción corren en la misma transacción para reducir
     * ventanas de carrera. Riesgo residual: la disponibilidad se comprueba en dos
     * tablas (`marketing_campaign_links` y `marketing_campaign_link_aliases`) sin
     * un lock global compartido; dos requests concurrentes con el mismo slug aún
     * podrían pasar assertAvailable y chocar en el unique index de una de ellas.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(
        array $data,
        Administrator $administrator,
        ?UploadedFile $heroUpload = null,
        array $galleryItems = [],
        array $galleryUploads = [],
    ): MarketingCampaignLink {
        $targetType = $data['target_type'] instanceof MarketingCampaignTargetType
            ? $data['target_type']
            : MarketingCampaignTargetType::from($data['target_type']);

        $primaryIds = $data['primary_laboratory_test_ids'] ?? [];
        $relatedIds = $data['related_laboratory_test_ids'] ?? [];
        $primaryImages = $data['primary_product_images'] ?? [];
        $relatedImages = $data['related_product_images'] ?? [];
        $primaryUploads = $data['primary_product_image_uploads'] ?? [];
        $relatedUploads = $data['related_product_image_uploads'] ?? [];
        $categoryIds = $data['related_category_ids'] ?? [];
        $galleryItemsPayload = $data['gallery_items'] ?? $galleryItems;
        $galleryUploadsPayload = $data['gallery_uploads'] ?? $galleryUploads;
        $sourceLinkId = (int) ($data['source_link_id'] ?? 0);
        $reuseSourceMedia = (bool) ($data['reuse_source_media'] ?? false);

        $linkData = Arr::except($data, [
            'primary_laboratory_test_ids',
            'related_laboratory_test_ids',
            'primary_product_images',
            'related_product_images',
            'primary_product_image_uploads',
            'related_product_image_uploads',
            'related_category_ids',
            'gallery_items',
            'gallery_uploads',
            'source_link_id',
            'reuse_source_media',
        ]);

        return DB::transaction(function () use (
            $linkData,
            $administrator,
            $targetType,
            $primaryIds,
            $relatedIds,
            $primaryImages,
            $relatedImages,
            $primaryUploads,
            $relatedUploads,
            $categoryIds,
            $heroUpload,
            $galleryItemsPayload,
            $galleryUploadsPayload,
            $sourceLinkId,
            $reuseSourceMedia,
        ) {
            $newMediaPaths = [];

            try {
                $campaign = MarketingCampaign::query()->findOrFail((int) $linkData['marketing_campaign_id']);
                $campaign->assertWritable();

                $linkData['slug'] = $this->slugService->assertAvailable($linkData['slug']);
                $linkData['target_payload'] = $this->targetPayloadValidator->validate(
                    $targetType,
                    $linkData['target_payload'],
                    (int) $linkData['marketing_campaign_id'],
                );
                $linkData['target_type'] = $targetType;

                $heroFields = Arr::only($linkData, ['hero_image_source', 'hero_image_url', 'hero_image_alt']);
                $baseData = Arr::except($linkData, self::HERO_COLUMNS);

                $link = MarketingCampaignLink::query()->create(array_merge($baseData, [
                    'created_by' => $administrator->id,
                    'updated_by' => $administrator->id,
                    'hero_image_source' => 'none',
                ]));

                $brand = $this->brandResolver->resolve($link);

                if ($brand === null) {
                    throw ValidationException::withMessages([
                        'target_payload' => 'No se pudo determinar la marca del enlace a partir del destino seleccionado.',
                    ]);
                }

                $this->categoryService->sync($link, $categoryIds);

                $this->productService->sync(
                    $link,
                    $primaryIds,
                    $relatedIds,
                    $brand,
                    $primaryImages,
                    $relatedImages,
                    $primaryUploads,
                    $relatedUploads,
                );

                if ($reuseSourceMedia && $sourceLinkId > 0) {
                    $this->copyMediaFromSource($link, $sourceLinkId, copyProductImages: true);
                } else {
                    $newMediaPaths = array_merge(
                        $newMediaPaths,
                        $this->mediaCleanup->applyHero($link, $heroFields, $heroUpload)['created'],
                    );
                    $newMediaPaths = array_merge(
                        $newMediaPaths,
                        $this->imageService->sync($link, $galleryItemsPayload, $galleryUploadsPayload),
                    );
                }

                return $link->fresh();
            } catch (\Throwable $exception) {
                $this->mediaCleanup->deletePaths($newMediaPaths);

                throw $exception;
            }
        });
    }

    private function copyMediaFromSource(
        MarketingCampaignLink $link,
        int $sourceLinkId,
        bool $copyProductImages = false,
    ): void
    {
        $source = MarketingCampaignLink::query()
            ->whereKey($sourceLinkId)
            ->where('marketing_campaign_id', $link->marketing_campaign_id)
            ->with([
                'landingProducts',
                'landingImages' => fn ($query) => $query->where('type', 'gallery')->orderBy('position')->orderBy('id'),
            ])
            ->first();

        if (! $source) {
            return;
        }

        $link->update([
            'hero_image_source' => $source->hero_image_source,
            'hero_image_disk' => $source->hero_image_disk,
            'hero_image_path' => $source->hero_image_path,
            'hero_image_url' => $source->hero_image_url,
            'hero_image_alt' => $source->hero_image_alt,
        ]);

        foreach ($source->landingImages as $image) {
            MarketingCampaignLinkImage::query()->create([
                'marketing_campaign_link_id' => $link->id,
                'type' => $image->type,
                'source' => $image->source,
                'disk' => $image->disk,
                'path' => $image->path,
                'external_url' => $image->external_url,
                'alt_text' => $image->alt_text,
                'position' => $image->position,
            ]);
        }

        if ($copyProductImages) {
            $this->productService->copyImagesFromSource($link, $source);
        }
    }
}
