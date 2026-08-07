<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignTargetType;
use App\Models\Administrator;
use App\Models\MarketingCampaignLink;
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

class UpdateMarketingCampaignLinkAction
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
     * Actualiza un enlace de campaña y su landing comercial dentro de una transacción.
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
        ?UploadedFile $heroUpload = null,
        array $galleryItems = [],
        array $galleryUploads = [],
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

        $primaryIds = $data['primary_laboratory_test_ids'] ?? [];
        $relatedIds = $data['related_laboratory_test_ids'] ?? [];
        $categoryIds = $data['related_category_ids'] ?? [];
        $galleryItemsPayload = $data['gallery_items'] ?? $galleryItems;
        $galleryUploadsPayload = $data['gallery_uploads'] ?? $galleryUploads;

        $linkData = Arr::except($data, [
            'slug',
            'primary_laboratory_test_ids',
            'related_laboratory_test_ids',
            'related_category_ids',
            'gallery_items',
            'gallery_uploads',
        ]);
        $heroFields = Arr::only($linkData, ['hero_image_source', 'hero_image_url', 'hero_image_alt']);
        $baseData = Arr::except($linkData, self::HERO_COLUMNS);
        $newSlug = $data['slug'];

        return DB::transaction(function () use (
            $link,
            $baseData,
            $administrator,
            $targetType,
            $targetPayload,
            $primaryIds,
            $relatedIds,
            $categoryIds,
            $heroFields,
            $heroUpload,
            $newSlug,
            $galleryItemsPayload,
            $galleryUploadsPayload,
        ) {
            $newMediaPaths = [];
            $removedMediaPaths = [];

            try {
                $link->update(array_merge($baseData, [
                    'target_type' => $targetType,
                    'target_payload' => $targetPayload,
                    'updated_by' => $administrator->id,
                ]));

                $brand = $this->brandResolver->resolve($link);

                if ($brand === null) {
                    throw ValidationException::withMessages([
                        'target_payload' => 'No se pudo determinar la marca del enlace a partir del destino seleccionado.',
                    ]);
                }

                $this->productService->sync($link, $primaryIds, $relatedIds, $brand);
                $this->categoryService->sync($link, $categoryIds);

                $heroResult = $this->mediaCleanup->applyHero(
                    $link,
                    $heroFields,
                    $heroUpload,
                    deferRemovedDeletion: true,
                );
                $newMediaPaths = array_merge($newMediaPaths, $heroResult['created']);
                $removedMediaPaths = array_merge($removedMediaPaths, $heroResult['removed']);

                $galleryResult = $this->imageService->sync(
                    $link,
                    $galleryItemsPayload,
                    $galleryUploadsPayload,
                    deferFileDeletion: true,
                );
                $newMediaPaths = array_merge($newMediaPaths, $galleryResult['created']);
                $removedMediaPaths = array_merge($removedMediaPaths, $galleryResult['removed']);

                $link = $this->slugService->changeSlug($link, $newSlug);

                $this->mediaCleanup->deleteRemovedPathsIfUnreferenced(
                    $removedMediaPaths,
                    $link->id,
                );

                return $link->fresh();
            } catch (\Throwable $exception) {
                $this->mediaCleanup->deletePaths($newMediaPaths);

                throw $exception;
            }
        });
    }
}
