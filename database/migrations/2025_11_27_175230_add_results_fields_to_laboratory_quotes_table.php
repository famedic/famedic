<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('laboratory_quotes', function (Blueprint $table) {
            $table->timestamp('ready_at')->nullable()->comment('Cuando los resultados están listos');
            $table->timestamp('results_downloaded_at')->nullable()->comment('Cuando se descargan los resultados');
            
            $table->index('ready_at');
            $table->index('results_downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_quotes', function (Blueprint $table) {
            $table->dropColumn(['ready_at', 'results_downloaded_at']);
        });
    }
};
