<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende otp_codes para auth Akubica.
 *
 * Idempotente ante drift de producción/staging (columnas/FKs/índices ya presentes).
 * No borra columnas drift ajenas (p. ej. challenge_id) ni altera purpose a nullable
 * si ya existe una definición compatible (varchar ≤32).
 *
 * Propiedad inequívoca vs legacy (sin registro persistente por ejecución):
 * - Pueden preexistir: purpose, email, challenge_id, índice
 *   otp_codes_user_purpose_challenge_status, FKs o ausencia de FKs en
 *   user_id / laboratory_purchase_id, y en entornos parciales incluso
 *   payload / max_attempts / used_at / ip_address / user_agent /
 *   otp_codes_email_purpose_status_index.
 * - Esta migración puede añadir: columnas Akubica faltantes, el índice
 *   email/purpose/status y FKs cascade si faltaban — pero down() no puede
 *   demostrar autoría de forma fiable entre migrate y rollback separados.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('otp_codes')) {
            return;
        }

        $this->dropForeignIfExists('otp_codes', 'user_id');
        $this->dropForeignIfExists('otp_codes', 'laboratory_purchase_id');

        $this->ensureNullableUnsignedBigInt('otp_codes', 'user_id');
        $this->ensureNullableUnsignedBigInt('otp_codes', 'laboratory_purchase_id');

        if (! Schema::hasColumn('otp_codes', 'email')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->string('email')->nullable()->after('laboratory_purchase_id');
            });
        } else {
            $this->assertStringColumnCompatible('otp_codes', 'email', 255, allowNull: true, allowNotNull: true);
        }

        if (! Schema::hasColumn('otp_codes', 'purpose')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->string('purpose', 32)->nullable()->after('email');
            });
        } else {
            // Producción puede tener purpose NOT NULL DEFAULT 'lab_results' — compatible
            // (más estricto). No forzamos nullable para no reescribir datos.
            $this->assertStringColumnCompatible('otp_codes', 'purpose', 32, allowNull: true, allowNotNull: true);
        }

        if (! Schema::hasColumn('otp_codes', 'payload')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $after = Schema::hasColumn('otp_codes', 'purpose') ? 'purpose' : 'email';
                $table->json('payload')->nullable()->after($after);
            });
        }

        if (! Schema::hasColumn('otp_codes', 'max_attempts')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->unsignedTinyInteger('max_attempts')->default(5)->after('attempts');
            });
        }

        if (! Schema::hasColumn('otp_codes', 'used_at')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->timestamp('used_at')->nullable()->after('verified_at');
            });
        }

        if (! Schema::hasColumn('otp_codes', 'ip_address')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $after = Schema::hasColumn('otp_codes', 'used_at') ? 'used_at' : 'verified_at';
                $table->string('ip_address', 45)->nullable()->after($after);
            });
        }

        if (! Schema::hasColumn('otp_codes', 'user_agent')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $after = Schema::hasColumn('otp_codes', 'ip_address') ? 'ip_address' : 'verified_at';
                $table->text('user_agent')->nullable()->after($after);
            });
        }

        if (! $this->hasIndexNamed('otp_codes', 'otp_codes_email_purpose_status_index')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->index(['email', 'purpose', 'status'], 'otp_codes_email_purpose_status_index');
            });
        }

        $this->ensureForeignKey('otp_codes', 'user_id', 'users', 'id');
        $this->ensureForeignKey('otp_codes', 'laboratory_purchase_id', 'laboratory_purchases', 'id');
    }

    /**
     * Conservative no-op rollback (opción B).
     *
     * Bajo drift, purpose/email/challenge_id e índices legacy pueden preexistir.
     * Sin un registro durable de qué creó una ejecución concreta de up(), eliminar
     * columnas/índices/FKs sería destructivo e irreversible. Las ampliaciones de
     * up() se conservan deliberadamente en rollback para preservar datos.
     *
     * No elimina: purpose, email, challenge_id, payload, max_attempts, used_at,
     * ip_address, user_agent, otp_codes_email_purpose_status_index,
     * otp_codes_user_purpose_challenge_status, ni FKs de user_id /
     * laboratory_purchase_id. Tampoco revierte nullability de esas FKs.
     */
    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }

    private function ensureNullableUnsignedBigInt(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $meta = $this->columnMeta($table, $column);
        if ($meta === null) {
            return;
        }

        $nullable = strtoupper((string) ($meta->Null ?? '')) === 'YES';
        if ($nullable) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NULL");
        } else {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->unsignedBigInteger($column)->nullable()->change();
            });
        }
    }

    private function assertStringColumnCompatible(
        string $table,
        string $column,
        int $maxLength,
        bool $allowNull = true,
        bool $allowNotNull = false,
    ): void {
        $meta = $this->columnMeta($table, $column);
        if ($meta === null) {
            throw new RuntimeException("Column {$table}.{$column} missing during compatibility check.");
        }

        $type = strtolower((string) ($meta->Type ?? ''));
        if (! str_starts_with($type, 'varchar') && ! str_starts_with($type, 'char')) {
            throw new RuntimeException(
                "Incompatible {$table}.{$column}: expected string/varchar, got {$type}."
            );
        }

        if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m) === 1) {
            $len = (int) $m[1];
            if ($len > $maxLength && $column === 'purpose') {
                throw new RuntimeException(
                    "Incompatible {$table}.{$column}: length {$len} exceeds expected {$maxLength}."
                );
            }
        }

        $nullable = strtoupper((string) ($meta->Null ?? '')) === 'YES';
        // Fail only when a nullable column is required and NOT NULL is not permitted.
        // When allowNotNull=true, an existing NOT NULL definition is accepted as compatible.
        if (! $nullable && ! $allowNotNull && $allowNull) {
            throw new RuntimeException(
                "Incompatible {$table}.{$column}: expected nullable, got NOT NULL."
            );
        }
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        $fk = $this->foreignKeyNameForColumn($table, $column);
        if ($fk === null) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($fk) {
            $blueprint->dropForeign($fk);
        });
    }

    private function ensureForeignKey(string $table, string $column, string $refTable, string $refColumn): void
    {
        if (! Schema::hasColumn($table, $column) || ! Schema::hasTable($refTable)) {
            return;
        }

        if ($this->foreignKeyNameForColumn($table, $column) !== null) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $refTable, $refColumn) {
            $blueprint->foreign($column)->references($refColumn)->on($refTable)->cascadeOnDelete();
        });
    }

    private function foreignKeyNameForColumn(string $table, string $column): ?string
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite testing: skip FK introspection; dropForeign/add guarded by try is avoided.
            return null;
        }

        $rows = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );

        if ($rows === []) {
            return null;
        }

        return (string) $rows[0]->CONSTRAINT_NAME;
    }

    private function hasIndexNamed(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? '') === $indexName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return object{Type?: string, Null?: string, Default?: mixed}|null
     */
    private function columnMeta(string $table, string $column): ?object
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $quotedTable = str_replace("'", "''", $table);
            $cols = DB::select("PRAGMA table_info('{$quotedTable}')");
            foreach ($cols as $col) {
                if (($col->name ?? '') === $column) {
                    return (object) [
                        'Type' => (string) ($col->type ?? ''),
                        'Null' => ((int) ($col->notnull ?? 0) === 0) ? 'YES' : 'NO',
                        'Default' => $col->dflt_value ?? null,
                    ];
                }
            }

            return null;
        }

        $rows = DB::select(
            'SELECT COLUMN_TYPE AS `Type`, IS_NULLABLE AS `Null`, COLUMN_DEFAULT AS `Default`
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1',
            [$table, $column]
        );

        return $rows[0] ?? null;
    }
};
