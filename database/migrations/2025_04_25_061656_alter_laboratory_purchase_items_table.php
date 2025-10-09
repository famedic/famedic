<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_purchase_items', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->json('feature_list')->nullable()->after('description');
            $table->text('indications')->nullable()->change();
        });
    }
};
