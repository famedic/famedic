<?php

use App\Models\LaboratoryStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_checkout_drafts', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_checkout_drafts', 'selected_laboratory_store_id')) {
                $table->foreignIdFor(LaboratoryStore::class, 'selected_laboratory_store_id')
                    ->nullable()
                    ->after('checkout_step')
                    ->constrained('laboratory_stores')
                    ->nullOnDelete();

                $table->timestamp('selected_laboratory_store_validated_at')
                    ->nullable()
                    ->after('selected_laboratory_store_id');

                $table->string('selected_laboratory_store_cart_hash', 64)
                    ->nullable()
                    ->after('selected_laboratory_store_validated_at');
            }
        });

        Schema::table('laboratory_purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_purchases', 'preferred_laboratory_store_id')) {
                $table->foreignIdFor(LaboratoryStore::class, 'preferred_laboratory_store_id')
                    ->nullable()
                    ->after('customer_id')
                    ->constrained('laboratory_stores')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_purchases', 'preferred_laboratory_store_id')) {
                $table->dropConstrainedForeignId('preferred_laboratory_store_id');
            }
        });

        Schema::table('laboratory_checkout_drafts', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_checkout_drafts', 'selected_laboratory_store_id')) {
                $table->dropConstrainedForeignId('selected_laboratory_store_id');
                $table->dropColumn([
                    'selected_laboratory_store_validated_at',
                    'selected_laboratory_store_cart_hash',
                ]);
            }
        });
    }
};
