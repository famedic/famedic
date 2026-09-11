<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_stores', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_stores', 'source')) {
                $table->string('source', 64)->nullable()->after('brand');
            }
            if (! Schema::hasColumn('laboratory_stores', 'external_key')) {
                $table->string('external_key', 160)->nullable()->after('source');
            }
            if (! Schema::hasColumn('laboratory_stores', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('external_key');
            }
            if (! Schema::hasColumn('laboratory_stores', 'source_missing_at')) {
                $table->timestamp('source_missing_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('laboratory_stores', 'street')) {
                $table->string('street')->nullable()->after('address');
            }
            if (! Schema::hasColumn('laboratory_stores', 'exterior_number')) {
                $table->string('exterior_number', 40)->nullable()->after('street');
            }
            if (! Schema::hasColumn('laboratory_stores', 'interior_number')) {
                $table->string('interior_number', 40)->nullable()->after('exterior_number');
            }
            if (! Schema::hasColumn('laboratory_stores', 'neighborhood')) {
                $table->string('neighborhood')->nullable()->after('interior_number');
            }
            if (! Schema::hasColumn('laboratory_stores', 'municipality')) {
                $table->string('municipality')->nullable()->after('neighborhood');
            }
            if (! Schema::hasColumn('laboratory_stores', 'city')) {
                $table->string('city')->nullable()->after('municipality');
            }
            if (! Schema::hasColumn('laboratory_stores', 'postal_code')) {
                $table->string('postal_code', 5)->nullable()->after('city');
            }
            if (! Schema::hasColumn('laboratory_stores', 'phone')) {
                $table->string('phone', 32)->nullable()->after('postal_code');
            }
            if (! Schema::hasColumn('laboratory_stores', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('laboratory_stores', 'longitude')) {
                $table->decimal('longitude', 11, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('laboratory_stores', 'raw_import_payload')) {
                $table->json('raw_import_payload')->nullable()->after('longitude');
            }

            $table->index(['source', 'brand', 'external_key'], 'lst_source_brand_external_idx');
            $table->index(['brand', 'is_active'], 'lst_brand_active_idx');
            $table->index(['brand', 'state'], 'lst_brand_state_idx');
            $table->index('postal_code', 'lst_postal_code_idx');
            $table->index(['latitude', 'longitude'], 'lst_coordinates_idx');
            $table->index('source_missing_at', 'lst_source_missing_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_stores', function (Blueprint $table) {
            $table->dropIndex('lst_source_brand_external_idx');
            $table->dropIndex('lst_brand_active_idx');
            $table->dropIndex('lst_brand_state_idx');
            $table->dropIndex('lst_postal_code_idx');
            $table->dropIndex('lst_coordinates_idx');
            $table->dropIndex('lst_source_missing_at_idx');

            $table->dropColumn([
                'source',
                'external_key',
                'is_active',
                'source_missing_at',
                'street',
                'exterior_number',
                'interior_number',
                'neighborhood',
                'municipality',
                'city',
                'postal_code',
                'phone',
                'latitude',
                'longitude',
                'raw_import_payload',
            ]);
        });
    }
};
