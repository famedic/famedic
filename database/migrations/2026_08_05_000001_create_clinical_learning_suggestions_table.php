<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_learning_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->string('type', 32); // medication | laboratory
            $table->string('detected_text');
            $table->string('confirmed_text');
            $table->string('confirmed_catalog_id')->nullable();
            $table->string('action', 32)->default('corrected'); // corrected | confirmed | ignored
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['type', 'detected_text']);
            $table->index(['type', 'confirmed_text']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_learning_suggestions');
    }
};
