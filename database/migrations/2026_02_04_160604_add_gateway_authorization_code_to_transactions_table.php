<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Agregar columna nullable
            $table->string('gateway_authorization_code', 100)
                ->nullable()
                ->after('gateway_token')
                ->comment('Código de autorización del gateway de pago');
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('gateway_authorization_code');
        });
    }
};
