<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * completed_at = primera vez que la factura quedó completa (PDF + XML).
 * Inmutable tras asignarse. Backfill estimado: LEAST(created_at, updated_at)
 * para históricos ya completos (created_at fue reiniciado en reemplazos previos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'completed_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->timestamp('completed_at')->nullable()->after('invoice_xml');
            });
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::table('invoices')
                ->whereNotNull('invoice')
                ->where('invoice', '!=', '')
                ->whereNotNull('invoice_xml')
                ->where('invoice_xml', '!=', '')
                ->whereNull('completed_at')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $created = $row->created_at;
                        $updated = $row->updated_at;
                        $completedAt = $created;

                        if ($created && $updated && $updated < $created) {
                            $completedAt = $updated;
                        }

                        DB::table('invoices')
                            ->where('id', $row->id)
                            ->update(['completed_at' => $completedAt]);
                    }
                });

            return;
        }

        // MySQL/MariaDB: usar la menor entre created_at y updated_at como estimación
        // de la primera finalización, porque CreateInvoiceAction reiniciaba created_at.
        DB::statement("
            UPDATE invoices
            SET completed_at = CASE
                WHEN created_at IS NULL AND updated_at IS NULL THEN NULL
                WHEN created_at IS NULL THEN updated_at
                WHEN updated_at IS NULL THEN created_at
                WHEN updated_at < created_at THEN updated_at
                ELSE created_at
            END
            WHERE completed_at IS NULL
              AND invoice IS NOT NULL
              AND invoice != ''
              AND invoice_xml IS NOT NULL
              AND invoice_xml != ''
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'completed_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('completed_at');
            });
        }
    }
};
