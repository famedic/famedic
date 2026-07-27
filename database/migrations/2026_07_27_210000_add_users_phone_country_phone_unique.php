<?php

use App\Services\Otp\Registration\PhoneUniquenessAuditor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0-A5.7A — UNIQUE(phone_country, phone) after empty→NULL and duplicate preflight (D1/D2).
 *
 * Does not delete or merge historical duplicates. Fails closed if any literal
 * duplicate groups exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        DB::table('users')->where('phone', '')->update(['phone' => null]);

        $report = app(PhoneUniquenessAuditor::class)->audit();
        if ($report['blocks_unique_index']) {
            throw new RuntimeException(
                'Cannot add users_phone_country_phone_unique: '
                .$report['literal_duplicate_groups']
                .' duplicate group(s) involving '
                .$report['literal_users_in_dup_groups']
                .' user row(s). Run akubica:audit-phone-uniqueness and remediate D2 manually before retrying.'
            );
        }

        if ($this->hasPhoneUniqueIndex()) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['phone_country', 'phone'], 'users_phone_country_phone_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! $this->hasPhoneUniqueIndex()) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_phone_country_phone_unique');
        });
    }

    private function hasPhoneUniqueIndex(): bool
    {
        foreach (Schema::getIndexes('users') as $index) {
            if (($index['name'] ?? '') === 'users_phone_country_phone_unique') {
                return true;
            }
        }

        return false;
    }
};
