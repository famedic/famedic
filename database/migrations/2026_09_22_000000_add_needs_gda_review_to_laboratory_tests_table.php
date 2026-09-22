<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_tests', function (Blueprint $table) {
            $table->boolean('needs_gda_review')->default(false)->after('requires_appointment');
            $table->text('gda_review_note')->nullable()->after('needs_gda_review');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_tests', function (Blueprint $table) {
            $table->dropColumn(['needs_gda_review', 'gda_review_note']);
        });
    }
};
