<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('laboratory_quotes', function (Blueprint $table) {
            $table->string('gda_code_http')->nullable()->after('gda_acuse');
            $table->string('gda_mensaje')->nullable()->after('gda_code_http');
            $table->text('gda_descripcion')->nullable()->after('gda_mensaje');
            $table->boolean('has_gda_warning')->default(false)->after('gda_descripcion');
            $table->text('gda_warning_message')->nullable()->after('has_gda_warning');
        });

        Schema::table('laboratory_quote_items', function (Blueprint $table) {
            $table->boolean('is_package')->default(false)->after('indications');
            $table->integer('feature_count')->default(0)->after('is_package');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_quotes', function (Blueprint $table) {
            $table->dropColumn([
                'gda_code_http',
                'gda_mensaje', 
                'gda_descripcion',
                'has_gda_warning',
                'gda_warning_message'
            ]);
        });

        Schema::table('laboratory_quote_items', function (Blueprint $table) {
            $table->dropColumn(['is_package', 'feature_count']);
        });
    }
};
