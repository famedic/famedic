<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class MarketingCampaignLinkMediaCleanup
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
        private MarketingCampaignHeroImageService $heroImageService,
    ) {}

    /**
     * @param  list<array{disk?: string|null, path?: string|null}>  $paths
     */
    public function deletePaths(array $paths): void
    {
        foreach ($paths as $entry) {
            $this->heroImageService->deletePath(
                $entry['disk'] ?? null,
                $entry['path'] ?? null,
            );
        }
    }

    /**
     * @param  list<array{disk?: string|null, path?: string|null}>  $paths
     */
    public function deleteRemovedPathsIfUnreferenced(array $paths, int $excludeLinkId): void
    {
        foreach ($paths as $entry) {
            $this->deletePathIfUnreferenced(
                $entry['disk'] ?? null,
                $entry['path'] ?? null,
                $excludeLinkId,
            );
        }
    }

    public function deletePathIfUnreferenced(?string $disk, ?string $path, int $excludeLinkId): void
    {
        if (! filled($path)) {
            return;
        }

        if ($this->isHeroPathReferencedElsewhere($disk, $path, $excludeLinkId)) {
            return;
        }

        if ($this->isGalleryPathReferencedElsewhere($disk, $path, $excludeLinkId)) {
            return;
        }

        $this->heroImageService->deletePath($disk, $path);
    }

    /**
     * @param  array<string, mixed>  $heroFields
     * @return array{
     *     created: list<array{disk?: string|null, path?: string|null}>,
     *     removed: list<array{disk?: string|null, path?: string|null}>
     * }
     */
    public function applyHero(
        MarketingCampaignLink $link,
        array $heroFields,
        ?UploadedFile $heroUpload,
        bool $deferRemovedDeletion = false,
    ): array {
        $created = [];
        $removed = [];

        $result = $this->heroImageService->apply($link, $heroFields, $heroUpload, $deferRemovedDeletion);

        try {
            $link->update(Arr::only($result, self::HERO_COLUMNS));
        } catch (\Throwable $exception) {
            if (filled($result['_new_hero_path'] ?? null)) {
                $this->heroImageService->deletePath(
                    $result['_new_hero_disk'] ?? null,
                    $result['_new_hero_path'],
                );
            }

            throw $exception;
        }

        if (filled($result['_new_hero_path'] ?? null)) {
            $created[] = [
                'disk' => $result['_new_hero_disk'] ?? null,
                'path' => $result['_new_hero_path'],
            ];
        }

        $removedDisk = $result['_removed_hero_disk'] ?? $result['_previous_hero_disk'] ?? null;
        $removedPath = $result['_removed_hero_path'] ?? $result['_previous_hero_path'] ?? null;

        if (
            filled($removedPath)
            && ($removedPath !== $link->hero_image_path || $removedDisk !== $link->hero_image_disk)
        ) {
            if ($deferRemovedDeletion) {
                $removed[] = ['disk' => $removedDisk, 'path' => $removedPath];
            } else {
                $this->deletePathIfUnreferenced($removedDisk, $removedPath, $link->id);
            }
        }

        return [
            'created' => $created,
            'removed' => $removed,
        ];
    }

    private function isHeroPathReferencedElsewhere(?string $disk, string $path, int $excludeLinkId): bool
    {
        return MarketingCampaignLink::query()
            ->whereKeyNot($excludeLinkId)
            ->where('hero_image_path', $path)
            ->when(
                filled($disk),
                fn ($query) => $query->where('hero_image_disk', $disk),
            )
            ->exists();
    }

    private function isGalleryPathReferencedElsewhere(?string $disk, string $path, int $excludeLinkId): bool
    {
        return MarketingCampaignLinkImage::query()
            ->where('path', $path)
            ->where('marketing_campaign_link_id', '!=', $excludeLinkId)
            ->when(
                filled($disk),
                fn ($query) => $query->where('disk', $disk),
            )
            ->exists();
    }
}
