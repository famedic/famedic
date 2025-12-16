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
        Schema::table('laboratory_notifications', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('status');
            $table->timestamp('email_attempted_at')->nullable()->after('email_sent_at');
            $table->foreignId('email_recipient_id')->nullable()->after('email_attempted_at');
            $table->string('email_recipient_email')->nullable()->after('email_recipient_id');
            $table->text('email_error')->nullable()->after('email_recipient_email');
            $table->text('notes')->nullable()->after('email_error');
            $table->integer('resend_count')->default(0)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_notifications', function (Blueprint $table) {
            $table->dropColumn([
                'email_sent_at',
                'email_attempted_at',
                'email_recipient_id',
                'email_recipient_email',
                'email_error',
                'notes',
                'resend_count'
            ]);
        });
    }
};
