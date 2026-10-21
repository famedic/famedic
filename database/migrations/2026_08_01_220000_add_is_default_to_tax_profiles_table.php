<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Columna is_default
        Schema::table('tax_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_profiles', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('customer_id');
            }
        });

        // 2) Normalizar a false + 3) asignar un default por customer (antes de la garantía BD)
        $this->backfillDefaults();

        // 4) Columna ordinaria sincronizada por la aplicación + 5) UNIQUE.
        if ($this->isMysql() && ! Schema::hasColumn('tax_profiles', 'default_owner_key')) {
            Schema::table('tax_profiles', function (Blueprint $table) {
                $table->unsignedBigInteger('default_owner_key')
                    ->nullable()
                    ->after('deleted_at');
            });
        }

        if ($this->isMysql() && ! $this->hasDefaultOwnerKeyUniqueIndex()) {
            Schema::table('tax_profiles', function (Blueprint $table) {
                $table->unique('default_owner_key', 'tax_profiles_default_owner_key_unique');
            });
        }

        $this->syncDefaultOwnerKeys();
    }

    public function down(): void
    {
        // Índice primero, luego columna generada, luego is_default.
        // No restaura valores previos ni elimina perfiles/archivos.
        if ($this->isMysql() && Schema::hasColumn('tax_profiles', 'default_owner_key')) {
            Schema::table('tax_profiles', function (Blueprint $table) {
                if ($this->hasDefaultOwnerKeyUniqueIndex()) {
                    $table->dropUnique('tax_profiles_default_owner_key_unique');
                }
                $table->dropColumn('default_owner_key');
            });
        }

        if (Schema::hasColumn('tax_profiles', 'is_default')) {
            Schema::table('tax_profiles', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }

    private function isMysql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
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

    /**
     * Un solo predeterminado activo por customer: el más reciente
     * (created_at DESC, id DESC). Pacientes sin perfiles activos quedan sin default.
     *
     * Solo perfiles con deleted_at IS NULL. No toca campos fiscales.
     * Itera customers en chunks (no carga toda tax_profiles en memoria).
     */
    public function backfillDefaults(): void
    {
        if (! Schema::hasColumn('tax_profiles', 'is_default')) {
            return;
        }

        // 2) Elegir un único default por customer activo.
        DB::table('tax_profiles')
            ->whereNull('deleted_at')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->orderBy('customer_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $customerId = $row->customer_id;

                    $profileId = DB::table('tax_profiles')
                        ->where('customer_id', $customerId)
                        ->whereNull('deleted_at')
                        ->where('is_default', true)
                        ->orderByDesc('updated_at')
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->value('id');

                    // Preserve a manually selected active default. Only choose
                    // the newest active profile when no default exists.
                    if ($profileId === null) {
                        $profileId = DB::table('tax_profiles')
                            ->where('customer_id', $customerId)
                            ->whereNull('deleted_at')
                            ->orderByDesc('created_at')
                            ->orderByDesc('id')
                            ->value('id');
                    }

                    DB::table('tax_profiles')
                        ->where('customer_id', $customerId)
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
