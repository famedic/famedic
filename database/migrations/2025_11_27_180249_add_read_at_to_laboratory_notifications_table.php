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
        Schema::table('laboratory_notifications', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->comment('Cuando se marca como leída');
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_notifications', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
