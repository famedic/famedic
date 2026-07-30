<?php

use App\Support\Migrations\MinimumTableContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otp_step_up_grants')) {
            MinimumTableContract::assertCompatible('otp_step_up_grants', $this->contract());

            return;
        }

        Schema::create('otp_step_up_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Nullable only when bind_to_sanctum_token=false or TransientToken (no DB id).
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->foreignId('otp_challenge_id')->constrained('otp_challenges')->cascadeOnDelete();
            $table->string('purpose', 64)->index();
            $table->string('resource_type', 64);
            $table->unsignedBigInteger('resource_id');
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id'], 'otp_step_up_grants_resource_index');
            $table->index(
                ['user_id', 'purpose', 'resource_type', 'resource_id', 'expires_at'],
                'otp_step_up_grants_lookup_index'
            );
        });
    }

    /**
     * Conservative no-op rollback under schema drift.
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
                'user_id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'personal_access_token_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'otp_challenge_id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'purpose' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'resource_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'resource_id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'granted_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
                'expires_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
                'revoked_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => true],
            ],
            'indexes' => [
                ['name' => 'otp_step_up_grants_public_id_unique', 'columns' => ['public_id'], 'unique' => true],
                ['name' => 'otp_step_up_grants_personal_access_token_id_index', 'columns' => ['personal_access_token_id']],
                ['name' => 'otp_step_up_grants_resource_index', 'columns' => ['resource_type', 'resource_id']],
            ],
            'foreign_keys' => [
                [
                    'columns' => ['user_id'],
                    'referenced_table' => 'users',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['cascade'],
                ],
                [
                    'columns' => ['otp_challenge_id'],
                    'referenced_table' => 'otp_challenges',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['cascade'],
                ],
            ],
        ];
    }
};
