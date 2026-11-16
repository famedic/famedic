<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SMS-3 lote 20261115C: otp_delivery_operations.correlation_id was created as
 * uuid (CHAR(36) on MySQL) while the contractual X-Correlation-ID accepts any
 * value matching /^[A-Za-z0-9._-]+$/ with 8..128 characters
 * (App\Support\Api\V1\AkubicaCorrelationId::MIN_LENGTH / MAX_LENGTH).
 * Valid non-UUID ids longer than 36 chars failed the insert with SQLSTATE 22001.
 *
 * Additive change: widen to VARCHAR(128), keep NOT NULL. The column has no
 * index or foreign key, so none is affected. The table contract in
 * 2026_07_28_120000_create_otp_delivery_operations_table.php already accepts
 * 'string'/'varchar' for correlation_id, so it stays consistent.
 *
 * Same root cause, second column: otp_movement_events.movement_key is
 * VARCHAR(128) and is written as 'corr:' + request correlation id
 * (OtpMovementRecorder::resolveMovementKey), i.e. up to 5 + 128 = 133 chars.
 * Ids of 124..128 chars made the movement insert fail (swallowed by the
 * recorder). Widened to VARCHAR(160), NOT NULL; its index is retained.
 */
return new class extends Migration
{
    /** Hardcoded on purpose: AkubicaCorrelationId::MAX_LENGTH at time of writing. */
    private const CORRELATION_ID_MAX_LENGTH = 128;

    /** 'corr:' prefix (5) + CORRELATION_ID_MAX_LENGTH (128) = 133, rounded up with headroom. */
    private const MOVEMENT_KEY_LENGTH = 160;

    public function up(): void
    {
        if ($this->needsWidening('otp_delivery_operations', 'correlation_id', self::CORRELATION_ID_MAX_LENGTH)) {
            Schema::table('otp_delivery_operations', function (Blueprint $table): void {
                $table->string('correlation_id', self::CORRELATION_ID_MAX_LENGTH)->nullable(false)->change();
            });
        }

        if ($this->needsWidening('otp_movement_events', 'movement_key', self::MOVEMENT_KEY_LENGTH)) {
            Schema::table('otp_movement_events', function (Blueprint $table): void {
                $table->string('movement_key', self::MOVEMENT_KEY_LENGTH)->nullable(false)->change();
            });
        }
    }

    /**
     * Intentionally non-destructive: narrowing back to CHAR(36) could truncate
     * or reject rows holding valid non-UUID correlation ids. Keep the wider column.
     */
    public function down(): void
    {
        // No-op by design.
    }

    /** Only widens (never narrows); skips missing tables/columns and already-wide varchar columns. */
    private function needsWidening(string $table, string $columnName, int $length): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $columnName)) {
            return false;
        }

        foreach (Schema::getColumns($table) as $column) {
            if (($column['name'] ?? null) !== $columnName) {
                continue;
            }

            $typeName = strtolower((string) ($column['type_name'] ?? ''));
            $current = preg_match('/\((\d+)\)/', (string) ($column['type'] ?? ''), $m) === 1 ? (int) $m[1] : null;

            if (in_array($typeName, ['text', 'mediumtext', 'longtext'], true)) {
                return false;
            }

            return ! ($typeName === 'varchar' && $current !== null && $current >= $length);
        }

        return false;
    }
};
