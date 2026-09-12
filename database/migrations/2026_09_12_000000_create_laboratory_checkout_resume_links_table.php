<?php

use App\Models\Cart;
use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('laboratory_checkout_resume_links')) {
            $this->ensureIndex(
                table: 'laboratory_checkout_resume_links',
                index: 'lab_resume_customer_brand_idx',
                create: fn () => Schema::table('laboratory_checkout_resume_links', function (Blueprint $table) {
                    $table->index(['customer_id', 'laboratory_brand'], 'lab_resume_customer_brand_idx');
                }),
            );

            return;
        }

        Schema::create('laboratory_checkout_resume_links', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Cart::class)->unique('lab_resume_cart_id_unique')->constrained()->cascadeOnDelete();
            $table->string('laboratory_brand', 32)->index('lab_resume_brand_idx');
            $table->string('token_hash', 64)->unique('lab_resume_token_hash_unique');
            $table->timestamp('expires_at')->index('lab_resume_expires_at_idx');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index('lab_resume_revoked_at_idx');
            $table->timestamps();

            $table->index(['customer_id', 'laboratory_brand'], 'lab_resume_customer_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_checkout_resume_links');
    }

    private function ensureIndex(string $table, string $index, callable $create): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        $create();
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};
