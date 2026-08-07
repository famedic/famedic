<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketingCampaignLinkImageService
{
    public const MAX = 6;

    public function __construct(
        private readonly MarketingCampaignHeroImageService $heroImageService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<UploadedFile|null>  $uploads
     * @return list<array{disk: string, path: string}>|array{
     *     created: list<array{disk: string, path: string}>,
     *     removed: list<array{disk: string|null, path: string}>
     * }
     */
    public function sync(
        MarketingCampaignLink $link,
        array $items,
        array $uploads = [],
        bool $deferFileDeletion = false,
    ): array {
        if (count($items) > self::MAX) {
            throw ValidationException::withMessages([
                'gallery_items' => 'No puedes tener más de '.self::MAX.' imágenes en la galería.',
            ]);
        }

        $existingIds = MarketingCampaignLinkImage::query()
            ->where('marketing_campaign_link_id', $link->id)
            ->where('type', 'gallery')
            ->pluck('id')
            ->all();

        $keptIds = [];
        $createdUploadPaths = [];
        $removedUploadPaths = [];

        try {
            DB::transaction(function () use (
                $link,
                $items,
                $uploads,
                $existingIds,
                $deferFileDeletion,
                &$keptIds,
                &$createdUploadPaths,
                &$removedUploadPaths,
            ) {
                $position = 0;

                foreach ($items as $index => $item) {
                    if (! is_array($item)) {
                        throw ValidationException::withMessages([
                            'gallery_items' => 'Entrada de galería inválida.',
                        ]);
                    }

                    $kind = (string) ($item['kind'] ?? '');

                    match ($kind) {
                        'existing' => $this->touchExisting($link, $item, $position, $existingIds, $keptIds),
                        'upload' => $createdUploadPaths[] = $this->createUpload($link, $item, $uploads, $position),
                        'external' => $this->createExternal($link, $item, $position),
                        default => throw ValidationException::withMessages([
                            'gallery_items' => 'Entrada de galería inválida.',
                        ]),
                    };

                    $position++;
                }

                $toDelete = array_diff($existingIds, $keptIds);

                foreach ($toDelete as $imageId) {
                    $removedPath = $this->deleteImageRecord((int) $imageId, $deferFileDeletion);

                    if ($removedPath !== null) {
                        $removedUploadPaths[] = $removedPath;
                    }
                }
            });
        } catch (\Throwable $exception) {
            foreach ($createdUploadPaths as $pathInfo) {
                $this->heroImageService->deletePath($pathInfo['disk'] ?? null, $pathInfo['path'] ?? null);
            }

            throw $exception;
        }

        if ($deferFileDeletion) {
            return [
                'created' => $createdUploadPaths,
                'removed' => $removedUploadPaths,
            ];
        }

        return $createdUploadPaths;
    }

    /**
     * @param  list<int>  $existingIds
     * @param  list<int>  $keptIds
     * @param  array<string, mixed>  $item
     */
    private function touchExisting(
        MarketingCampaignLink $link,
        array $item,
        int $position,
        array $existingIds,
        array &$keptIds,
    ): void {
        $id = (int) ($item['id'] ?? 0);

        if ($id <= 0 || ! in_array($id, $existingIds, true)) {
            throw ValidationException::withMessages([
                'gallery_items' => 'Una imagen de galería ya no existe o no pertenece a este enlace.',
            ]);
        }

        $image = MarketingCampaignLinkImage::query()
            ->where('marketing_campaign_link_id', $link->id)
            ->where('type', 'gallery')
            ->findOrFail($id);

        $image->update([
            'position' => $position,
            'alt_text' => filled($item['alt'] ?? null) ? (string) $item['alt'] : null,
        ]);

        $keptIds[] = $id;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<UploadedFile|null>  $uploads
     * @return array{disk: string, path: string}
     */
    private function createUpload(
        MarketingCampaignLink $link,
        array $item,
        array $uploads,
        int $position,
    ): array {
        $uploadIndex = (int) ($item['upload_index'] ?? -1);
        $upload = $uploads[$uploadIndex] ?? null;

        if (! $upload instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'gallery_uploads' => 'Falta el archivo de una imagen de galería.',
            ]);
        }

        $disk = (string) config('filesystems.default', 'local');
        $directory = sprintf(
            'marketing-campaigns/%d/links/%d/gallery',
            (int) $link->marketing_campaign_id,
            (int) $link->id,
        );

        $extension = strtolower($upload->getClientOriginalExtension() ?: 'jpg');
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $upload->storeAs($directory, $filename, $disk);

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'gallery_uploads' => 'No se pudo almacenar una imagen de galería.',
            ]);
        }

        MarketingCampaignLinkImage::query()->create([
            'marketing_campaign_link_id' => $link->id,
            'type' => 'gallery',
            'source' => 'upload',
            'disk' => $disk,
            'path' => $path,
            'external_url' => null,
            'alt_text' => filled($item['alt'] ?? null) ? (string) $item['alt'] : null,
            'position' => $position,
        ]);

        return ['disk' => $disk, 'path' => $path];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createExternal(MarketingCampaignLink $link, array $item, int $position): void
    {
        $url = trim((string) ($item['url'] ?? ''));
        $this->heroImageService->assertSafeExternalUrl($url);

        MarketingCampaignLinkImage::query()->create([
            'marketing_campaign_link_id' => $link->id,
            'type' => 'gallery',
            'source' => 'external',
            'disk' => null,
            'path' => null,
            'external_url' => $url,
            'alt_text' => filled($item['alt'] ?? null) ? (string) $item['alt'] : null,
            'position' => $position,
        ]);
    }

    private function deleteImageRecord(int $imageId, bool $deferFileDeletion = false): ?array
    {
        $image = MarketingCampaignLinkImage::query()->find($imageId);

        if ($image === null) {
            return null;
        }

        $removedPath = null;

        if ($image->source === 'upload' && filled($image->path)) {
            if ($deferFileDeletion) {
                $removedPath = [
                    'disk' => $image->disk,
                    'path' => $image->path,
                ];
            } else {
                $this->heroImageService->deletePath($image->disk, $image->path);
            }
        }

        $image->delete();

        return $removedPath;
    }
}
