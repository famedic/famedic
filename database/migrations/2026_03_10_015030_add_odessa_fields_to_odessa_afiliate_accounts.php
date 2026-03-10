<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('odessa_afiliate_accounts', function (Blueprint $table) {
            $table->string('client_id')->nullable()->after('odessa_identifier');
            $table->string('empresa')->nullable()->after('client_id');
            $table->string('nombre')->nullable()->after('empresa');
            $table->string('planta_id')->nullable()->after('nombre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('odessa_afiliate_accounts', function (Blueprint $table) {

            $table->dropColumn([
                'client_id',
                'empresa',
                'nombre',
                'planta_id'
            ]);

        });
    }
};
