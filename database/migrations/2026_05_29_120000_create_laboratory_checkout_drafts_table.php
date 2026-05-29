<?php

use App\Models\Address;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_checkout_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->string('laboratory_brand', 32);
            $table->foreignIdFor(Contact::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Address::class)->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method')->nullable();
            $table->foreignIdFor(Coupon::class)->nullable()->constrained()->nullOnDelete();
            $table->string('checkout_step', 32)->default('patient');
            $table->timestamps();

            $table->unique(['customer_id', 'laboratory_brand']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_checkout_drafts');
    }
};
