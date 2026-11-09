<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_consumers', function (Blueprint $t) {
            $t->id();
            $t->enum('kind', ['leo', 'native']);
            $t->json('capabilities');
            $t->boolean('enabled')->default(false);
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('api_consumer_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consumer_id')->constrained('api_consumers')->cascadeOnDelete();
            $t->boolean('enabled')->default(false);
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('api_consumer_credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consumer_id')->constrained('api_consumers')->cascadeOnDelete();
            $t->foreignId('channel_id')->constrained('api_consumer_channels')->cascadeOnDelete();
            $t->foreignId('personal_access_token_id')->nullable()->unique()->constrained('personal_access_tokens')->nullOnDelete();
            $t->boolean('enabled')->default(false);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('api_consumer_delegations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consumer_id')->constrained('api_consumers')->cascadeOnDelete();
            $t->foreignId('channel_id')->constrained('api_consumer_channels')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->json('capabilities');
            $t->boolean('enabled')->default(false);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('api_consumer_token_bindings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('personal_access_token_id')->nullable()->unique()->constrained('personal_access_tokens')->nullOnDelete();
            $t->foreignId('consumer_id')->constrained('api_consumers')->cascadeOnDelete();
            $t->foreignId('channel_id')->constrained('api_consumer_channels')->cascadeOnDelete();
            $t->foreignId('delegation_id')->constrained('api_consumer_delegations')->cascadeOnDelete();
            $t->json('capabilities');
            $t->boolean('enabled')->default(false);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['api_consumer_token_bindings', 'api_consumer_delegations', 'api_consumer_credentials', 'api_consumer_channels', 'api_consumers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
