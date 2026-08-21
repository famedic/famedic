<?php

namespace App\Services\ActiveCampaign;

use App\Support\TrackingPathSanitizer;
use Carbon\CarbonImmutable;

class ActiveCampaignTrackingLogMapper
{
    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>|null
     */
    public function fromActivity(array $activity): ?array
    {
        $referenceType = (string) ($activity['reference_type'] ?? '');
        if ($referenceType !== 'TrackingLog') {
            return null;
        }

        $path = TrackingPathSanitizer::sanitizeTrackingPath($this->firstString([
            $activity['url'] ?? null,
            $activity['path'] ?? null,
            $activity['page'] ?? null,
            $activity['link'] ?? null,
            data_get($activity, 'reference.url'),
            data_get($activity, 'reference.path'),
            data_get($activity, 'reference.page'),
            data_get($activity, 'reference.link'),
            data_get($this->jsonData($activity), 'url'),
            data_get($this->jsonData($activity), 'path'),
            data_get($this->jsonData($activity), 'page'),
            data_get($this->jsonData($activity), 'link'),
            data_get($this->jsonData($activity), 'pageUrl'),
        ]));

        if ($path === null) {
            return null;
        }

        $occurredAt = $this->parseOccurredAt($activity['tstamp'] ?? null);
        if ($occurredAt === null) {
            return null;
        }

        $title = $this->cleanText($this->firstString([
            $activity['title'] ?? null,
            data_get($activity, 'reference.title'),
            data_get($this->jsonData($activity), 'title'),
            data_get($this->jsonData($activity), 'page_title'),
            data_get($this->jsonData($activity), 'pageTitle'),
        ]), 255);

        return [
            'path' => $path,
            'title' => $title,
            'label' => $this->labelForPath($path),
            'occurred_at' => $occurredAt,
            'source' => 'activecampaign_site_tracking',
            'raw_reference_type' => $referenceType,
            'raw_reference_id' => $this->cleanText($this->firstString([
                $activity['reference_id'] ?? null,
                data_get($activity, 'reference.id'),
                $activity['id'] ?? null,
            ]), 191),
        ];
    }

    public function labelForPath(string $path): string
    {
        return match (true) {
            $path === '/laboratories',
            $path === '/laboratory-brand-selection' => 'Catalogo de laboratorios',
            preg_match('#^/laboratory/[^/]+/laboratory-tests$#', $path) === 1 => 'Estudios de laboratorio',
            preg_match('#^/laboratory-tests/[^/]+$#', $path) === 1 => 'Detalle de estudio',
            preg_match('#^/laboratory/[^/]+/shopping-cart$#', $path) === 1 => 'Carrito',
            preg_match('#^/laboratory/[^/]+/checkout$#', $path) === 1 => 'Checkout',
            $path === '/user/purchases' => 'Mis compras',
            default => 'Pagina visitada',
        };
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    private function jsonData(array $activity): array
    {
        $jsonData = $activity['jsonData'] ?? $activity['jsondata'] ?? [];

        if (is_string($jsonData)) {
            $decoded = json_decode($jsonData, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($jsonData) ? $jsonData : [];
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function parseOccurredAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanText(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
