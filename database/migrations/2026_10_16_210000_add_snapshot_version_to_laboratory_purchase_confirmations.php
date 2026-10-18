<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_purchase_confirmations', function (Blueprint $table) {
            $table->uuid('snapshot_version')->nullable();
        });
        // Preserve already frozen snapshots; never decrypt or regenerate their content.
        DB::table('laboratory_purchase_confirmations')->whereNotNull('payload')->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('laboratory_purchase_confirmations')->where('id', $row->id)
                        ->whereNull('snapshot_version')->update(['snapshot_version' => (string) Str::uuid()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('laboratory_purchase_confirmations', fn (Blueprint $table) => $table->dropColumn('snapshot_version'));
    }
};
