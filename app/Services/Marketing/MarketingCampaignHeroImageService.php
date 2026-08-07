<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCampaignHeroImageSource;
use App\Models\MarketingCampaignLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketingCampaignHeroImageService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function apply(
        MarketingCampaignLink $link,
        array $data,
        ?UploadedFile $upload = null,
        bool $deferFileDeletes = false,
    ): array {
        $source = MarketingCampaignHeroImageSource::tryFrom((string) ($data['hero_image_source'] ?? 'none'))
            ?? MarketingCampaignHeroImageSource::None;

        return match ($source) {
            MarketingCampaignHeroImageSource::None => $this->clearHeroFields($link, $data, $deferFileDeletes),
            MarketingCampaignHeroImageSource::External => $this->applyExternal($link, $data, $deferFileDeletes),
            MarketingCampaignHeroImageSource::Upload => $this->applyUpload($link, $data, $upload, $deferFileDeletes),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clearHeroFields(
        MarketingCampaignLink $link,
        array $data,
        bool $deferFileDeletes,
    ): array {
        $removed = [];

        if (filled($link->hero_image_path)) {
            if ($deferFileDeletes) {
                $removed = [
                    '_removed_hero_disk' => $link->hero_image_disk,
                    '_removed_hero_path' => $link->hero_image_path,
                ];
            } else {
                $this->deleteStoredFile($link);
            }
        }

        return array_merge($data, [
            'hero_image_source' => MarketingCampaignHeroImageSource::None->value,
            'hero_image_disk' => null,
            'hero_image_path' => null,
            'hero_image_url' => null,
        ], $removed);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyExternal(
        MarketingCampaignLink $link,
        array $data,
        bool $deferFileDeletes,
    ): array {
        $url = trim((string) ($data['hero_image_url'] ?? ''));
        $this->assertSafeExternalUrl($url);

        $removed = [];

        if (filled($link->hero_image_path)) {
            if ($deferFileDeletes) {
                $removed = [
                    '_removed_hero_disk' => $link->hero_image_disk,
                    '_removed_hero_path' => $link->hero_image_path,
                ];
            } else {
                $this->deleteStoredFile($link);
            }
        }

        return array_merge($data, [
            'hero_image_source' => MarketingCampaignHeroImageSource::External->value,
            'hero_image_disk' => null,
            'hero_image_path' => null,
            'hero_image_url' => $url,
        ], $removed);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyUpload(
        MarketingCampaignLink $link,
        array $data,
        ?UploadedFile $upload,
        bool $deferFileDeletes,
    ): array
    {
        if ($upload === null) {
            // Conservar imagen existente en update sin nuevo archivo.
            if (filled($link->hero_image_path)) {
                return array_merge($data, [
                    'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
                    'hero_image_disk' => $link->hero_image_disk ?: config('filesystems.default'),
                    'hero_image_path' => $link->hero_image_path,
                    'hero_image_url' => null,
                ]);
            }

            throw ValidationException::withMessages([
                'hero_image' => 'Debes subir una imagen para la fuente upload.',
            ]);
        }

        $disk = (string) config('filesystems.default', 'local');
        $directory = sprintf(
            'marketing-campaigns/%d/links/%d',
            (int) $link->marketing_campaign_id,
            (int) $link->id,
        );

        $extension = strtolower($upload->getClientOriginalExtension() ?: 'jpg');
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $upload->storeAs($directory, $filename, $disk);

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'hero_image' => 'No se pudo almacenar la imagen.',
            ]);
        }

        $previousDisk = $link->hero_image_disk;
        $previousPath = $link->hero_image_path;

        $payload = array_merge($data, [
            'hero_image_source' => MarketingCampaignHeroImageSource::Upload->value,
            'hero_image_disk' => $disk,
            'hero_image_path' => $path,
            'hero_image_url' => null,
        ]);

        // Borrar anterior sólo después de tener el nuevo path listo (el Action
        // llama esto dentro de la misma transacción de negocio; si falla el update,
        // el caller debe limpiar el upload nuevo).
        $payload['_previous_hero_disk'] = $previousDisk;
        $payload['_previous_hero_path'] = $previousPath;
        $payload['_new_hero_disk'] = $disk;
        $payload['_new_hero_path'] = $path;

        return $payload;
    }

    public function deleteStoredFile(?MarketingCampaignLink $link): void
    {
        if (! $link || ! filled($link->hero_image_path)) {
            return;
        }

        $disk = $link->hero_image_disk ?: config('filesystems.default', 'local');

        try {
            Storage::disk($disk)->delete($link->hero_image_path);
        } catch (\Throwable) {
            // best-effort
        }
    }

    public function deletePath(?string $disk, ?string $path): void
    {
        if (! filled($path)) {
            return;
        }

        try {
            Storage::disk($disk ?: config('filesystems.default', 'local'))->delete($path);
        } catch (\Throwable) {
            // best-effort
        }
    }

    public function assertSafeExternalUrl(string $url): void
    {
        if ($url === '' || mb_strlen($url) > 1000) {
            throw ValidationException::withMessages([
                'hero_image_url' => 'La URL de imagen no es válida.',
            ]);
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw ValidationException::withMessages([
                'hero_image_url' => 'Sólo se permiten URLs HTTPS absolutas.',
            ]);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages([
                'hero_image_url' => 'La URL no puede incluir credenciales.',
            ]);
        }

        $host = strtolower((string) $parts['host']);
        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '127.0.0.1'
            || $host === '::1'
            || filter_var($host, FILTER_VALIDATE_IP)
        ) {
            $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : null;
            if ($ip && (str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.') || str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.'))) {
                throw ValidationException::withMessages([
                    'hero_image_url' => 'No se permiten hosts privados o locales.',
                ]);
            }
            if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '127.0.0.1' || $host === '::1') {
                throw ValidationException::withMessages([
                    'hero_image_url' => 'No se permiten hosts privados o locales.',
                ]);
            }
        }
    }
}
