<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->string('status', 40)->default('draft')->index();

            $table->json('document')->nullable();
            $table->json('interpretation')->nullable();
            $table->json('patient')->nullable();
            $table->json('studies')->nullable();
            $table->json('medications')->nullable();
            $table->json('validation')->nullable();
            $table->json('commercial')->nullable();
            $table->json('packages')->nullable();
            $table->json('cart_payload')->nullable();
            $table->json('quote_payload')->nullable();
            $table->json('integrations')->nullable(); // hooks for CRM, MI, analytics, timeline

            $table->text('clinical_summary')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();

            $table->unsignedInteger('studies_count')->default(0);
            $table->unsignedInteger('medications_count')->default(0);
            $table->unsignedInteger('subtotal_lab_cents')->default(0);
            $table->unsignedInteger('subtotal_pharmacy_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);

            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_orders');
    }
};
