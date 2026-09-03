<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_stores', function (Blueprint $table) {
            $table->index(['brand', 'is_active', 'municipality'], 'lst_brand_active_municipality_idx');
            $table->index(['brand', 'is_active', 'postal_code'], 'lst_brand_active_postal_code_idx');
            $table->index(['brand', 'is_active', 'state', 'municipality'], 'lst_brand_active_state_municipality_idx');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_stores', function (Blueprint $table) {
            $table->dropIndex('lst_brand_active_municipality_idx');
            $table->dropIndex('lst_brand_active_postal_code_idx');
            $table->dropIndex('lst_brand_active_state_municipality_idx');
        });
    }
};
