<?php

use App\Enums\Gender;
use App\Enums\Kinship;
use App\Models\Customer;
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
        Schema::create('family_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('paternal_lastname');
            $table->string('maternal_lastname');
            $table->date('birth_date')->nullable();
            $table->enum('gender', array_map(fn($case) => $case->value, Gender::cases()))->nullable();
            $table->enum('kinship', array_map(fn($case) => $case->value, Kinship::cases()));
            $table->foreignIdFor(Customer::class)->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_accounts');
    }
};
