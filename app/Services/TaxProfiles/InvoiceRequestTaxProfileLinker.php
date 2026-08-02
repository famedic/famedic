<?php

namespace App\Services\TaxProfiles;

use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\TaxProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auditoría / vínculo histórico invoice_request → tax_profile.
 *
 * applyUniqueMatches() es infraestructura de servicio (revalidada).
 * El comando Artisan de PF-1B.1 permanece en modo solo auditoría.
 */
class InvoiceRequestTaxProfileLinker
{
    public const CLASS_UNIQUE = 'unique';

    public const CLASS_AMBIGUOUS = 'ambiguous';

    public const CLASS_NONE = 'none';

    public const CLASS_UNRESOLVED_OWNER = 'unresolved_owner';

    public const CLASS_ALREADY_LINKED = 'already_linked';

    /**
     * @return array{
     *     invoice_request_id: int,
     *     classification: string,
     *     customer_id: int|null,
     *     candidate_ids: list<int>,
     *     matched_tax_profile_id: int|null
     * }
     */
    public function classify(InvoiceRequest $invoiceRequest): array
    {
        if ($invoiceRequest->tax_profile_id !== null) {
            return [
                'invoice_request_id' => $invoiceRequest->id,
                'classification' => self::CLASS_ALREADY_LINKED,
                'customer_id' => $this->resolveCustomerId($invoiceRequest),
                'candidate_ids' => [(int) $invoiceRequest->tax_profile_id],
                'matched_tax_profile_id' => (int) $invoiceRequest->tax_profile_id,
            ];
        }

        $customerId = $this->resolveCustomerId($invoiceRequest);

        if ($customerId === null) {
            return [
                'invoice_request_id' => $invoiceRequest->id,
                'classification' => self::CLASS_UNRESOLVED_OWNER,
                'customer_id' => null,
                'candidate_ids' => [],
                'matched_tax_profile_id' => null,
            ];
        }

        $candidates = $this->findCandidates($customerId, $invoiceRequest);
        $count = $candidates->count();

        if ($count === 1) {
            $matchedId = (int) $candidates->first()->id;

            return [
                'invoice_request_id' => $invoiceRequest->id,
                'classification' => self::CLASS_UNIQUE,
                'customer_id' => $customerId,
                'candidate_ids' => [$matchedId],
                'matched_tax_profile_id' => $matchedId,
            ];
        }

        if ($count > 1) {
            return [
                'invoice_request_id' => $invoiceRequest->id,
                'classification' => self::CLASS_AMBIGUOUS,
                'customer_id' => $customerId,
                'candidate_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'matched_tax_profile_id' => null,
            ];
        }

        return [
            'invoice_request_id' => $invoiceRequest->id,
            'classification' => self::CLASS_NONE,
            'customer_id' => $customerId,
            'candidate_ids' => [],
            'matched_tax_profile_id' => null,
        ];
    }

