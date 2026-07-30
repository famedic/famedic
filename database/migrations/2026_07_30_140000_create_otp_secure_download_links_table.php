<?php

use App\Support\Migrations\MinimumTableContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otp_secure_download_links')) {
            MinimumTableContract::assertCompatible('otp_secure_download_links', $this->contract());

            return;
        }

        Schema::create('otp_secure_download_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->foreignId('otp_step_up_grant_id')
                ->constrained('otp_step_up_grants')
                ->cascadeOnDelete();
            $table->string('purpose', 64)->index();
            $table->string('resource_type', 64);
            $table->unsignedBigInteger('resource_id');
            $table->timestamp('expires_at')->index();
            $table->unsignedSmallInteger('max_opens')->default(1);
            $table->unsignedSmallInteger('open_count')->default(0);
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id'], 'otp_secure_download_links_resource_index');
            $table->index(
                ['user_id', 'purpose', 'resource_type', 'resource_id'],
                'otp_secure_download_links_lookup_index'
            );
        });
    }

    /**
     * Conservative no-op rollback under schema drift (OTP P0-A pattern).
     * Staging rollback: DROP TABLE manually after confirming no prod dependency,
     * then delete the migrations row — do not rely on migrate:rollback alone.
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
                'public_id' => ['types' => ['uuid', 'string', 'varchar', 'char'], 'nullable' => false],
                'token_hash' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'user_id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'personal_access_token_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'otp_step_up_grant_id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'purpose' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'resource_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'resource_id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'expires_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
                'max_opens' => ['types' => ['smallint', 'integer', 'tinyint'], 'nullable' => false],
                'open_count' => ['types' => ['smallint', 'integer', 'tinyint'], 'nullable' => false],
                'consumed_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => true],
                'revoked_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => true],
                'last_opened_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => true],
            ],
            'indexes' => [
                ['name' => 'otp_secure_download_links_public_id_unique', 'columns' => ['public_id'], 'unique' => true],
                ['name' => 'otp_secure_download_links_token_hash_unique', 'columns' => ['token_hash'], 'unique' => true],
                ['name' => 'otp_secure_download_links_resource_index', 'columns' => ['resource_type', 'resource_id']],
            ],
            'foreign_keys' => [
                [
                    'columns' => ['user_id'],
                    'referenced_table' => 'users',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['cascade'],
                ],
                [
                    'columns' => ['otp_step_up_grant_id'],
                    'referenced_table' => 'otp_step_up_grants',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['cascade'],
                ],
            ],
        ];
    }
};
