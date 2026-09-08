<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL identifier limit: 64 chars. Laravel auto-names on long table names exceed it.
     *
     * @see tests/Unit/Database/OtpMonitorMigrationIdentifierLengthTest.php
     */
    public const TABLE = 'otp_sms_delivery_receipt_applications';

    public const FK_RECEIPT = 'otp_dlr_apps_receipt_fk';

    public const FK_OPERATION = 'otp_dlr_apps_operation_fk';

    public const UQ_RECEIPT = 'otp_dlr_apps_receipt_uq';

    public const IDX_OPERATION = 'otp_dlr_apps_operation_idx';

    public const IDX_APPLIED_AT = 'otp_dlr_apps_applied_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->createTable();

            return;
        }

        $this->repairPartialTable();
    }

    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }

    private function createTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('otp_sms_delivery_receipt_id');
            $table->unsignedBigInteger('otp_delivery_operation_id');
            $table->timestamp('applied_at');

            $table->unique('otp_sms_delivery_receipt_id', self::UQ_RECEIPT);
            $table->index('otp_delivery_operation_id', self::IDX_OPERATION);
            $table->index('applied_at', self::IDX_APPLIED_AT);

            $table->foreign('otp_sms_delivery_receipt_id', self::FK_RECEIPT)
                ->references('id')
                ->on('otp_sms_delivery_receipts')
                ->cascadeOnDelete();

            $table->foreign('otp_delivery_operation_id', self::FK_OPERATION)
                ->references('id')
                ->on('otp_delivery_operations')
                ->cascadeOnDelete();
        });
    }

    /**
     * Staging recovery: CREATE TABLE may succeed while later ALTER FK fails (MySQL 1059).
     * Never drops the table or rows — only adds missing columns/constraints.
     */
    private function repairPartialTable(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'otp_sms_delivery_receipt_id')) {
                $table->unsignedBigInteger('otp_sms_delivery_receipt_id')->after('id');
            }
            if (! Schema::hasColumn(self::TABLE, 'otp_delivery_operation_id')) {
                $table->unsignedBigInteger('otp_delivery_operation_id')->after('otp_sms_delivery_receipt_id');
            }
            if (! Schema::hasColumn(self::TABLE, 'applied_at')) {
                $table->timestamp('applied_at')->after('otp_delivery_operation_id');
            }
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! $this->uniqueOnColumnExists(self::TABLE, 'otp_sms_delivery_receipt_id')) {
                $table->unique('otp_sms_delivery_receipt_id', self::UQ_RECEIPT);
            }
            if (! $this->indexOnColumnExists(self::TABLE, 'otp_delivery_operation_id')) {
                $table->index('otp_delivery_operation_id', self::IDX_OPERATION);
            }
            if (! $this->indexOnColumnExists(self::TABLE, 'applied_at')) {
                $table->index('applied_at', self::IDX_APPLIED_AT);
            }
            if (! $this->foreignKeyOnColumnExists(self::TABLE, 'otp_sms_delivery_receipt_id')) {
                $table->foreign('otp_sms_delivery_receipt_id', self::FK_RECEIPT)
                    ->references('id')
                    ->on('otp_sms_delivery_receipts')
                    ->cascadeOnDelete();
            }
            if (! $this->foreignKeyOnColumnExists(self::TABLE, 'otp_delivery_operation_id')) {
                $table->foreign('otp_delivery_operation_id', self::FK_OPERATION)
                    ->references('id')
                    ->on('otp_delivery_operations')
                    ->cascadeOnDelete();
            }
        });
    }

    private function uniqueOnColumnExists(string $table, string $column): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0
             LIMIT 1',
            [$database, $table, $column],
        );

        return $row !== null;
    }

    private function indexOnColumnExists(string $table, string $column): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 1
             LIMIT 1',
            [$database, $table, $column],
        );

        return $row !== null;
    }

    private function foreignKeyOnColumnExists(string $table, string $column): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$database, $table, $column],
        );

        return $row !== null;
    }
};
