<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atix_phone_lookup_operations')) {
            return;
        }

        $schema = DB::getDatabaseName();
        $hasEventUnique = DB::table('information_schema.statistics')
            ->where('table_schema', $schema)->where('table_name', 'atix_phone_lookup_operations')
            ->where('index_name', 'atix_phone_lookup_event_unique')->exists();
        $hasIdempotencyUnique = DB::table('information_schema.statistics')
            ->where('table_schema', $schema)->where('table_name', 'atix_phone_lookup_operations')
            ->where('index_name', 'atix_phone_lookup_idempotency_unique')->exists();

        if ($hasEventUnique || $hasIdempotencyUnique) {
            Schema::table('atix_phone_lookup_operations', function (Blueprint $table) use ($hasEventUnique, $hasIdempotencyUnique): void {
                if ($hasEventUnique) $table->dropUnique('atix_phone_lookup_event_unique');
                if ($hasIdempotencyUnique) $table->dropUnique('atix_phone_lookup_idempotency_unique');
            });
        }

        $hasEventIndex = DB::table('information_schema.statistics')
            ->where('table_schema', $schema)->where('table_name', 'atix_phone_lookup_operations')
            ->where('index_name', 'atix_phone_lookup_event_index')->exists();
        $hasIdempotencyIndex = DB::table('information_schema.statistics')
            ->where('table_schema', $schema)->where('table_name', 'atix_phone_lookup_operations')
            ->where('index_name', 'atix_phone_lookup_idempotency_index')->exists();
        if (! $hasEventIndex || ! $hasIdempotencyIndex) {
            Schema::table('atix_phone_lookup_operations', function (Blueprint $table): void {
                if (! DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', 'atix_phone_lookup_operations')->where('index_name', 'atix_phone_lookup_event_index')->exists()) {
                    $table->index(['integration_key_hash', 'channel_event_hash'], 'atix_phone_lookup_event_index');
                }
                if (! DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', 'atix_phone_lookup_operations')->where('index_name', 'atix_phone_lookup_idempotency_index')->exists()) {
                    $table->index(['integration_key_hash', 'idempotency_key_hash'], 'atix_phone_lookup_idempotency_index');
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }
};
