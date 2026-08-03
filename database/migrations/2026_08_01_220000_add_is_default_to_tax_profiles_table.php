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

        // 4) Columna generada + 5) UNIQUE (solo MySQL 8)
        if ($this->isMysql() && ! Schema::hasColumn('tax_profiles', 'default_owner_key')) {
            Schema::table('tax_profiles', function (Blueprint $table) {
                $table->unsignedBigInteger('default_owner_key')
                    ->nullable()
                    ->storedAs('CASE WHEN `is_default` = 1 AND `deleted_at` IS NULL THEN `customer_id` ELSE NULL END');
                $table->unique('default_owner_key', 'tax_profiles_default_owner_key_unique');
            });
        }
    }

    public function down(): void
    {
        // Índice primero, luego columna generada, luego is_default.
        // No restaura valores previos ni elimina perfiles/archivos.
        if ($this->isMysql() && Schema::hasColumn('tax_profiles', 'default_owner_key')) {
            Schema::table('tax_profiles', function (Blueprint $table) {
                $table->dropUnique('tax_profiles_default_owner_key_unique');
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

    /**
     * Un solo predeterminado activo por customer: el más reciente
     * (created_at DESC, id DESC). Pacientes sin perfiles activos quedan sin default.
     *
     * Solo perfiles con deleted_at IS NULL. No toca campos fiscales.
     * Itera customers en chunks (no carga toda tax_profiles en memoria).
     */
    private function backfillDefaults(): void
    {
        if (! Schema::hasColumn('tax_profiles', 'is_default')) {
            return;
        }

        // 2) Normalizar
        DB::table('tax_profiles')->update(['is_default' => false]);

        // 3) Asignar default por customer activo
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
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->value('id');

                    if ($profileId === null) {
                        continue;
                    }

                    DB::table('tax_profiles')
                        ->where('id', $profileId)
                        ->whereNull('deleted_at')
                        ->update(['is_default' => true]);
                }
            });
    }
};
