<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_profiles')) {
            return;
        }

        if (! Schema::hasColumn('tax_profiles', 'is_default')) {
            Schema::table('tax_profiles', function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->after('customer_id');
            });
        }

        if (Schema::hasColumn('tax_profiles', 'default_owner_key') && $this->isGeneratedColumn()) {
            if ($this->hasDefaultOwnerKeyUniqueIndex()) {
                Schema::table('tax_profiles', function (Blueprint $table): void {
                    $table->dropUnique('tax_profiles_default_owner_key_unique');
                });
            }

            Schema::table('tax_profiles', function (Blueprint $table): void {
                $table->dropColumn('default_owner_key');
            });
        }

        if (! Schema::hasColumn('tax_profiles', 'default_owner_key')) {
            Schema::table('tax_profiles', function (Blueprint $table): void {
                $table->unsignedBigInteger('default_owner_key')
                    ->nullable()
                    ->after('deleted_at');
            });
        }

        if (! $this->hasDefaultOwnerKeyUniqueIndex()) {
            Schema::table('tax_profiles', function (Blueprint $table): void {
                $table->unique('default_owner_key', 'tax_profiles_default_owner_key_unique');
            });
        }

        // Convert the legacy generated column before any backfill writes to it.
        // MySQL rejects direct updates to generated columns (error 3105).
        $this->backfillDefaults();

        $this->syncDefaultOwnerKeys();

    }

    public function down(): void
    {
        // This repair migration is intentionally non-destructive. The column
        // belongs to the baseline migration and must not be removed by rollback.
    }

    private function isMysql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }

    private function isGeneratedColumn(): bool
    {
        foreach (Schema::getColumns('tax_profiles') as $column) {
            if (($column['name'] ?? null) === 'default_owner_key') {
                return str_contains(strtoupper((string) ($column['extra'] ?? '')), 'GENERATED');
            }
        }

        return false;
    }

    private function hasDefaultOwnerKeyUniqueIndex(): bool
    {
        foreach (Schema::getIndexes('tax_profiles') as $index) {
            if (($index['name'] ?? '') === 'tax_profiles_default_owner_key_unique') {
                return true;
            }
        }

        return false;
    }

    public function backfillDefaults(): void
    {
        DB::table('tax_profiles')
            ->whereNull('deleted_at')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->orderBy('customer_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $profileId = DB::table('tax_profiles')
                        ->where('customer_id', $row->customer_id)
                        ->whereNull('deleted_at')
                        ->where('is_default', true)
                        ->orderByDesc('updated_at')
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->value('id');

                    if ($profileId === null) {
                        $profileId = DB::table('tax_profiles')
                            ->where('customer_id', $row->customer_id)
                            ->whereNull('deleted_at')
                            ->orderByDesc('created_at')
                            ->orderByDesc('id')
                            ->value('id');
                    }

                    DB::table('tax_profiles')
                        ->where('customer_id', $row->customer_id)
                        ->whereNull('deleted_at')
                        ->update(['is_default' => false]);

                    if ($profileId === null) {
                        continue;
                    }

                    DB::table('tax_profiles')
                        ->where('id', $profileId)
                        ->whereNull('deleted_at')
                        ->update(['is_default' => true]);
                }
            });

        DB::table('tax_profiles')
            ->whereNotNull('deleted_at')
            ->update(['is_default' => false]);

        $this->syncDefaultOwnerKeys();
    }

    private function syncDefaultOwnerKeys(): void
    {
        if (! $this->isMysql() || ! Schema::hasColumn('tax_profiles', 'default_owner_key')) {
            return;
        }

        DB::table('tax_profiles')->update(['default_owner_key' => null]);
        DB::table('tax_profiles')
            ->where('is_default', true)
            ->whereNull('deleted_at')
            ->update(['default_owner_key' => DB::raw('customer_id')]);
    }
};
