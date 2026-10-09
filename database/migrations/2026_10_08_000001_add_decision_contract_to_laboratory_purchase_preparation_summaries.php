<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_purchase_preparation_summaries', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_purchase_preparation_summaries', 'decision_status')) {
                $table->string('decision_status', 40)->nullable();
            }

            if (! Schema::hasColumn('laboratory_purchase_preparation_summaries', 'rules_version')) {
                $table->string('rules_version', 80)->nullable();
            }

            if (! Schema::hasColumn('laboratory_purchase_preparation_summaries', 'rules_applied')) {
                $table->json('rules_applied')->nullable();
            }

            if (! Schema::hasColumn('laboratory_purchase_preparation_summaries', 'fallback_reason')) {
                $table->string('fallback_reason', 160)->nullable();
            }

            if (! Schema::hasColumn('laboratory_purchase_preparation_summaries', 'fallback_category')) {
                $table->string('fallback_category', 80)->nullable();
            }

            if (! Schema::hasColumn('laboratory_purchase_preparation_summaries', 'needs_provider_review')) {
                $table->boolean('needs_provider_review')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_purchase_preparation_summaries', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('laboratory_purchase_preparation_summaries', 'decision_status') ? 'decision_status' : null,
                Schema::hasColumn('laboratory_purchase_preparation_summaries', 'rules_version') ? 'rules_version' : null,
                Schema::hasColumn('laboratory_purchase_preparation_summaries', 'rules_applied') ? 'rules_applied' : null,
                Schema::hasColumn('laboratory_purchase_preparation_summaries', 'fallback_reason') ? 'fallback_reason' : null,
                Schema::hasColumn('laboratory_purchase_preparation_summaries', 'fallback_category') ? 'fallback_category' : null,
                Schema::hasColumn('laboratory_purchase_preparation_summaries', 'needs_provider_review') ? 'needs_provider_review' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
