<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odessa_afiliated_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('odessa_identifier')->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odessa_afiliated_companies');
    }
};
