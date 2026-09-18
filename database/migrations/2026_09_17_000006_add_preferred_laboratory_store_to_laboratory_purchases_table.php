<?php

use App\Models\LaboratoryStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            $table->foreignIdFor(LaboratoryStore::class, 'preferred_laboratory_store_id')
                ->nullable()
                ->after('brand')
                ->constrained('laboratory_stores');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_laboratory_store_id');
        });
    }
};
