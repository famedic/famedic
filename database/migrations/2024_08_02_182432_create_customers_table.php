<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('medical_attention_identifier')->nullable()->unique();
            $table->dateTime('medical_attention_subscription_expires_at')->nullable();
            $table->string('stripe_id')->nullable()->index();
            $table->morphs('customerable');
            $table->foreignIdFor(User::class)->nullable()->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
