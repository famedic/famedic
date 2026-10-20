<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laboratory_result_extraction_qa_metrics')) {
            return;
        }

        Schema::table('laboratory_result_extraction_qa_metrics', function (Blueprint $table) {
            if (! $this->foreignKeyExists('laboratory_result_extraction_qa_metrics', 'lab_res_qa_metrics_version_fk')) {
                $table->foreign('laboratory_result_version_id', 'lab_res_qa_metrics_version_fk')
                    ->references('id')
                    ->on('laboratory_result_versions')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('laboratory_result_extraction_qa_metrics')) {
            return;
        }

        Schema::table('laboratory_result_extraction_qa_metrics', function (Blueprint $table) {
            if ($this->foreignKeyExists('laboratory_result_extraction_qa_metrics', 'lab_res_qa_metrics_version_fk')) {
                $table->dropForeign('lab_res_qa_metrics_version_fk');
            }
        });
    }

    private function foreignKeyExists(string $table, string $foreignName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$database, $table, $foreignName, 'FOREIGN KEY']
        );

        return $result !== [];
    }
};
