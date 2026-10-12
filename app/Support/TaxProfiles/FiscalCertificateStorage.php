<?php

namespace App\Support\TaxProfiles;

use App\Models\InvoiceRequest;
use App\Models\TaxProfile;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class FiscalCertificateStorage
{
    /**
     * @return list<string>
     */
    public static function disksToProbe(): array
    {
        return array_values(array_unique(array_filter([
            'local',
            'private',
            config('filesystems.default'),
        ], fn ($disk) => is_string($disk) && $disk !== '')));
    }

    public static function exists(string $path): bool
    {
        foreach (self::disksToProbe() as $disk) {
            if (! config("filesystems.disks.{$disk}")) {
                continue;
            }

            try {
                if (Storage::disk($disk)->exists($path)) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return storage_path_exists($path);
    }

    public static function resolveDisk(string $path): ?string
    {
        foreach (self::disksToProbe() as $disk) {
            if (! config("filesystems.disks.{$disk}")) {
                continue;
            }

            try {
                if (Storage::disk($disk)->exists($path)) {
                    return $disk;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return storage_path_exists($path) ? (string) config('filesystems.default') : null;
    }

    /**
     * @return array{
     *     invoice_request: array{has_path: bool, exists: bool},
     *     tax_profile: array{has_path: bool, exists: bool},
     *     effective: array{source: ?string, has_path: bool, exists: bool, uses_fallback: bool}
     * }
     */
    public static function availabilityFor(?InvoiceRequest $invoiceRequest, ?TaxProfile $taxProfile): array
    {
        $invoiceRequestAvailability = [
            'has_path' => filled($invoiceRequest?->fiscal_certificate),
            'exists' => filled($invoiceRequest?->fiscal_certificate)
                && self::exists((string) $invoiceRequest->fiscal_certificate),
        ];

        $taxProfileAvailability = [
            'has_path' => filled($taxProfile?->fiscal_certificate),
            'exists' => filled($taxProfile?->fiscal_certificate)
                && self::exists((string) $taxProfile->fiscal_certificate),
        ];

        $effectiveSource = match (true) {
            $invoiceRequestAvailability['exists'] => 'invoice_request',
            $taxProfileAvailability['exists'] => 'tax_profile',
            default => null,
        };

        return [
            'invoice_request' => $invoiceRequestAvailability,
            'tax_profile' => $taxProfileAvailability,
            'effective' => [
                'source' => $effectiveSource,
                'has_path' => $invoiceRequestAvailability['has_path'] || $taxProfileAvailability['has_path'],
                'exists' => $effectiveSource !== null,
                'uses_fallback' => $effectiveSource === 'tax_profile'
                    && $invoiceRequestAvailability['has_path']
                    && ! $invoiceRequestAvailability['exists'],
            ],
        ];
    }
}