    /**
     * Solo solicitudes sin tax_profile_id (incluye soft-deleted).
     *
     * @return Collection<int, array{
     *     invoice_request_id: int,
     *     classification: string,
     *     customer_id: int|null,
     *     candidate_ids: list<int>,
     *     matched_tax_profile_id: int|null
     * }>
     */
    public function auditUnlinked(?int $limit = null): Collection
    {
        $query = InvoiceRequest::query()
            ->withTrashed()
            ->whereNull('tax_profile_id')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn (InvoiceRequest $request) => $this->classify($request));
    }

    /**
     * Persiste solo coincidencias unique tras revalidar.
     * No modifica columnas de snapshot. No toca ambiguous/none/unresolved/already_linked.
     */
    public function applyUniqueMatches(?int $limit = null): int
    {
        $updated = 0;

        foreach ($this->auditUnlinked($limit) as $row) {
            if ($row['classification'] !== self::CLASS_UNIQUE || $row['matched_tax_profile_id'] === null) {
                continue;
            }

            $invoiceRequest = InvoiceRequest::withTrashed()->find($row['invoice_request_id']);
            if (! $invoiceRequest || $invoiceRequest->tax_profile_id !== null) {
                continue;
            }

            $snapshotBefore = $this->snapshotFingerprint($invoiceRequest);

            $reclassified = $this->classify($invoiceRequest->fresh());
            if ($reclassified['classification'] !== self::CLASS_UNIQUE) {
                continue;
            }

            $matchedId = $reclassified['matched_tax_profile_id'];
            if ($matchedId === null || $matchedId !== $row['matched_tax_profile_id']) {
                continue;
            }

            $profile = TaxProfile::withTrashed()->find($matchedId);
            if (! $profile || (int) $profile->customer_id !== (int) $reclassified['customer_id']) {
                continue;
            }

            $affected = DB::table('invoice_requests')
                ->where('id', $invoiceRequest->id)
                ->whereNull('tax_profile_id')
                ->where('name', $invoiceRequest->name)
                ->where('rfc', $invoiceRequest->rfc)
                ->where('zipcode', $invoiceRequest->zipcode)
                ->where('tax_regime', $invoiceRequest->tax_regime)
                ->where('cfdi_use', $invoiceRequest->cfdi_use)
                ->where('fiscal_certificate', $invoiceRequest->fiscal_certificate)
                ->update([
                    'tax_profile_id' => $matchedId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                continue;
            }

            $after = InvoiceRequest::withTrashed()->find($invoiceRequest->id);
            if (! $after || $this->snapshotFingerprint($after) !== $snapshotBefore) {
                // Defensa: si el snapshot divergió, no contar como éxito lógico.
                continue;
            }

            $updated += $affected;
        }

        return $updated;
    }

    public function resolveCustomerId(InvoiceRequest $invoiceRequest): ?int
    {
        $type = $invoiceRequest->invoice_requestable_type;
        $id = $invoiceRequest->invoice_requestable_id;

        if (! $type || ! $id) {
            return null;
        }

        if ($type === LaboratoryPurchase::class || $type === 'App\\Models\\LaboratoryPurchase') {
            $customerId = LaboratoryPurchase::withTrashed()->whereKey($id)->value('customer_id');

            return $customerId !== null ? (int) $customerId : null;
        }

        if ($type === OnlinePharmacyPurchase::class || $type === 'App\\Models\\OnlinePharmacyPurchase') {
            $customerId = OnlinePharmacyPurchase::withTrashed()->whereKey($id)->value('customer_id');

            return $customerId !== null ? (int) $customerId : null;
        }

        return null;
    }

    /**
     * Candidatos del propietario real, incluyendo soft-deleted.
     * Campos normalizados: RFC upper/trim; name/zip/regime trim.
     *
     * @return Collection<int, TaxProfile>
     */
    public function findCandidates(int $customerId, InvoiceRequest $invoiceRequest): Collection
    {
        $rfc = Str::upper(trim((string) $invoiceRequest->rfc));
        $name = trim((string) $invoiceRequest->name);
        $zipcode = trim((string) $invoiceRequest->zipcode);
        $taxRegime = trim((string) $invoiceRequest->tax_regime);
        $certificateBasename = $invoiceRequest->fiscal_certificate
            ? basename((string) $invoiceRequest->fiscal_certificate)
            : null;

        $profiles = TaxProfile::withTrashed()
            ->where('customer_id', $customerId)
            ->whereRaw('UPPER(TRIM(rfc)) = ?', [$rfc])
            ->whereRaw('TRIM(name) = ?', [$name])
            ->whereRaw('TRIM(zipcode) = ?', [$zipcode])
            ->whereRaw('TRIM(tax_regime) = ?', [$taxRegime])
            ->get();

        if ($certificateBasename) {
            $withBasename = $profiles->filter(function (TaxProfile $profile) use ($certificateBasename) {
                if (! filled($profile->fiscal_certificate)) {
                    return false;
                }

                return basename((string) $profile->fiscal_certificate) === $certificateBasename;
            });

            if ($withBasename->isNotEmpty()) {
                return $withBasename->values();
            }
        }

        return $profiles->values();
    }

    private function snapshotFingerprint(InvoiceRequest $invoiceRequest): string
    {
        return implode('|', [
            (string) $invoiceRequest->name,
            (string) $invoiceRequest->rfc,
            (string) $invoiceRequest->zipcode,
            (string) $invoiceRequest->tax_regime,
            (string) $invoiceRequest->cfdi_use,
            (string) $invoiceRequest->fiscal_certificate,
        ]);
    }
}
