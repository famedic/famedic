<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** MySQL identifier limit is 64 chars; Laravel auto-names exceed that for long table names. */
    private const FK_RECEIPT = 'otp_sms_rcpt_apps_receipt_fk';

    private const FK_OPERATION = 'otp_sms_rcpt_apps_operation_fk';

    private const UQ_RECEIPT = 'otp_sms_rcpt_apps_receipt_uq';

    private const IDX_OPERATION = 'otp_sms_rcpt_apps_operation_idx';

    private const IDX_APPLIED_AT = 'otp_sms_rcpt_apps_applied_idx';

    public function up(): void
    {
        if (! Schema::hasTable('otp_sms_delivery_receipt_applications')) {
            Schema::create('otp_sms_delivery_receipt_applications', function (Blueprint $table): void {
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

            return;
        }

        // QA recovery: table may exist from a failed run where auto-named FKs exceeded 64 chars.
        Schema::table('otp_sms_delivery_receipt_applications', function (Blueprint $table): void {
            if (! $this->indexExists('otp_sms_delivery_receipt_applications', self::UQ_RECEIPT)) {
                $table->unique('otp_sms_delivery_receipt_id', self::UQ_RECEIPT);
            }
            if (! $this->indexExists('otp_sms_delivery_receipt_applications', self::IDX_OPERATION)) {
                $table->index('otp_delivery_operation_id', self::IDX_OPERATION);
            }
            if (! $this->indexExists('otp_sms_delivery_receipt_applications', self::IDX_APPLIED_AT)) {
                $table->index('applied_at', self::IDX_APPLIED_AT);
            }
            if (! $this->foreignKeyExists('otp_sms_delivery_receipt_applications', self::FK_RECEIPT)) {
                $table->foreign('otp_sms_delivery_receipt_id', self::FK_RECEIPT)
                    ->references('id')
                    ->on('otp_sms_delivery_receipts')
                    ->cascadeOnDelete();
            }
            if (! $this->foreignKeyExists('otp_sms_delivery_receipt_applications', self::FK_OPERATION)) {
                $table->foreign('otp_delivery_operation_id', self::FK_OPERATION)
                    ->references('id')
                    ->on('otp_delivery_operations')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$database, $table, $name, 'FOREIGN KEY'],
        );

        return $row !== null;
    }

    private function indexExists(string $table, string $name): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$database, $table, $name],
        );

        return $row !== null;
    }
};
