<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLQ-A1-01: historical reference to the original laboratory_tests.id of each order product.
 *
 * Nullable: rows created before this column (or by the legacy importer) have no verifiable source until the
 * backfill command classifies them. Deliberately WITHOUT a foreign key: laboratory_tests rows are soft-deleted
 * and also force-deleted (e.g. Rc2PriceFixturesCommand), so ON DELETE CASCADE would delete order history,
 * ON DELETE SET NULL would erase the reference, and RESTRICT would change existing catalog-deletion behavior.
 * The value is a historical snapshot, not a live relation. Indexed for lookups and the backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_purchase_items', function (Blueprint $table) {
            $table->unsignedBigInteger('laboratory_test_id')->nullable()->after('gda_id');
            $table->index('laboratory_test_id', 'lab_purchase_items_lab_test_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_purchase_items', function (Blueprint $table) {
            $table->dropIndex('lab_purchase_items_lab_test_id_idx');
            $table->dropColumn('laboratory_test_id');
        });
    }
};
