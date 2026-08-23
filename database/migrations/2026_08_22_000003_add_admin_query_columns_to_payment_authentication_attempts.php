<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_authentication_attempts', function (Blueprint $table) {
            $table->string('failure_certainty', 40)->nullable()->after('failure_origin');

            $table->index('started_at', 'paa_started_at_idx');
            $table->index(['status', 'started_at'], 'paa_status_started_idx');
            $table->index(['failure_category', 'started_at'], 'paa_category_started_idx');
            $table->index(['failure_origin', 'started_at'], 'paa_origin_started_idx');
            $table->index(['failure_certainty', 'started_at'], 'paa_certainty_started_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_authentication_attempts', function (Blueprint $table) {
            $table->dropIndex('paa_started_at_idx');
            $table->dropIndex('paa_status_started_idx');
            $table->dropIndex('paa_category_started_idx');
            $table->dropIndex('paa_origin_started_idx');
            $table->dropIndex('paa_certainty_started_idx');
            $table->dropColumn('failure_certainty');
        });
    }
};
