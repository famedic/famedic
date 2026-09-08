<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @see tests/Unit/Database/OtpMonitorMigrationIdentifierLengthTest.php
     */
    public const TABLE = 'otp_sms_delivery_receipts';

    public const FK_OPERATION = 'otp_dlr_receipts_op_fk';

    public const IDX_PROVIDER_MESSAGE = 'otp_dlr_receipts_msg_idx';

    public const IDX_OPERATION = 'otp_dlr_receipts_op_idx';

    public const IDX_STATUS = 'otp_dlr_receipts_status_idx';

    public const UQ_IDEMPOTENCY = 'otp_dlr_receipts_idem_uq';

    public const IDX_RECEIVED = 'otp_dlr_receipts_recv_idx';

    public const IDX_DELIVERY_MSG = 'otp_dlr_ops_msg_idx';

    public const IDX_DELIVERY_SMS_STATUS = 'otp_dlr_ops_sms_st_idx';

    public function up(): void
    {
        if (Schema::hasTable('otp_delivery_operations') && ! Schema::hasColumn('otp_delivery_operations', 'provider_message_id')) {
            Schema::table('otp_delivery_operations', function (Blueprint $table): void {
                $table->string('provider_message_id', 64)->nullable()->index(self::IDX_DELIVERY_MSG);
                $table->string('sms_delivery_status', 32)->nullable()->index(self::IDX_DELIVERY_SMS_STATUS);
                $table->timestamp('sms_delivery_status_at')->nullable();
                $table->string('sms_failure_code', 32)->nullable();
                $table->string('sms_failure_reason', 255)->nullable();
            });
        }

        if (! Schema::hasTable(self::TABLE)) {
            $this->createReceiptsTable();

            return;
        }

        $this->repairPartialReceiptsTable();
    }

    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }

    private function createReceiptsTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('provider_message_id', 64);
            $table->unsignedBigInteger('otp_delivery_operation_id')->nullable();
            $table->string('receipt_status', 32);
            $table->string('raw_status', 32)->nullable();
            $table->unsignedTinyInteger('status_rank')->default(0);
            $table->string('failure_code', 32)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->char('idempotency_key', 64);
            $table->timestamp('received_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('provider_message_id', self::IDX_PROVIDER_MESSAGE);
            $table->index('otp_delivery_operation_id', self::IDX_OPERATION);
            $table->index('receipt_status', self::IDX_STATUS);
            $table->unique('idempotency_key', self::UQ_IDEMPOTENCY);
            $table->index('received_at', self::IDX_RECEIVED);

            $table->foreign('otp_delivery_operation_id', self::FK_OPERATION)
                ->references('id')
                ->on('otp_delivery_operations')
                ->nullOnDelete();
        });
    }

    private function repairPartialReceiptsTable(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! $this->indexOnColumnExists(self::TABLE, 'provider_message_id')) {
                $table->index('provider_message_id', self::IDX_PROVIDER_MESSAGE);
            }
            if (! $this->indexOnColumnExists(self::TABLE, 'otp_delivery_operation_id')) {
                $table->index('otp_delivery_operation_id', self::IDX_OPERATION);
            }
            if (! $this->indexOnColumnExists(self::TABLE, 'receipt_status')) {
                $table->index('receipt_status', self::IDX_STATUS);
            }
            if (! $this->uniqueOnColumnExists(self::TABLE, 'idempotency_key')) {
                $table->unique('idempotency_key', self::UQ_IDEMPOTENCY);
            }
            if (! $this->indexOnColumnExists(self::TABLE, 'received_at')) {
                $table->index('received_at', self::IDX_RECEIVED);
            }
            if (! $this->foreignKeyOnColumnExists(self::TABLE, 'otp_delivery_operation_id')) {
                $table->foreign('otp_delivery_operation_id', self::FK_OPERATION)
                    ->references('id')
                    ->on('otp_delivery_operations')
                    ->nullOnDelete();
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
