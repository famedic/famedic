<?php

use App\Models\LaboratoryPurchase;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_purchase_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(LaboratoryPurchase::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'created_by')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();

            $table->index(['laboratory_purchase_id', 'revoked_at'], 'lab_purchase_shares_purchase_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_purchase_shares');
    }
};
