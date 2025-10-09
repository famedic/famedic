<?php

use App\Enums\MedicalSubscriptionType;
use App\Models\MedicalAttentionSubscription;
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
        Schema::table('medical_attention_subscriptions', function (Blueprint $table) {
            $table->enum('type', array_map(fn($case) => $case->value, MedicalSubscriptionType::cases()))->nullable()->after('price_cents');
            $table->foreignIdFor(MedicalAttentionSubscription::class, 'parent_subscription_id')->nullable()->after('type')->constrained('medical_attention_subscriptions');
            $table->timestamp('synced_with_murguia_at')->nullable()->after('parent_subscription_id');
        });
    }

};
