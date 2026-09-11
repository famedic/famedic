<?php

use App\Models\Customer;
use App\Models\OdessaPreEnrollment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertBackfillHasNoConflicts();

        Schema::create('medical_attention_identifier_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 10)->unique('mair_identifier_unique');
            $table->string('purpose', 64);
            $table->string('reservable_type')->nullable();
            $table->unsignedBigInteger('reservable_id')->nullable();
            $table->string('status', 24)->default('RESERVED')->index('mair_status_idx');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamps();

            $table->index(['reservable_type', 'reservable_id'], 'mair_reservable_idx');
            $table->index('purpose', 'mair_purpose_idx');
        });

        // Exact 10-digit enforcement is kept in application code: MySQL and SQLite
        // require different regex/check syntax, and a portable fragile constraint
        // would be riskier than centralized validation plus the global unique index.
        $this->backfillCustomers();
        $this->backfillPreEnrollments();
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_attention_identifier_reservations');
    }

    private function backfillCustomers(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        $this->validCustomerIdentifiers()
            ->orderBy('id')
            ->get()
            ->each(function ($customer): void {
                $this->insertReservation(
                    identifier: (string) $customer->medical_attention_identifier,
                    purpose: 'customer_existing',
                    reservableType: Customer::class,
                    reservableId: (int) $customer->id,
                );
            });
    }

    private function backfillPreEnrollments(): void
    {
        if (! Schema::hasTable('odessa_pre_enrollments')) {
            return;
        }

        $this->validPreEnrollmentIdentifiers()
            ->orderBy('id')
            ->get()
            ->each(function ($preEnrollment): void {
                $this->insertReservation(
                    identifier: (string) $preEnrollment->medical_attention_identifier,
                    purpose: 'pre_enrollment_existing',
                    reservableType: OdessaPreEnrollment::class,
                    reservableId: (int) $preEnrollment->id,
                );
            });
    }

    private function insertReservation(string $identifier, string $purpose, ?string $reservableType, ?int $reservableId): void
    {
        if (! $this->isValidIdentifier($identifier)) {
            return;
        }

        DB::table('medical_attention_identifier_reservations')->insert([
            'identifier' => $identifier,
            'purpose' => $purpose,
            'reservable_type' => $reservableType,
            'reservable_id' => $reservableId,
            'status' => 'RESERVED',
            'reserved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertBackfillHasNoConflicts(): void
    {
        $customerDuplicates = Schema::hasTable('customers')
            ? $this->duplicateCount($this->validCustomerIdentifiers())
            : 0;
        $preEnrollmentDuplicates = Schema::hasTable('odessa_pre_enrollments')
            ? $this->duplicateCount($this->validPreEnrollmentIdentifiers())
            : 0;
        $crossDuplicates = Schema::hasTable('customers') && Schema::hasTable('odessa_pre_enrollments')
            ? $this->crossDuplicateCount()
            : 0;

        if ($customerDuplicates + $preEnrollmentDuplicates + $crossDuplicates === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'No se pudo crear la reserva global de noCredito: conflictos detectados customers=%d, pre_enrollments=%d, cruzados=%d.',
            $customerDuplicates,
            $preEnrollmentDuplicates,
            $crossDuplicates,
        ));
    }

    private function validCustomerIdentifiers()
    {
        return DB::table('customers')
            ->select(['id', 'medical_attention_identifier'])
            ->whereNotNull('medical_attention_identifier')
            ->where('medical_attention_identifier', '!=', '')
            ->whereRaw('LENGTH(medical_attention_identifier) = 10')
            ->whereRaw($this->digitsOnlySql('medical_attention_identifier'));
    }

    private function validPreEnrollmentIdentifiers()
    {
        return DB::table('odessa_pre_enrollments')
            ->select(['id', 'medical_attention_identifier'])
            ->whereNotNull('medical_attention_identifier')
            ->where('medical_attention_identifier', '!=', '')
            ->whereRaw('LENGTH(medical_attention_identifier) = 10')
            ->whereRaw($this->digitsOnlySql('medical_attention_identifier'));
    }

    private function duplicateCount($query): int
    {
        return DB::query()
            ->fromSub(
                $query
                    ->cloneWithout(['columns', 'orders'])
                    ->select('medical_attention_identifier')
                    ->groupBy('medical_attention_identifier')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_identifiers',
            )
            ->count();
    }

    private function crossDuplicateCount(): int
    {
        return DB::table('customers')
            ->whereNotNull('customers.medical_attention_identifier')
            ->where('customers.medical_attention_identifier', '!=', '')
            ->whereRaw('LENGTH(customers.medical_attention_identifier) = 10')
            ->whereRaw($this->digitsOnlySql('customers.medical_attention_identifier'))
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('odessa_pre_enrollments')
                    ->whereColumn('odessa_pre_enrollments.medical_attention_identifier', 'customers.medical_attention_identifier')
                    ->whereNotNull('odessa_pre_enrollments.medical_attention_identifier')
                    ->where('odessa_pre_enrollments.medical_attention_identifier', '!=', '')
                    ->whereRaw('LENGTH(odessa_pre_enrollments.medical_attention_identifier) = 10')
                    ->whereRaw($this->digitsOnlySql('odessa_pre_enrollments.medical_attention_identifier'));
            })
            ->distinct()
            ->count('customers.medical_attention_identifier');
    }

    private function digitsOnlySql(string $column): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "{$column} NOT GLOB '*[^0-9]*'"
            : "{$column} REGEXP '^[0-9]+$'";
    }

    private function isValidIdentifier(string $identifier): bool
    {
        return preg_match('/^\d{10}$/', $identifier) === 1;
    }
};
