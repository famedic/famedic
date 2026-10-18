<?php

use App\Services\Laboratory\GdaOrderConfirmation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('laboratory_purchases', 'gda_status')) {
            $this->assertImportedContract();
            // Laravel records this migration only after the complete, read-only validation succeeds.
            return;
        }
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            $table->string('gda_status', 32)->nullable()->index('laboratory_purchases_gda_status_idx');
        });
        // No historical backfill. The model supplies pending only for new purchases.
    }

    private function assertImportedContract(): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('gda_status_schema_incompatible: mysql_validation_required');
        }
        $database = $connection->getDatabaseName();
        $table = $connection->getTablePrefix().'laboratory_purchases';
        $column = $connection->selectOne(
            'SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS max_length,
                IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, EXTRA AS extra,
                GENERATION_EXPRESSION AS generation_expression, CHARACTER_SET_NAME AS charset,
                COLLATION_NAME AS collation_name
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, 'gda_status'],
        );
        $tableInfo = $connection->selectOne(
            'SELECT TABLE_COLLATION AS table_collation FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table],
        );
        if (! $column || $column->data_type !== 'varchar' || (int) $column->max_length !== 32
            || $column->is_nullable !== 'YES' || $column->column_default !== null
            || $column->extra !== '' || ($column->generation_expression ?? '') !== ''
            || $column->charset !== 'utf8mb4' || $column->collation_name !== ($tableInfo->table_collation ?? null)) {
            throw new RuntimeException('gda_status_schema_incompatible: column_contract');
        }
        $indexes = $connection->select('SHOW INDEX FROM '.$connection->getQueryGrammar()->wrapTable('laboratory_purchases'));
        $index = array_values(array_filter($indexes, fn ($row) => $row->Key_name === 'laboratory_purchases_gda_status_idx'));
        if (count($index) !== 1 || (int) $index[0]->Non_unique !== 1 || (int) $index[0]->Seq_in_index !== 1
            || $index[0]->Column_name !== 'gda_status' || $index[0]->Sub_part !== null
            || strtoupper($index[0]->Index_type) !== 'BTREE' || $index[0]->Collation !== 'A'
            || (isset($index[0]->Visible) && $index[0]->Visible !== 'YES')) {
            throw new RuntimeException('gda_status_schema_incompatible: index_contract');
        }
        // Binary comparison rejects unknown, case-folded or whitespace-padded values even under a CI collation.
        foreach ($indexes as $row) {
            if ($row->Column_name === 'gda_status' && (int) $row->Non_unique === 0) {
                throw new RuntimeException('gda_status_schema_incompatible: additional_unique_constraint');
            }
        }
        $checks = $connection->select(
            'SELECT c.CHECK_CLAUSE AS check_clause FROM information_schema.CHECK_CONSTRAINTS c
             JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME
             WHERE t.TABLE_SCHEMA = ? AND t.TABLE_NAME = ? AND t.CONSTRAINT_TYPE = ?',
            [$database, $table, 'CHECK'],
        );
        foreach ($checks as $check) {
            if (preg_match('/\bgda_status\b/i', $check->check_clause)) {
                throw new RuntimeException('gda_status_schema_incompatible: additional_check_constraint');
            }
        }
        if ($connection->selectOne('SELECT 1 AS present FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
            [$database, $table, 'gda_status'])) {
            throw new RuntimeException('gda_status_schema_incompatible: additional_foreign_key');
        }
        $status = $connection->getQueryGrammar()->wrap('gda_status');
        if ($connection->table('laboratory_purchases')->whereNotNull('gda_status')
            ->whereRaw('BINARY '.$status.' NOT IN (?, ?, ?, ?)', ['pending', 'confirmed', 'failed', 'uncertain'])->exists()) {
            throw new RuntimeException('gda_status_schema_incompatible: invalid_status_values');
        }
        if (! Schema::hasColumn('laboratory_purchases', 'gda_order_id') || ! Schema::hasColumn('laboratory_purchases', 'gda_consecutivo')) {
            throw new RuntimeException('gda_status_schema_incompatible: confirmation_evidence_columns');
        }
        // Includes soft-deleted purchases. Never infer confirmed status or repair historical evidence here.
        $connection->table('laboratory_purchases')->where('gda_status', 'confirmed')
            ->select(['id', 'gda_order_id', 'gda_consecutivo'])->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    if (! GdaOrderConfirmation::valid(['id' => $row->gda_order_id, 'infogda_consecutivo' => $row->gda_consecutivo])) {
                        throw new RuntimeException('gda_status_schema_incompatible: invalid_confirmed_evidence');
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('laboratory_purchases', 'gda_status')) {
            // A later rollback cannot prove ownership of imported schema or safely discard financial evidence.
            throw new RuntimeException('gda_status_rollback_blocked: preserve_historical_evidence');
        }
    }
};
