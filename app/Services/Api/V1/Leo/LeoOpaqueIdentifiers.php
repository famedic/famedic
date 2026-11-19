<?php

namespace App\Services\Api\V1\Leo;

use App\Domain\Akubica\Benefits\OpaqueReference;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * RC2 Batch 2 · A1 (rev7 L08 LeoFamilyMember.id, L13b LeoOpaqueTaxProfileId).
 * Stable per customer and subject; random, not derived from the internal id; resolution is always scoped to the
 * customer, so a reference issued to one customer never resolves for another (unknown and foreign look the same).
 */
final class LeoOpaqueIdentifiers
{
    public const FAMILY_MEMBER = 'family_account';

    public const TAX_PROFILE = 'tax_profile';

    private const PREFIX = [self::FAMILY_MEMBER => 'fam', self::TAX_PROFILE => 'tax'];

    public function forSubject(int $customerId, string $subjectType, int $subjectId): string
    {
        $prefix = self::prefix($subjectType);
        $existing = $this->find($customerId, $subjectType, $subjectId);
        if ($existing !== null) {
            return $existing;
        }

        $reference = OpaqueReference::issue($prefix);
        try {
            DB::table('api_opaque_identifiers')->insert([
                'customer_id' => $customerId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'ref_hash' => OpaqueReference::key($reference, $prefix),
                'ref_ciphertext' => Crypt::encryptString($reference),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Concurrent first read: the unique (customer, type, subject) row already exists; reuse it.
            $existing = $this->find($customerId, $subjectType, $subjectId);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        return $reference;
    }

    /** Internal subject id for a reference issued to this customer, or null (unknown, malformed or foreign). */
    public function resolve(int $customerId, string $subjectType, string $reference): ?int
    {
        $prefix = self::prefix($subjectType);
        if (! OpaqueReference::valid($reference, $prefix)) {
            return null;
        }
        $row = DB::table('api_opaque_identifiers')
            ->where('ref_hash', hash('sha256', $reference))
            ->where('customer_id', $customerId)
            ->where('subject_type', $subjectType)
            ->first(['subject_id']);

        return $row === null ? null : (int) $row->subject_id;
    }

    private function find(int $customerId, string $subjectType, int $subjectId): ?string
    {
        $row = DB::table('api_opaque_identifiers')
            ->where('customer_id', $customerId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->first(['ref_ciphertext']);

        return $row === null ? null : Crypt::decryptString($row->ref_ciphertext);
    }

    private static function prefix(string $subjectType): string
    {
        return self::PREFIX[$subjectType] ?? throw new \InvalidArgumentException('Unknown opaque subject type');
    }
}
