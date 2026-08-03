<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoice_requests', 'tax_profile_id')) {
            return;
        }

        Schema::table('invoice_requests', function (Blueprint $table) {
            $table->foreignId('tax_profile_id')
                ->nullable()
                ->after('id')
                ->constrained('tax_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoice_requests', 'tax_profile_id')) {
            return;
        }

        Schema::table('invoice_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_profile_id');
        });
    }
};
