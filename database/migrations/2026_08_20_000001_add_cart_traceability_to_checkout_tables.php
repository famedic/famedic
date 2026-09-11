<?php

use App\Models\Cart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_attempts', 'cart_id')) {
                $table->foreignIdFor(Cart::class, 'cart_id')
                    ->nullable()
                    ->after('token_id')
                    ->constrained('carts')
                    ->nullOnDelete();

                $table->index(['cart_id', 'created_at'], 'payment_attempts_cart_created_idx');
            }
        });

        Schema::table('laboratory_purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_purchases', 'cart_id')) {
                $table->foreignIdFor(Cart::class, 'cart_id')
                    ->nullable()
                    ->after('customer_id')
                    ->constrained('carts')
                    ->nullOnDelete();

                $table->index('cart_id', 'laboratory_purchases_cart_id_idx');
            }
        });

        Schema::table('laboratory_appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_appointments', 'cart_id')) {
                $table->foreignIdFor(Cart::class, 'cart_id')
                    ->nullable()
                    ->after('customer_id')
                    ->constrained('carts')
                    ->nullOnDelete();

                $table->index('cart_id', 'laboratory_appointments_cart_id_idx');
            }
        });

        if (! Schema::hasTable('cart_events')) {
            Schema::create('cart_events', function (Blueprint $table) {
                $table->id();
                $table->foreignIdFor(Cart::class, 'cart_id')
                    ->nullable()
                    ->constrained('carts')
                    ->nullOnDelete();
                $table->string('event', 64);
                $table->string('source', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->string('idempotency_key', 160)->nullable();
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamps();

                $table->index(['cart_id', 'occurred_at'], 'cart_events_cart_occurred_idx');
                $table->index(['event', 'occurred_at'], 'cart_events_event_occurred_idx');
                $table->unique(['cart_id', 'event', 'idempotency_key'], 'cart_events_cart_event_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_events');

        Schema::table('laboratory_appointments', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_appointments', 'cart_id')) {
                $table->dropConstrainedForeignId('cart_id');
            }
        });

        Schema::table('laboratory_purchases', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_purchases', 'cart_id')) {
                $table->dropConstrainedForeignId('cart_id');
            }
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('payment_attempts', 'cart_id')) {
                $table->dropConstrainedForeignId('cart_id');
            }
        });
    }
};
