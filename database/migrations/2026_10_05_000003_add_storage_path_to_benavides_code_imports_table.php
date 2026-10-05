<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benavides_code_imports', function (Blueprint $table) {
            $table->string('stored_path')->nullable()->after('original_filename');
        });
    }

    public function down(): void
    {
        Schema::table('benavides_code_imports', function (Blueprint $table) {
            $table->dropColumn('stored_path');
        });
    }
};
