<?php

namespace App\Support\Laboratory;

use Carbon\Carbon;

final class GdaResultsPdfAssessment
{
    public function __construct(
        public readonly bool $hasPdfInStorage,
        public readonly bool $isGdaManaged,
        public readonly bool $isManual,
        public readonly bool $availableAtGda,
        public readonly bool $hasNewerResults,
        public readonly bool $isStale,
        public readonly bool $isAutomaticOverwriteCandidate,
        public readonly string $pdfKind,
        public readonly string $freshnessStatus,
        public readonly string $freshnessStatusLabel,
        public readonly ?Carbon $latestResultsAt,
        public readonly ?Carbon $storedPdfAt,
        public readonly ?string $storedPdfAtSource,
        public readonly ?string $staleLagLabel,
        public readonly bool $storedPdfTimestampUnreliable = false,
    ) {
    }

    public function isManual(): bool
    {
        return $this->isManual;
    }

    public function isGdaCurrent(): bool
    {
        return $this->freshnessStatus === 'gda_current';
    }

    public function isGdaStale(): bool
    {
        return $this->freshnessStatus === 'gda_stale';
    }

    /**
     * True cuando el webhook/job debe sincronizar desde GDA:
     * no hay PDF todavía, o el PDF GDA es candidato a overwrite automático.
     * Nunca para PDF manual.
     */
    public function shouldAutomaticallySync(): bool
    {
        if ($this->isManual) {
            return false;
        }

        if (! $this->hasPdfInStorage && $this->availableAtGda) {
            return true;
        }

        return $this->isAutomaticOverwriteCandidate;
    }
}
