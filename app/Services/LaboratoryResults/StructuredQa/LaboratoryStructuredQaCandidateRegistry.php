<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use RuntimeException;

class LaboratoryStructuredQaCandidateRegistry
{
    /** @var list<LaboratoryStructuredQaCandidate>|null */
    private ?array $cached = null;

    public function flushCache(): void
    {
        $this->cached = null;
    }

    /**
     * @return list<LaboratoryStructuredQaCandidate>
     */
    public function candidates(?int $versionId = null, ?int $limit = null): array
    {
        $all = $this->loadAll();

        if ($versionId !== null) {
            $all = array_values(array_filter(
                $all,
                fn (LaboratoryStructuredQaCandidate $c) => $c->laboratoryResultVersionId === $versionId
            ));
        }

        if ($limit !== null && $limit > 0) {
            $all = array_slice($all, 0, $limit);
        }

        return $all;
    }

    public function expectedInputCount(): int
    {
        return 23;
    }

    /**
     * @return list<LaboratoryStructuredQaCandidate>
     */
    private function loadAll(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $manifestPath = (string) config('laboratory-results.structured_shadow_qa.manifest_path');

        if (is_readable($manifestPath)) {
            $payload = json_decode((string) file_get_contents($manifestPath), true);

            if (! is_array($payload) || ! isset($payload['candidates']) || ! is_array($payload['candidates'])) {
                throw new RuntimeException('Invalid structured QA manifest: '.$manifestPath);
            }

            return $this->cached = $this->mapRows($payload['candidates']);
        }

        return $this->cached = $this->buildFromFallbackSources();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<LaboratoryStructuredQaCandidate>
     */
    private function mapRows(array $rows): array
    {
        $excluded = (array) config('laboratory-results.structured_shadow_qa.excluded_pdf_patterns', []);
        $candidates = [];

        foreach ($rows as $row) {
            if ($this->isExcludedPdf((string) ($row['physical_pdf'] ?? ''), $excluded)) {
                continue;
            }

            $candidates[] = LaboratoryStructuredQaCandidate::fromManifestRow($row);
        }

        return $candidates;
    }

    /**
     * @return list<LaboratoryStructuredQaCandidate>
     */
    private function buildFromFallbackSources(): array
    {
        $sources = (array) config('laboratory-results.structured_shadow_qa.fallback_sources', []);
        $recalcPath = (string) ($sources['recalc'] ?? '');
        $validationPath = (string) ($sources['validation'] ?? '');
        $investigationPath = (string) ($sources['purchase_investigation'] ?? '');

        foreach ([$recalcPath, $validationPath] as $path) {
            if (! is_readable($path)) {
                throw new RuntimeException('Structured QA fallback source not readable: '.$path);
            }
        }

        $recalc = json_decode((string) file_get_contents($recalcPath), true);
        $validation = json_decode((string) file_get_contents($validationPath), true);
        $investigation = is_readable($investigationPath)
            ? json_decode((string) file_get_contents($investigationPath), true)
            : ['cases' => []];

        $scopeKeys = [];
        foreach (($recalc['structured_qa_candidates'] ?? []) as $entry) {
            $scopeKeys[(string) $entry['physical_pdf'].'|'.(string) $entry['analyte_code']] = $entry;
        }

        $invMap = [];
        foreach (($investigation['cases'] ?? []) as $case) {
            $invMap[(string) $case['physical_pdf'].'|'.(string) $case['analyte_code']] = $case;
        }

        $excluded = (array) config('laboratory-results.structured_shadow_qa.excluded_pdf_patterns', []);
        $rows = [];

        foreach (($validation['candidates'] ?? []) as $candidate) {
            $key = (string) $candidate['physical_pdf'].'|'.(string) $candidate['analyte_code'];

            if (! isset($scopeKeys[$key])) {
                continue;
            }

            if ($this->isExcludedPdf((string) $candidate['physical_pdf'], $excluded)) {
                continue;
            }

            /** @var array<string, mixed> $recalcEntry */
            $recalcEntry = $scopeKeys[$key];
            $inv = $invMap[$key] ?? null;
            $assocMethod = null;
            $purchaseItemAssociation = (array) ($candidate['purchase_item_association'] ?? []);

            if ($inv !== null
                && ($inv['classification'] ?? '') === 'RESOLVED'
                && ($inv['resolved_rule'] ?? '') === 'A_smalot_panel') {
                $assocMethod = 'smalot_panel_deterministic';
                $purchaseItemAssociation = [
                    'status' => 'confirmed',
                    'reason' => 'smalot_panel_deterministic',
                    'laboratory_purchase_item_id' => (int) $inv['resolved_purchase_item_id'],
                    'purchase_item_name' => (string) ($inv['resolved_purchase_item_name'] ?? ''),
                ];
            } elseif (($candidate['purchase_item_association']['status'] ?? '') === 'confirmed') {
                $assocMethod = 'result_status_confirmed';
            }

            $purchaseItemId = $recalcEntry['purchase_item_id']
                ?? $purchaseItemAssociation['laboratory_purchase_item_id']
                ?? null;

            $rows[] = array_merge($candidate, [
                'structured_readiness' => 'READY_FOR_STRUCTURED_CANDIDATE',
                'not_ready_reasons' => [],
                'purchase_item_association' => $purchaseItemAssociation,
                'purchase_item_id' => $purchaseItemId,
                'purchase_association_method' => $assocMethod,
                'is_cbc' => str_starts_with((string) $candidate['analyte_code'], 'FAMEDIC_CBC_'),
            ]);
        }

        return $this->mapRows($rows);
    }

    /**
     * @param  list<string>  $patterns
     */
    private function isExcludedPdf(string $physicalPdf, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($physicalPdf, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
