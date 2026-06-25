<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->foreignKeyExists('otp_codes', 'laboratory_purchase_id')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->dropForeign(['laboratory_purchase_id']);
            });
        }

        Schema::table('otp_codes', function (Blueprint $table) {
            if (Schema::hasColumn('otp_codes', 'laboratory_purchase_id')) {
                $table->unsignedBigInteger('laboratory_purchase_id')->nullable()->change();
            }

            if (! Schema::hasColumn('otp_codes', 'purpose')) {
                $table->string('purpose', 32)->default('lab_results')->after('user_id');
            }

            if (! Schema::hasColumn('otp_codes', 'challenge_id')) {
                $table->uuid('challenge_id')->nullable()->after('purpose');
            }
        });

        if (! Schema::hasIndex('otp_codes', 'otp_codes_user_purpose_challenge_status')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->index(
                    ['user_id', 'purpose', 'challenge_id', 'status'],
                    'otp_codes_user_purpose_challenge_status'
                );
            });
        }

        if (! $this->foreignKeyExists('otp_codes', 'laboratory_purchase_id')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->foreign('laboratory_purchase_id')
                    ->references('id')
                    ->on('laboratory_purchases')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('otp_codes', 'laboratory_purchase_id')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->dropForeign(['laboratory_purchase_id']);
            });
        }

        if (Schema::hasIndex('otp_codes', 'otp_codes_user_purpose_challenge_status')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->dropIndex('otp_codes_user_purpose_challenge_status');
            });
        }

        $columns = array_values(array_filter(
            ['purpose', 'challenge_id'],
            fn (string $column) => Schema::hasColumn('otp_codes', $column)
        ));

        if ($columns !== []) {
            Schema::table('otp_codes', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }

        if (Schema::hasColumn('otp_codes', 'laboratory_purchase_id')) {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->unsignedBigInteger('laboratory_purchase_id')->nullable(false)->change();
            });

            if (! $this->foreignKeyExists('otp_codes', 'laboratory_purchase_id')) {
                Schema::table('otp_codes', function (Blueprint $table) {
                    $table->foreign('laboratory_purchase_id')
                        ->references('id')
                        ->on('laboratory_purchases')
                        ->cascadeOnDelete();
                });
            }
        }
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $result = DB::selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );

        return (int) $result->count > 0;
    }
};
