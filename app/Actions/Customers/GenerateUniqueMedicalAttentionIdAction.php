<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\MedicalAttentionIdentifierReservation;
use App\Models\OdessaPreEnrollment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class GenerateUniqueMedicalAttentionIdAction
{
    private const MAX_ATTEMPTS = 25;

    public function __invoke(
        ?callable $persist = null,
        string $purpose = 'customer_generated',
        ?string $reservableType = null,
        ?int $reservableId = null,
    ): int
    {
        $attempts = 0;
        $hasReservationTable = $this->reservationTableExists();

        do {
            $attempts++;
            $code = random_int(1000000000, 9999999999);
            if (! $this->isValidIdentifier((string) $code)) {
                continue;
            }

            if ($hasReservationTable) {
                if (! $this->reserve($code, $purpose, $reservableType, $reservableId)) {
                    continue;
                }
            } elseif ($this->existsInLegacyCustomerTable($code)) {
                continue;
            }

            if (! $persist) {
                return $code;
            }

            try {
                $persist($code);

                return $code;
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception) || $attempts >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        } while ($attempts < self::MAX_ATTEMPTS);

        throw new RuntimeException('No se pudo generar un noCredito único después de 25 intentos.');
    }

    public function exists(int|string $code): bool
    {
        $code = (string) $code;
        if (! $this->isValidIdentifier($code)) {
            return false;
        }

        if ($this->reservationTableExists()) {
            return MedicalAttentionIdentifierReservation::query()
                ->where('identifier', $code)
                ->exists();
        }

        return $this->existsInLegacyCustomerTable($code)
            || (Schema::hasTable('odessa_pre_enrollments')
                && OdessaPreEnrollment::query()
                    ->where('medical_attention_identifier', $code)
                    ->exists());
    }

    public function reservationTableExists(): bool
    {
        return Schema::hasTable('medical_attention_identifier_reservations');
    }

    public function reserveExistingIdentifier(
        string $identifier,
        string $purpose,
        ?string $reservableType = null,
        ?int $reservableId = null,
    ): bool {
        if (! $this->isValidIdentifier($identifier)) {
            return false;
        }

        if (! $this->reservationTableExists()) {
            return true;
        }

        try {
            DB::table('medical_attention_identifier_reservations')->insert([
                'identifier' => $identifier,
                'purpose' => $this->sanitizePurpose($purpose),
                'reservable_type' => $reservableType,
                'reservable_id' => $reservableId,
                'status' => MedicalAttentionIdentifierReservation::STATUS_RESERVED,
                'reserved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return DB::table('medical_attention_identifier_reservations')
                ->where('identifier', $identifier)
                ->where('reservable_type', $reservableType)
                ->where('reservable_id', $reservableId)
                ->exists();
        }
    }

    private function reserve(int $code, string $purpose, ?string $reservableType, ?int $reservableId): bool
    {
        $identifier = (string) $code;
        if (! $this->isValidIdentifier($identifier)) {
            return false;
        }

        try {
            DB::table('medical_attention_identifier_reservations')->insert([
                'identifier' => $identifier,
                'purpose' => $this->sanitizePurpose($purpose),
                'reservable_type' => $reservableType,
                'reservable_id' => $reservableId,
                'status' => MedicalAttentionIdentifierReservation::STATUS_RESERVED,
                'reserved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    private function existsInLegacyCustomerTable(int|string $code): bool
    {
        return Customer::query()
            ->where('medical_attention_identifier', (string) $code)
            ->exists();
    }

    private function sanitizePurpose(string $purpose): string
    {
        $purpose = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $purpose) ?: 'generated';

        return substr($purpose, 0, 64);
    }

    public function isValidIdentifier(int|string|null $identifier): bool
    {
        return is_string($identifier) || is_int($identifier)
            ? preg_match('/^\d{10}$/', (string) $identifier) === 1
            : false;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}
