<?php

namespace App\Support\Laboratory;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Regla única de frescura del PDF de resultados GDA.
 *
 * No dispara descargas ni sobrescribe archivos: solo evalúa estado.
 */
final class GdaResultsPdfStatus
{
    public const GDA_PATH_MARKER = 'results/gda-';

    public const GDA_STORED_PATH_PATTERN = 'results/gda-%d-%s.pdf';

    public const PDF_KIND_NONE = 'none';

    public const PDF_KIND_GDA = 'gda';

    public const PDF_KIND_MANUAL = 'manual';

    public const STORED_AT_SOURCE_LAST_MODIFIED = 'storage_last_modified';

    public const STORED_AT_SOURCE_FETCHED_AT = 'results_fetched_at';

    public static function isGdaManagedPath(?string $path): bool
    {
        return is_string($path) && $path !== '' && str_contains($path, self::GDA_PATH_MARKER);
    }

    public static function isManualPath(?string $path): bool
    {
        return is_string($path) && $path !== '' && ! self::isGdaManagedPath($path);
    }

    /**
     * Evalúa el PDF de una compra usando sus notificaciones de resultados.
     */
    public static function assessPurchase(LaboratoryPurchase $purchase): GdaResultsPdfAssessment
    {
        return self::assess($purchase, self::resultsNotificationsForPurchase($purchase));
    }

    /**
     * @return Collection<int, LaboratoryNotification>
     */
    public static function resultsNotificationsForPurchase(LaboratoryPurchase $purchase): Collection
    {
        return LaboratoryNotification::query()
            ->ofResultsType()
            ->where(function ($query) use ($purchase) {
                $query->where('laboratory_purchase_id', $purchase->id);

                if ($purchase->gda_order_id) {
                    $query->orWhere('gda_order_id', $purchase->gda_order_id);
                }

                if ($purchase->gda_consecutivo) {
                    $query->orWhere('gda_consecutivo', $purchase->gda_consecutivo);
                }
            })
            ->get();
    }

    /**
     * @param  iterable<int, LaboratoryNotification>  $resultsNotifications
     */
    public static function assess(?LaboratoryPurchase $purchase, iterable $resultsNotifications): GdaResultsPdfAssessment
    {
        $notifications = Collection::make($resultsNotifications)
            ->filter(fn ($notification) => $notification instanceof LaboratoryNotification)
            ->values();

        $latest = $notifications
            ->sortByDesc(fn (LaboratoryNotification $notification) => $notification->results_received_at ?? $notification->created_at)
            ->first();

        $latestResultsAt = $latest
            ? ($latest->results_received_at ?? $latest->created_at)
            : null;

        if ($latestResultsAt && ! $latestResultsAt instanceof CarbonInterface) {
            $latestResultsAt = Carbon::parse($latestResultsAt);
        }

        $availableAtGda = $latest?->hasAvailableResults() ?? false;
        $storagePath = $purchase?->results;
        $hasPdfInStorage = $purchase !== null
            && is_string($storagePath)
            && $storagePath !== ''
            && self::storageExists($storagePath);

        $isGdaManaged = $hasPdfInStorage && self::isGdaManagedPath($storagePath);
        $isManual = $hasPdfInStorage && self::isManualPath($storagePath);

        [$storedPdfAt, $storedPdfAtSource] = $hasPdfInStorage
            ? self::resolveStoredPdfAt($purchase, $notifications)
            : [null, null];

        $storedPdfTimestampUnreliable = $hasPdfInStorage && $storedPdfAt === null;

        $hasNewerResults = self::hasNewerResults(
            $availableAtGda,
            $hasPdfInStorage,
            $latestResultsAt,
            $storedPdfAt,
            $storedPdfTimestampUnreliable,
        );

        $isStale = $isManual
            ? false
            : $hasNewerResults;

        $isAutomaticOverwriteCandidate = $isStale && ! $isManual && $availableAtGda && $isGdaManaged;

        $pdfKind = match (true) {
            $isManual => self::PDF_KIND_MANUAL,
            $isGdaManaged => self::PDF_KIND_GDA,
            default => self::PDF_KIND_NONE,
        };

        $freshnessStatus = self::resolveFreshnessStatus(
            $availableAtGda,
            $hasPdfInStorage,
            $isManual,
            $isGdaManaged,
            $isStale,
        );

        return new GdaResultsPdfAssessment(
            hasPdfInStorage: $hasPdfInStorage,
            isGdaManaged: $isGdaManaged,
            isManual: $isManual,
            availableAtGda: $availableAtGda,
            hasNewerResults: $hasNewerResults,
            isStale: $isStale,
            isAutomaticOverwriteCandidate: $isAutomaticOverwriteCandidate,
            pdfKind: $pdfKind,
            freshnessStatus: $freshnessStatus,
            freshnessStatusLabel: self::freshnessStatusLabel($freshnessStatus),
            latestResultsAt: $latestResultsAt instanceof Carbon ? $latestResultsAt : ($latestResultsAt ? Carbon::parse($latestResultsAt) : null),
            storedPdfAt: $storedPdfAt,
            storedPdfAtSource: $storedPdfAtSource,
            staleLagLabel: self::formatLag($storedPdfAt, $latestResultsAt instanceof CarbonInterface ? Carbon::instance($latestResultsAt) : $latestResultsAt),
            storedPdfTimestampUnreliable: $storedPdfTimestampUnreliable,
        );
    }

