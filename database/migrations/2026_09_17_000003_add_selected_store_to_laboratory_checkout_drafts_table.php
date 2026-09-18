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
            $table->foreignIdFor(LaboratoryStore::class, 'selected_laboratory_store_id')
                ->nullable()
                ->after('promo_validation_token')
                ->constrained('laboratory_stores')
                ->nullOnDelete();
            $table->timestamp('selected_laboratory_store_validated_at')
                ->nullable()
                ->after('selected_laboratory_store_id');
            $table->string('selected_laboratory_store_cart_hash', 64)
                ->nullable()
                ->after('selected_laboratory_store_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_checkout_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_laboratory_store_id');
            $table->dropColumn([
                'selected_laboratory_store_validated_at',
                'selected_laboratory_store_cart_hash',
            ]);
        });
    }
};
