<?php

use App\Models\Customer;
use App\Support\Migrations\MinimumTableContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('akubica_checkout_links')) {
            MinimumTableContract::assertCompatible('akubica_checkout_links', $this->contract());

            return;
        }

        Schema::create('akubica_checkout_links', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('laboratory_brand', 32);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('created_by_token_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('expires_at');
        });
    }

    /**
     * Conservative no-op rollback under schema drift.
     *
     * up() may accept a pre-existing compatible table without creating it.
     * Without a durable authorship record, dropIfExists would destroy that
     * table and its data on migrate:rollback. The table is therefore retained.
     */
    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }

    /**
     * @return array{
     *     columns: array<string, array{types: list<string>, nullable?: bool|null}>,
     *     indexes: list<array{name: string, columns: list<string>, unique?: bool}>,
     *     foreign_keys: list<array{columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_delete?: string|list<string>}>
     * }
     */
    private function contract(): array
    {
        return [
            'columns' => [
                'id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'customer_id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'token_hash' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'laboratory_brand' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'expires_at' => ['types' => ['timestamp', 'datetime'], 'nullable' => false],
                'used_at' => ['types' => ['timestamp', 'datetime'], 'nullable' => true],
                'created_by_token_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'metadata' => ['types' => ['json', 'text'], 'nullable' => true],
            ],
            'indexes' => [
                ['name' => 'akubica_checkout_links_token_hash_unique', 'columns' => ['token_hash'], 'unique' => true],
                ['name' => 'akubica_checkout_links_customer_id_index', 'columns' => ['customer_id']],
                ['name' => 'akubica_checkout_links_expires_at_index', 'columns' => ['expires_at']],
            ],
            'foreign_keys' => [
                [
                    'columns' => ['customer_id'],
                    'referenced_table' => 'customers',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['cascade'],
                ],
            ],
        ];
    }
};