    private static function hasNewerResults(
        bool $availableAtGda,
        bool $hasPdfInStorage,
        ?CarbonInterface $latestResultsAt,
        ?Carbon $storedPdfAt,
        bool $storedPdfTimestampUnreliable,
    ): bool {
        if (! $availableAtGda) {
            return false;
        }

        if (! $hasPdfInStorage) {
            return true;
        }

        if ($storedPdfTimestampUnreliable || $storedPdfAt === null || $latestResultsAt === null) {
            return false;
        }

        return $latestResultsAt->gt($storedPdfAt);
    }

    private static function resolveFreshnessStatus(
        bool $availableAtGda,
        bool $hasPdfInStorage,
        bool $isManual,
        bool $isGdaManaged,
        bool $isStale,
    ): string {
        if ($isManual) {
            return 'manual';
        }

        if ($isGdaManaged && $isStale) {
            return 'gda_stale';
        }

        if ($isGdaManaged) {
            return 'gda_current';
        }

        if ($availableAtGda && ! $hasPdfInStorage) {
            return 'gda_available';
        }

        if ($availableAtGda) {
            return 'gda_available';
        }

        return 'none';
    }

    public static function freshnessStatusLabel(string $status): string
    {
        return match ($status) {
            'manual' => 'PDF manual',
            'gda_stale' => 'PDF GDA desactualizado',
            'gda_current' => 'PDF actualizado',
            'gda_available' => 'Disponible en GDA',
            'legacy' => 'PDF legacy en BD',
            'legacy_stale' => 'PDF legacy desactualizado',
            default => 'Sin PDF',
        };
    }

    public static function pdfKindLabel(string $kind): string
    {
        return match ($kind) {
            self::PDF_KIND_GDA => 'GDA',
            self::PDF_KIND_MANUAL => 'Manual',
            'legacy' => 'Legacy',
            default => 'Sin PDF',
        };
    }

    /**
     * Fecha del PDF almacenado.
     *
     * Primario: Storage::lastModified() (momento real de escritura del objeto).
     * Respaldo: el results_fetched_at más reciente en gda_message de las notificaciones.
     *
     * No usa purchase.updated_at: ese campo cambia por status, acuse, completed_at, etc.
     *
     * @param  Collection<int, LaboratoryNotification>  $notifications
     * @return array{0: ?Carbon, 1: ?string}
     */
    private static function resolveStoredPdfAt(LaboratoryPurchase $purchase, Collection $notifications): array
    {
        try {
            $timestamp = Storage::lastModified($purchase->results);

            if (is_numeric($timestamp) && (int) $timestamp > 0) {
                return [
                    Carbon::createFromTimestamp((int) $timestamp),
                    self::STORED_AT_SOURCE_LAST_MODIFIED,
                ];
            }
        } catch (Throwable) {
            // El disco puede no exponer lastModified; se usa el respaldo de gda_message.
        }

        $fetchedAt = $notifications
            ->map(fn (LaboratoryNotification $notification) => $notification->pdfFetchedAt())
            ->filter()
            ->sort()
            ->last();

        if ($fetchedAt instanceof Carbon) {
            return [$fetchedAt, self::STORED_AT_SOURCE_FETCHED_AT];
        }

        return [null, null];
    }

    private static function storageExists(string $path): bool
    {
        try {
            return Storage::exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    private static function formatLag(?Carbon $storedPdfAt, mixed $latestResultsAt): ?string
    {
        if (! $storedPdfAt || ! $latestResultsAt instanceof CarbonInterface) {
            return null;
        }

        $latest = Carbon::instance($latestResultsAt);

        if (! $latest->gt($storedPdfAt)) {
            return null;
        }

        $totalHours = (int) $storedPdfAt->diffInHours($latest);
        $days = intdiv($totalHours, 24);
        $hours = $totalHours % 24;
        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' '.($days === 1 ? 'día' : 'días');
        }

        if ($hours > 0 || $days === 0) {
            $minutes = $days === 0 && $hours === 0
                ? max(1, (int) $storedPdfAt->diffInMinutes($latest))
                : 0;

            if ($hours > 0) {
                $parts[] = $hours.' '.($hours === 1 ? 'hora' : 'horas');
            } elseif ($minutes > 0 && $days === 0) {
                $parts[] = $minutes.' '.($minutes === 1 ? 'minuto' : 'minutos');
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
