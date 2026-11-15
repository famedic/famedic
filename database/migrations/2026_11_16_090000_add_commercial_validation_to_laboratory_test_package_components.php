<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * RC2 B02: la vinculación de un estudio de catálogo a un paquete no equivale a
 * aprobación comercial. Las filas existentes quedan en pending_commercial_validation
 * hasta que FAMEDIC Comercial apruebe la equivalencia (validation_reference).
 * No modifica precios, feature_list ni la composición existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_test_package_components', function (Blueprint $table): void {
            $table->enum('validation_status', [
                'pending_commercial_validation',
                'validated',
                'rejected',
            ])->default('pending_commercial_validation')->after('sort_order');
            $table->string('validation_reference', 255)->nullable()->after('validation_status');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_test_package_components', function (Blueprint $table): void {
            $table->dropColumn(['validation_reference', 'validation_status']);
        });
    }
};
