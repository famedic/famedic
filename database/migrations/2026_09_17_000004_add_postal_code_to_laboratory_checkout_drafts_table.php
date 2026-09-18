<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_checkout_drafts', function (Blueprint $table) {
            $table->string('postal_code', 5)
                ->nullable()
                ->after('checkout_step')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_checkout_drafts', function (Blueprint $table) {
            $table->dropIndex(['postal_code']);
            $table->dropColumn('postal_code');
        });
    }
};
