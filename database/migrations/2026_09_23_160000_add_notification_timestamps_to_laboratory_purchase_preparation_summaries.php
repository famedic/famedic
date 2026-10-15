<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_purchase_preparation_summaries', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('invalidated_at');
            $table->timestamp('notification_email_queued_at')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_purchase_preparation_summaries', function (Blueprint $table) {
            $table->dropColumn([
                'notified_at',
                'notification_email_queued_at',
            ]);
        });
    }
};
