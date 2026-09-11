<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activecampaign_web_activities')) {
            return;
        }

        Schema::create('activecampaign_web_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ac_contact_id');
            $table->string('path', 512);
            $table->string('title')->nullable();
            $table->string('label', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->string('source', 64)->default('activecampaign_site_tracking');
            $table->string('raw_reference_type', 64)->nullable();
            $table->string('raw_reference_id', 191)->nullable();
            $table->char('activity_hash', 64)->unique();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('ac_contact_id');
            $table->index('occurred_at');
            $table->index(['customer_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activecampaign_web_activities');
    }
};
