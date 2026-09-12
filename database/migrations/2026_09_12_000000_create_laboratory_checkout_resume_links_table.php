<?php

use App\Models\Cart;
use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('laboratory_checkout_resume_links')) {
            return;
        }

        Schema::create('laboratory_checkout_resume_links', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Cart::class)->unique()->constrained()->cascadeOnDelete();
            $table->string('laboratory_brand', 32)->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['customer_id', 'laboratory_brand']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_checkout_resume_links');
    }
};
